<?php

namespace App\Services\Bitcoin;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reading Bitcoin and Litecoin without running either of them.
 *
 * The Monero corridor exists only because this server holds a wallet; these
 * two are the opposite case, and it is worth being explicit about why. An
 * ordinary address on these chains is public: Esplora — the index behind
 * mempool.space and litecoinspace.org — will tell anyone what is unspent on
 * it, for no key and no account, over four endpoints that are identical on
 * both chains. So the bridge watches deposits by asking, and holds keys only
 * for the half that has to sign.
 *
 * The rules are this file's whole substance:
 *
 *   - **null is not zero.** An index that times out, rate-limits or answers
 *     nonsense must never read as an empty address (which would close a
 *     deposit window over somebody's coins) or as an empty pool (which would
 *     refuse every payout as "insufficient"). Every method answers null for
 *     "could not tell" and only a real, readable nothing is zero.
 *   - **satoshis as decimal strings.** Eight decimals fit in an int, but the
 *     bridge's arithmetic is bcmath end to end and a float in this path is a
 *     rounding error in a balance.
 *   - **confirmations are counted, not assumed.** `/address/:a` would give a
 *     balance in one call, but it cannot say how deep each output is; a
 *     deposit two blocks old is not creditable on a chain where six is the
 *     rule, so the outputs are listed and measured against the tip.
 */
final class EsploraApiService
{
    /** The tip moves every ~10 minutes on BTC and ~2.5 on LTC; this is cheap. */
    private const TIP_CACHE_SECONDS = 30;

    public function supports(string $chainKey): bool
    {
        return $this->baseUrl($chainKey) !== '';
    }

    /**
     * What is sitting on one address, split by whether it is deep enough.
     *
     * `pending` is everything seen but not yet at `minConfirmations`, mempool
     * included, so a page can say "your deposit is in, it needs four more
     * blocks" instead of "nothing arrived" — the difference between a person
     * waiting and a person writing to support.
     *
     * @return array{confirmed: string, pending: string}|null
     */
    public function addressBalances(string $chainKey, string $address, int $minConfirmations): ?array
    {
        $utxos = $this->unspent($chainKey, $address);

        if ($utxos === null) {
            return null;
        }

        $tip = $this->tipHeight($chainKey);

        if ($tip === null) {
            return null;
        }

        $confirmed = '0';
        $pending = '0';

        foreach ($utxos as $utxo) {
            $value = (string) (int) ($utxo['value'] ?? 0);

            if (bccomp($value, '0', 0) <= 0) {
                continue;
            }

            if ($this->confirmations($utxo, $tip) >= max(1, $minConfirmations)) {
                $confirmed = bcadd($confirmed, $value, 0);

                continue;
            }

            $pending = bcadd($pending, $value, 0);
        }

        return ['confirmed' => $confirmed, 'pending' => $pending];
    }

    /**
     * Everything the relay could actually spend across a set of addresses.
     *
     * Deliberately all-or-nothing: if one address cannot be read, the total is
     * null rather than a smaller number, because a pool reported short is a
     * corridor that refuses transfers it could have made — and a pool reported
     * long is one that accepts transfers it cannot.
     *
     * @param  array<int, string>  $addresses
     */
    public function spendableBalance(string $chainKey, array $addresses): ?string
    {
        $tip = $this->tipHeight($chainKey);

        if ($tip === null) {
            return null;
        }

        $total = '0';

        foreach (array_unique(array_filter($addresses)) as $address) {
            $utxos = $this->unspent($chainKey, (string) $address);

            if ($utxos === null) {
                return null;
            }

            foreach ($utxos as $utxo) {
                // Unconfirmed change is not spendable: the relay refuses to
                // build on an output that is not in a block, so counting it
                // here would advertise capacity the payout would then decline.
                if ($this->confirmations($utxo, $tip) < 1) {
                    continue;
                }

                $total = bcadd($total, (string) (int) ($utxo['value'] ?? 0), 0);
            }
        }

        return $total;
    }

    /**
     * Is this payout on the chain (or in the mempool)?
     *
     * true means it exists, null means the index could not answer. There is no
     * false: a transaction that was broadcast and is not visible may still be
     * in flight, and reading that as "it failed" is how a bridge pays twice.
     */
    public function transactionExists(string $chainKey, string $txid): ?bool
    {
        $base = $this->baseUrl($chainKey);

        if ($base === '' || ! preg_match('/^[0-9a-fA-F]{64}$/', $txid)) {
            return null;
        }

        $response = $this->get($chainKey, "/tx/{$txid}");

        if ($response === null) {
            return null;
        }

        return $response->successful() ? true : null;
    }

    /** Current chain tip, or null when the index cannot be reached. */
    public function tipHeight(string $chainKey): ?int
    {
        $base = $this->baseUrl($chainKey);

        if ($base === '') {
            return null;
        }

        $cached = Cache::get($this->tipCacheKey($chainKey, $base));

        if (is_int($cached)) {
            return $cached;
        }

        $response = $this->get($chainKey, '/blocks/tip/height');

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $height = (int) trim($response->body());

        if ($height <= 0) {
            return null;
        }

        Cache::put($this->tipCacheKey($chainKey, $base), $height, self::TIP_CACHE_SECONDS);

        return $height;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function unspent(string $chainKey, string $address): ?array
    {
        if ($address === '') {
            return null;
        }

        $response = $this->get($chainKey, '/address/'.rawurlencode($address).'/utxo');

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $body = $response->json();

        // An address with nothing on it answers `[]`; anything that is not a
        // list is the index having a bad day, which is not the same fact.
        return is_array($body) ? array_values(array_filter($body, 'is_array')) : null;
    }

    /**
     * @param  array<string, mixed>  $utxo
     */
    private function confirmations(array $utxo, int $tip): int
    {
        $status = (array) ($utxo['status'] ?? []);

        if (($status['confirmed'] ?? false) !== true) {
            return 0;
        }

        $height = (int) ($status['block_height'] ?? 0);

        if ($height <= 0 || $height > $tip) {
            return 0;
        }

        return $tip - $height + 1;
    }

    private function get(string $chainKey, string $path): ?Response
    {
        $base = $this->baseUrl($chainKey);

        if ($base === '') {
            return null;
        }

        try {
            return Http::acceptJson()
                ->timeout((int) config('bridge.chains.'.$chainKey.'.esplora_timeout', 15))
                ->retry(2, 500, throw: false)
                ->get($base.$path);
        } catch (\Throwable $e) {
            Log::warning('Esplora: unreachable', [
                'chain' => $chainKey,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function baseUrl(string $chainKey): string
    {
        return rtrim((string) config("bridge.chains.{$chainKey}.esplora_url", ''), '/');
    }

    private function tipCacheKey(string $chainKey, string $base): string
    {
        return 'esplora.tip.'.$chainKey.'.'.sha1($base);
    }
}
