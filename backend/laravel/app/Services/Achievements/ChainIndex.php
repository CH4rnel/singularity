<?php

namespace App\Services\Achievements;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What an address has actually done on Cyberia, read from the explorer's index.
 *
 * Achievements used to be detected from `activity_events` — the tail of the
 * Telegram bot's announcer feed. That feed is **forward-only**: each announcer
 * bootstraps its cursor to the head of the chain the first time it runs, so
 * nothing anyone did before that instant is in it. On prod the earliest row is
 * 2026-08-05, while the operator's 27 lending transactions start 2026-05-21 —
 * which is exactly why the "Lender" badge was unearnable for the person who
 * had used the market most.
 *
 * A badge is permanent and publicly verifiable, so its detector has to see all
 * of history or say that it cannot. Blockscout already indexes every
 * transaction on this chain and answers without a key, so that is the source;
 * `activity_events` stays as a fast path for anything recent.
 *
 * Three rules hold everywhere in this class:
 *
 *  - **`null` means "unreadable", never "no".** A down explorer must not
 *    silently deny a badge somebody earned, so callers treat null as "ask
 *    again later" rather than as a negative.
 *  - **A truncated scan that found nothing is `null` too.** We page from the
 *    oldest transaction forward, because the old history is the part the feed
 *    could never see; an address busy enough to exceed the page budget without
 *    a match has not been ruled out, it has only been unfinished.
 *  - **Selectors are matched, not just addresses.** The router is the same
 *    contract for a swap and for adding liquidity, so only the first four
 *    bytes of the call data tell them apart.
 */
class ChainIndex
{
    /** Transactions per explorer page. */
    private const PAGE_SIZE = 100;

    /** Page budget per address, so one very busy account cannot hang a sweep. */
    private const MAX_PAGES = 50;

    /**
     * Every cToken entry point that means "this person used the market":
     * mint / mint() / redeem / redeemUnderlying / borrow / repayBorrow /
     * repayBorrowBehalf / liquidateBorrow.
     */
    public const LENDING_SELECTORS = [
        '0xa0712d68', '0x1249c58b', '0xdb006a75', '0x852a12e3',
        '0xc5ebeaec', '0x0e752702', '0x2608f818', '0xf5e3c462',
    ];

    /** Comptroller: enterMarkets. */
    public const ENTER_MARKETS_SELECTOR = '0xc2998238';

    /** Router: addLiquidity / addLiquidityETH. */
    public const ADD_LIQUIDITY_SELECTORS = ['0xe8e33700', '0xf305d719'];

    /**
     * Every contract this address has sent a transaction to, mapped to the
     * selectors it called there. Null when the history could not be read in
     * full.
     *
     * @return array<string, array<int, string>>|null
     */
    public function callsBy(string $address): ?array
    {
        $address = mb_strtolower(trim($address));

        if (! preg_match('/^0x[0-9a-f]{40}$/', $address)) {
            return null;
        }

        return Cache::remember(
            'achievements:calls:'.$address,
            now()->addMinutes(15),
            fn () => $this->fetchCalls($address),
        );
    }

    /**
     * Has this address ever supplied, withdrawn, borrowed or repaid in any
     * listed lending market — or entered one?
     */
    public function usedLending(string $address): ?bool
    {
        $markets = $this->lendingMarkets();

        if ($markets === null) {
            return null;
        }

        $comptroller = $this->normalise(config('cyber.contracts.lending_comptroller'));

        $targets = [];

        foreach ($markets as $market) {
            $targets[$market] = self::LENDING_SELECTORS;
        }

        if ($comptroller !== null) {
            $targets[$comptroller] = [self::ENTER_MARKETS_SELECTOR];
        }

        return $this->matched($address, $targets);
    }

    /** Has this address ever added liquidity through the router? */
    public function addedLiquidity(string $address): ?bool
    {
        $router = $this->normalise(config('cyber.contracts.dex_router'));

        return $router === null
            ? null
            : $this->matched($address, [$router => self::ADD_LIQUIDITY_SELECTORS]);
    }

