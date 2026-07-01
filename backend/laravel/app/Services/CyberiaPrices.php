<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * USD pricing for Cyberia tokens, derived purely from the DEX pool graph.
 *
 * Cyberia has no price oracle, so prices are walked outward from the USD anchor
 * tokens (USDC/USDT, pegged at $1) through the constant-product pools: each pool
 * gives an exchange rate between its two tokens, and prices propagate one pool
 * at a time. Shared by the analytics dashboard and the per-token pages so both
 * report the same number.
 */
class CyberiaPrices
{
    /**
     * Fallbacks used verbatim when the matching config/services.cyberia.* keys
     * are absent — e.g. a cached config that predates them — so a stale config
     * cache can't quietly disable pricing.
     */
    private const DEFAULT_USD_ANCHORS = '0xdc25597B19799010047F17e9591EFE08EFd40077,0x94845aF24a3E431593A2b941b2b31836dE45185D';

    private const DEFAULT_PRICE_MIN_POOL_USD = 0.01;

    /** Symbols always treated as $1 anchors, regardless of address config. */
    private const STABLE_SYMBOLS = ['USDC', 'USDT'];

    /**
     * USD price of every token reachable from a $1 anchor through the DEX pool
     * graph.
     *
     * A price is only as trustworthy as the shallowest pool in its chain back
     * to an anchor, so each token keeps the route that maximises that
     * bottleneck (widest-path relaxation) and pools whose already-priced side
     * holds less than the depth floor are skipped — that floor is what rejects
     * the 1-wei dust pools that would otherwise produce absurd ratios.
     *
     * @param  Collection<int, object>  $pools
     * @return array<string, float> lowercased token address => USD price (anchors included at 1.0)
     */
    public function priceFromPools(Collection $pools): array
    {
        $floor = (float) config('services.cyberia.price_min_pool_usd');
        if ($floor <= 0) {
            $floor = self::DEFAULT_PRICE_MIN_POOL_USD;
        }

        // Each pool becomes two directed edges (price flows either way).
        $edges = [];
        foreach ($pools as $pool) {
            $r0 = (float) $pool->reserve0;
            $r1 = (float) $pool->reserve1;
            if ($r0 <= 0 || $r1 <= 0) {
                continue;
            }
            $t0 = strtolower((string) $pool->token0);
            $t1 = strtolower((string) $pool->token1);
            $edges[] = [$t0, $r0, $t1, $r1];
            $edges[] = [$t1, $r1, $t0, $r0];
        }

        $price = [];
        $confidence = [];
        foreach ($this->usdAnchors() as $anchor) {
            $price[$anchor] = 1.0;
            $confidence[$anchor] = INF;
        }
        // Also anchor by symbol: if the bot labelled a pool token USDC/USDT we
        // peg it at $1 even when its address isn't in the configured list, so an
        // address mismatch between the bot and this app can't unprice the walk.
        foreach ($pools as $pool) {
            foreach ([[$pool->token0, $pool->symbol0], [$pool->token1, $pool->symbol1]] as [$addr, $sym]) {
                if (in_array(strtoupper((string) $sym), self::STABLE_SYMBOLS, true)) {
                    $addr = strtolower((string) $addr);
                    $price[$addr] = 1.0;
                    $confidence[$addr] = INF;
                }
            }
        }

        if ($edges === [] || $price === []) {
            return [];
        }

        // Bellman-Ford-style: prices settle within (node count) passes; the
        // early break stops as soon as a pass improves nothing.
        $maxPasses = count($edges) + 1;
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $changed = false;
            foreach ($edges as [$from, $rFrom, $to, $rTo]) {
                if (! isset($price[$from])) {
                    continue;
                }
                $sideUsd = $rFrom * $price[$from];
                if ($sideUsd < $floor) {
                    continue;
                }
                $candidate = min($confidence[$from], $sideUsd);
                if ($candidate > ($confidence[$to] ?? 0.0)) {
                    $confidence[$to] = $candidate;
                    $price[$to] = $price[$from] * $rFrom / $rTo;
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }

        return $price;
    }

    /**
     * Map of lowercased token address => symbol, from the pool rows.
     *
     * @param  Collection<int, object>  $pools
     * @return array<string, string>
     */
    public function poolSymbols(Collection $pools): array
    {
        $symbols = [];
        foreach ($pools as $pool) {
            $symbols[strtolower($pool->token0)] = $pool->symbol0;
            $symbols[strtolower($pool->token1)] = $pool->symbol1;
        }

        return $symbols;
    }

    /**
     * TVL of one pool from the price map. Both sides of a constant-product pool
     * are equal in value, so a single priced side is doubled (mirrors the bot's
     * snapshot). Null when neither token can be priced.
     *
     * @param  array<string, float>  $priceMap
     */
    public function poolTvl(object $pool, array $priceMap): ?float
    {
        $p0 = $priceMap[strtolower($pool->token0)] ?? null;
        $p1 = $priceMap[strtolower($pool->token1)] ?? null;

        if ($p0 !== null && $p1 !== null) {
            return (float) $pool->reserve0 * $p0 + (float) $pool->reserve1 * $p1;
        }
        if ($p0 !== null) {
            return (float) $pool->reserve0 * $p0 * 2;
        }
        if ($p1 !== null) {
            return (float) $pool->reserve1 * $p1 * 2;
        }

        return null;
    }

    /**
     * USD anchor token addresses (lowercased), the $1-pegged roots of the walk.
     *
     * @return list<string>
     */
    public function usdAnchors(): array
    {
        $configured = (string) config('services.cyberia.usd_anchors');

        return collect(explode(',', $configured !== '' ? $configured : self::DEFAULT_USD_ANCHORS))
            ->map(fn (string $a): string => strtolower(trim($a)))
            ->filter()
            ->values()
            ->all();
    }
}
