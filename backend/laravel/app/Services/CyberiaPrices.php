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
     * Last walk, keyed by the pool collection it was run over. A token page
     * asks for the prices and then for one token's route; both are the same
     * relaxation, and running it twice per request would be pure waste.
     *
     * @var array{0: int, 1: array{price: array<string, float>, via: array<string, array{pair: string, token0: string, from: string}>}}|null
     */
    private ?array $memo = null;

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
        return $this->walk($pools)['price'];
    }

    /**
     * The pools the price walk actually used to price one token, ordered from
     * that token outward to the $1 anchor it settled on.
     *
     * The chart on a token page needs the same route the price came from: a
     * token's direct pool against a stablecoin is often not the pool its price
     * is read through (thin pools are skipped by the depth floor), and a chart
     * drawn on a different route than the headline price would contradict the
     * number printed above it.
     *
     * Null when the token is itself an anchor, or when nothing priced it.
     *
     * @param  Collection<int, object>  $pools
     * @return list<array{pair: string, token0: string, tokenIn: string, tokenOut: string}>|null
     */
    public function usdRoute(Collection $pools, string $token): ?array
    {
        $via = $this->walk($pools)['via'];
        $current = strtolower($token);
        $hops = [];

        // Bounded by the node count: a widest-path predecessor chain cannot
        // cycle, but a malformed map must not spin here either.
        for ($step = 0; $step <= count($via); $step++) {
            $edge = $via[$current] ?? null;
            if ($edge === null) {
                break;
            }
            $hops[] = [
                'pair' => $edge['pair'],
                'token0' => $edge['token0'],
                'tokenIn' => $current,
                'tokenOut' => $edge['from'],
            ];
            $current = $edge['from'];
        }

        return $hops === [] ? null : $hops;
    }

    /**
     * One pass of the widest-path relaxation, keeping both what each token is
     * worth and which pool taught it that.
     *
     * @param  Collection<int, object>  $pools
     * @return array{price: array<string, float>, via: array<string, array{pair: string, token0: string, from: string}>}
     */
    private function walk(Collection $pools): array
    {
        if ($this->memo !== null && $this->memo[0] === spl_object_id($pools)) {
            return $this->memo[1];
        }

        $floor = (float) config('services.cyberia.price_min_pool_usd');
        if ($floor <= 0) {
            $floor = self::DEFAULT_PRICE_MIN_POOL_USD;
        }

        // Each pool becomes two directed edges (price flows either way), each
        // carrying the pool it came from so the winning chain stays readable.
        $edges = [];
        foreach ($pools as $pool) {
            $r0 = (float) $pool->reserve0;
            $r1 = (float) $pool->reserve1;
            if ($r0 <= 0 || $r1 <= 0) {
                continue;
            }
            $t0 = strtolower((string) $pool->token0);
            $t1 = strtolower((string) $pool->token1);
            $pair = (string) $pool->pair_address;
            $edges[] = [$t0, $r0, $t1, $r1, $pair, $t0];
            $edges[] = [$t1, $r1, $t0, $r0, $pair, $t0];
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
            return $this->remember($pools, ['price' => [], 'via' => []]);
        }

        $via = [];

        // Bellman-Ford-style: prices settle within (node count) passes; the
        // early break stops as soon as a pass improves nothing.
        $maxPasses = count($edges) + 1;
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $changed = false;
            foreach ($edges as [$from, $rFrom, $to, $rTo, $pair, $token0]) {
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
                    // `token0` rides along because a chart reading this pool's
                    // reserves has to know which side of the pair it is.
                    $via[$to] = [
                        'pair' => $pair,
                        'token0' => $token0,
                        'from' => $from,
                    ];
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }

        return $this->remember($pools, ['price' => $price, 'via' => $via]);
    }

    /**
     * @param  Collection<int, object>  $pools
     * @param  array{price: array<string, float>, via: array<string, array{pair: string, token0: string, from: string}>}  $result
     * @return array{price: array<string, float>, via: array<string, array{pair: string, token0: string, from: string}>}
     */
    private function remember(Collection $pools, array $result): array
    {
        $this->memo = [spl_object_id($pools), $result];

        return $result;
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