    /**
     * Has this address ever turned bridged CYBER.sol into the native coin?
     *
     * Any call to the swap contract counts: it does one thing, so the selector
     * adds nothing a reader would trust more.
     */
    public function convertedCyberSol(string $address): ?bool
    {
        $swap = $this->normalise(config('cyber.contracts.cyber_sol_swap'));

        return $swap === null ? null : $this->matched($address, [$swap => []]);
    }

    /**
     * The markets the comptroller currently lists. Cached for hours — the set
     * changes when somebody deploys a market, not while a sweep is running.
     *
     * @return array<int, string>|null
     */
    public function lendingMarkets(): ?array
    {
        $comptroller = $this->normalise(config('cyber.contracts.lending_comptroller'));

        if ($comptroller === null) {
            return null;
        }

        return Cache::remember('achievements:lending-markets', now()->addHours(6), function () use ($comptroller) {
            // getAllMarkets() -> address[]
            $result = $this->ethCall($comptroller, '0xb0772d0b');

            if ($result === null) {
                return null;
            }

            $body = substr($result, 2);
            $count = (int) hexdec(substr($body, 64, 64) ?: '0');

            if ($count <= 0 || mb_strlen($body) < 128 + $count * 64) {
                return null;
            }

            $markets = [];

            for ($i = 0; $i < $count; $i++) {
                $markets[] = '0x'.substr($body, 128 + $i * 64 + 24, 40);
            }

            return $markets;
        });
    }

    /* ------------------------------------------------------------ internals -- */

    /**
     * @param  array<string, array<int, string>>  $targets  contract => selectors ([] = any call)
     */
    private function matched(string $address, array $targets): ?bool
    {
        $calls = $this->callsBy($address);

        if ($calls === null) {
            return null;
        }

        foreach ($targets as $contract => $selectors) {
            $seen = $calls[$contract] ?? null;

            if ($seen === null) {
                continue;
            }

            if ($selectors === [] || array_intersect($selectors, $seen) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<int, string>>|null
     */
    private function fetchCalls(string $address): ?array
    {
        $base = rtrim((string) config('cyber.chain.explorer', ''), '/');

        if ($base === '') {
            return null;
        }

        $calls = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $response = Http::timeout(20)->get($base.'/api', [
                    'module' => 'account',
                    'action' => 'txlist',
                    'address' => $address,
                    'page' => $page,
                    'offset' => self::PAGE_SIZE,
                    // Oldest first: the history the announcer feed could never
                    // see is precisely the old end, so a budget that runs out
                    // should run out at the recent end, which the feed covers.
                    'sort' => 'asc',
                ]);

                if ($response->failed()) {
                    return null;
                }

                $rows = $response->json('result');
            } catch (\Throwable $e) {
                Log::warning('Achievement chain index unreadable', [
                    'address' => $address,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            // Blockscout answers an empty history with a string, not a list.
            if (! is_array($rows)) {
                return $calls;
            }

            foreach ($rows as $row) {
                $to = mb_strtolower((string) ($row['to'] ?? ''));

                if ($to === '') {
                    continue;
                }

                $selector = mb_strtolower(mb_substr((string) ($row['input'] ?? ''), 0, 10));

                if (! isset($calls[$to])) {
                    $calls[$to] = [];
                }

                if ($selector !== '' && ! in_array($selector, $calls[$to], true)) {
                    $calls[$to][] = $selector;
                }
            }

            if (count($rows) < self::PAGE_SIZE) {
                return $calls;
            }
        }

        // Budget exhausted: what we hold is a prefix of the history, so a
        // "no" drawn from it would not be a no. Say we do not know.
        Log::info('Achievement chain index truncated', ['address' => $address]);

        return null;
    }

    private function ethCall(string $to, string $data): ?string
    {
        $rpc = (string) config('cyber.chain.rpc', 'https://rpc.cyberia.church');

        try {
            $response = Http::timeout(15)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_call',
                'params' => [['to' => $to, 'data' => $data], 'latest'],
            ]);

            $result = $response->json('result');

            return is_string($result) && str_starts_with($result, '0x') ? $result : null;
        } catch (\Throwable $e) {
            Log::warning('Achievement chain read failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function normalise(mixed $address): ?string
    {
        $address = mb_strtolower(trim((string) $address));

        return preg_match('/^0x[0-9a-f]{40}$/', $address) === 1 ? $address : null;
    }
}
