<?php

namespace App\Services\Analytics;

use App\Services\SolanaRpcProxy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Has this wallet actually received anything?"
 *
 * The browser can see its own balance, which makes it the fastest way to
 * notice funding and the worst way to record it: a balance that ticks up and
 * down would re-fire the milestone on every refresh, and a claim nobody
 * checked is a claim anybody can make. So the wallet reports a candidate and
 * this class confirms it against a chain — once, for the chains this server
 * can read without an API key.
 *
 * That list is short on purpose (`config/analytics.php`). Reading a balance
 * needs an address, and an address is the one piece of data that ties an
 * anonymous installation to a person's on-chain identity — so it is only ever
 * asked for, stored and read where the answer buys something we cannot get
 * another way. On BNB, Base and the Bitcoin family, where reading would need a
 * key we do not ship, no address is stored at all and the client's claim is
 * recorded as a claim.
 *
 * A native balance is not the whole question: a wallet holding USDC and no
 * CYBER is funded, and is in fact the exact user the gas station exists for.
 * So EVM chains are asked twice — the node for the coin, the keyless index for
 * everything else.
 */
class FundingVerifier
{
    public function __construct(private SolanaRpcProxy $solana) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function chains(): array
    {
        return (array) config('analytics.verifiable_chains', []);
    }

    public function isVerifiable(string $chain): bool
    {
        return array_key_exists($chain, $this->chains());
    }

    /**
     * The stored form of an address, or null when it is not one.
     *
     * Rejecting here rather than at the edge means nothing malformed is ever
     * written: the table holds addresses this server intends to read, and a
     * row it can never read is only a linkage with no purpose.
     */
    public function normalize(string $chain, string $address): ?string
    {
        $config = $this->chains()[$chain] ?? null;
        $trimmed = trim($address);

        if ($config === null) {
            return null;
        }

        if (($config['type'] ?? '') === 'evm') {
            return preg_match('/^0x[a-fA-F0-9]{40}$/', $trimmed) === 1 ? Str::lower($trimmed) : null;
        }

        // Base58, and the length Solana's 32-byte keys land in.
        return preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * Whether this address holds anything at all right now.
     *
     * Cached briefly and per address, because the answer is asked repeatedly
     * about wallets that are still empty — the sweep re-checks every unfunded
     * user it saw recently — and changes at most once in a wallet's life.
     */
    public function hasBalance(string $chain, string $address): bool
    {
        $normalized = $this->normalize($chain, $address);

        if ($normalized === null) {
            return false;
        }

        $key = "analytics.funded:{$chain}:{$normalized}";
        $cached = Cache::get($key);

        if (is_bool($cached)) {
            return $cached;
        }

        $config = $this->chains()[$chain];

        $funded = ($config['type'] ?? '') === 'evm'
            ? $this->evmFunded($config, $normalized)
            : $this->solanaFunded($normalized);

        // Only a positive answer is cached. "Not yet" is the answer that is
        // about to change, and caching it would delay the one moment this
        // whole class exists to catch.
        if ($funded) {
            Cache::put($key, true, now()->addMinutes((int) config('analytics.funding_cache_minutes', 10)));
        }

        return $funded;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function evmFunded(array $config, string $address): bool
    {
        if ($this->nativeBalanceAboveZero((string) ($config['rpc'] ?? ''), $address)) {
            return true;
        }

        return $this->holdsTokens((string) ($config['explorer_api'] ?? ''), $address);
    }

    private function nativeBalanceAboveZero(string $rpc, string $address): bool
    {
        if ($rpc === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_getBalance',
                'params' => [$address, 'latest'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Analytics funding read failed', ['error' => $e->getMessage()]);

            return false;
        }

        $result = $response->json('result');

        return is_string($result)
            && preg_match('/^0x[0-9a-fA-F]*$/', $result) === 1
            && ltrim(substr($result, 2), '0') !== '';
    }

    /**
     * The same keyless Blockscout call the gas station uses to decide whether
     * an address owns anything here — one request that answers for every token
     * and NFT at once.
     */
    private function holdsTokens(string $api, string $address): bool
    {
        if ($api === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)->get($api, [
                'module' => 'account',
                'action' => 'tokenlist',
                'address' => $address,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Analytics token read failed', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $result = $response->json('result');

        if (! is_array($result)) {
            return false;
        }

        foreach ($result as $token) {
            $balance = is_array($token) ? (string) ($token['balance'] ?? '0') : '0';

            if (preg_match('/^\d+$/', $balance) === 1 && bccomp($balance, '0') > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Solana through this app's own relay — the same path the browser uses,
     * for the same reason: the public cluster answers this server and refuses
     * a browser. SPL holdings count as much as lamports do, so both are asked.
     */
    private function solanaFunded(string $address): bool
    {
        if (! $this->solana->enabled()) {
            return false;
        }

        try {
            $balance = $this->solana->forward([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getBalance',
                'params' => [$address],
            ]);

            if ((int) ($balance['result']['value'] ?? 0) > 0) {
                return true;
            }

            $tokens = $this->solana->forward([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTokenAccountsByOwner',
                'params' => [
                    $address,
                    ['programId' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA'],
                    ['encoding' => 'jsonParsed'],
                ],
            ]);

            foreach ((array) ($tokens['result']['value'] ?? []) as $account) {
                $amount = $account['account']['data']['parsed']['info']['tokenAmount']['amount'] ?? '0';

                if (is_string($amount) && preg_match('/^\d+$/', $amount) === 1 && bccomp($amount, '0') > 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Analytics Solana funding read failed', ['error' => $e->getMessage()]);
        }

        return false;
    }
}
