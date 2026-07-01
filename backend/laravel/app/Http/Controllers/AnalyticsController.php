<?php

namespace App\Http\Controllers;

use App\Services\CyberiaPrices;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public on-chain analytics: swaps, liquidity, bridges, token prices and DEX
 * pools. Everything except the live chain head comes from SQLite tables
 * written by the Telegram bot (services/telegram-bot/), which
 * indexes the chain and shares this app's database file. The page therefore
 * renders even when those tables don't exist yet — sections just come back
 * empty.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly CyberiaPrices $prices) {}

    public function index(Request $request): Response
    {
        $days = (int) $request->query('days', 1);
        $days = max(1, min($days, 90));
        // The bot writes created_at as UTC `YYYY-MM-DD HH:MM:SS` (sqlite
        // datetime('now')), so windows are compared in the same format.
        $since = Carbon::now('UTC')->subDays($days)->format('Y-m-d H:i:s');

        $hasEvents = Schema::hasTable('activity_events');
        $hasPrices = Schema::hasTable('token_prices');
        $hasPools = Schema::hasTable('dex_pools');

        $swaps = $hasEvents
            ? DB::table('activity_events')
                ->where('kind', 'swap')
                ->where('created_at', '>=', $since)
                ->selectRaw(
                    'COUNT(*) as count, '
                    .'COALESCE(SUM(usd), 0) as volume_usd, '
                    .'COUNT(DISTINCT user_addr) as traders, '
                    .'SUM(CASE WHEN usd IS NULL THEN 1 ELSE 0 END) as unpriced'
                )
                ->first()
            : null;

        $liquidity = $hasEvents
            ? DB::table('activity_events')
                ->whereIn('kind', ['liq_add', 'liq_remove'])
                ->where('created_at', '>=', $since)
                ->groupBy('kind')
                ->selectRaw('kind, COUNT(*) as count, COALESCE(SUM(usd), 0) as volume_usd')
                ->get()
                ->keyBy('kind')
            : collect();

        $lending = $hasEvents
            ? DB::table('activity_events')
                ->where('kind', 'like', 'lend\_%')
                ->where('created_at', '>=', $since)
                ->groupBy('kind')
                ->selectRaw('kind, COUNT(*) as count, COALESCE(SUM(usd), 0) as volume_usd')
                ->get()
            : collect();

        $topTokens = $hasEvents
            ? collect(DB::select(
                'SELECT sym AS symbol, SUM(usd) AS volume_usd, COUNT(*) AS legs FROM (
                    SELECT sym_in AS sym, usd FROM activity_events
                    WHERE kind = ? AND created_at >= ? AND usd IS NOT NULL
                    UNION ALL
                    SELECT sym_out, usd FROM activity_events
                    WHERE kind = ? AND created_at >= ? AND usd IS NOT NULL
                ) GROUP BY sym ORDER BY volume_usd DESC LIMIT 10',
                ['swap', $since, 'swap', $since],
            ))
            : collect();

        // Daily bars always cover at least two weeks so the chart stays
        // readable when the stat window is just 24h.
        $chartSince = Carbon::now('UTC')->subDays(max($days, 14))->format('Y-m-d H:i:s');
        $daily = $hasEvents
            ? collect(DB::select(
                "SELECT DATE(created_at) AS day,
                        SUM(CASE WHEN kind = 'swap' THEN COALESCE(usd, 0) ELSE 0 END) AS swap_usd,
                        SUM(CASE WHEN kind = 'swap' THEN 1 ELSE 0 END) AS swaps,
                        SUM(CASE WHEN kind IN ('liq_add', 'liq_remove') THEN 1 ELSE 0 END) AS liquidity_events,
                        SUM(CASE WHEN kind = 'bridge' THEN 1 ELSE 0 END) AS bridges
                 FROM activity_events
                 WHERE created_at >= ?
                 GROUP BY day ORDER BY day",
                [$chartSince],
            ))
            : collect();

        $recent = $hasEvents
            ? DB::table('activity_events')
                ->orderByDesc('id')
                ->limit(25)
                ->get(['kind', 'usd', 'sym_in', 'amt_in', 'sym_out', 'amt_out', 'user_addr', 'tx_hash', 'meta', 'created_at'])
            : collect();

        // Bridge stats come from the app's own table — full history, not just
        // what the bot announced.
        $bridges = DB::table('bridge_requests')
            ->where('status', 'completed')
            ->where('created_at', '>=', $since)
            ->groupBy('direction', 'token')
            ->selectRaw('direction, token, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->get();

        $pools = $hasPools
            ? DB::table('dex_pools')->get()
            : collect();

        // Price every token by walking the pool graph from the USD anchors,
        // rather than trusting the bot's token_prices.price_usd column — the
        // bot only relays one hop through WCYBER/CYBER.sol, so tokens reachable
        // only through a longer chain (e.g. RUB via SILVER) end up unpriced.
        $priceMap = $this->prices->priceFromPools($pools);

        if ($priceMap !== []) {
            $symbols = $this->prices->poolSymbols($pools);

            $prices = collect($priceMap)
                // Skip anchors that don't appear in any pool: they carry a
                // nominal $1 but aren't tradeable tokens on this DEX, so listing
                // them (with their raw address for a symbol) is just noise.
                ->filter(fn (float $usd, string $addr): bool => isset($symbols[$addr]))
                ->map(fn (float $usd, string $addr): object => (object) [
                    'address' => $addr,
                    'symbol' => $symbols[$addr],
                    'price_usd' => $usd,
                ])
                ->values()
                ->sortBy('symbol', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();

            // Recompute TVL from the fuller price set so pools whose tokens the
            // bot couldn't price (now reachable) stop showing a blank TVL.
            foreach ($pools as $pool) {
                $pool->tvl_usd = $this->prices->poolTvl($pool, $priceMap);
            }
            $pools = $pools->sortByDesc('tvl_usd')->values();

            $cyberAddr = array_search('CYBER.sol', $symbols, true);
            $cyberPrice = $cyberAddr !== false ? ($priceMap[$cyberAddr] ?? null) : null;
        } else {
            // No pool graph yet — fall back to whatever the bot recorded.
            $prices = $hasPrices
                ? DB::table('token_prices')->whereNotNull('price_usd')->orderBy('symbol')->get()
                : collect();
            $cyberPrice = null;
        }

        $cyberPrice ??= $hasPrices
            ? DB::table('token_prices')->where('symbol', 'CYBER.sol')->value('price_usd')
            : null;

        $cyberPrice ??= $this->configuredCyberPrice();

        return Inertia::render('Analytics', [
            'days' => $days,
            'swaps' => $swaps,
            'liquidity' => $liquidity,
            'lending' => $lending,
            'topTokens' => $topTokens,
            'daily' => $daily,
            'recent' => $recent,
            'bridges' => $bridges,
            'pools' => $pools,
            'prices' => $prices,
            'cyberPrice' => $cyberPrice,
            'tvlUsd' => $hasPools ? (float) $pools->sum('tvl_usd') : null,
            'snapshotAt' => $hasPools
                ? DB::table('dex_pools')->max('updated_at')
                : ($hasPrices ? DB::table('token_prices')->max('updated_at') : null),
            'chain' => $this->chainStats(),
            'explorerUrl' => rtrim(config('services.cyberia.explorer_url'), '/'),
            'indexerReady' => $hasEvents,
            // Uppercased symbol => address, so the token lists can link a symbol
            // to its /token/{address} page when it's a documented token.
            'tokenLinks' => collect(config('tokens.list', []))
                ->mapWithKeys(fn (array $meta, string $addr): array => [
                    strtoupper((string) $meta['symbol']) => $addr,
                ])
                ->all(),
        ]);
    }

    /**
     * Live chain head, cached briefly so page reloads don't hammer the RPC.
     *
     * @return array{latest_block: int|null, gas_price_gwei: float|null}|null
     */
    private function chainStats(): ?array
    {
        return Cache::remember('analytics.chain_stats', 15, function (): ?array {
            $rpc = config('services.ethereum.rpc_url') ?: 'https://rpc.cyberia.church';

            try {
                $block = $this->rpcCall($rpc, 'eth_blockNumber');
                $gasPrice = $this->rpcCall($rpc, 'eth_gasPrice');
            } catch (\Throwable) {
                return null;
            }

            if ($block === null) {
                return null;
            }

            return [
                'latest_block' => (int) hexdec(substr($block, 2)),
                'gas_price_gwei' => $gasPrice !== null ? hexdec(substr($gasPrice, 2)) / 1e9 : null,
            ];
        });
    }

    private function configuredCyberPrice(): ?float
    {
        $price = config('services.cyber_sol.price_usd');

        if ($price === null || $price === '') {
            return null;
        }

        return is_numeric($price) ? (float) $price : null;
    }

    private function rpcCall(string $rpc, string $method): ?string
    {
        $result = Http::timeout(4)
            ->post($rpc, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => []])
            ->json('result');

        return is_string($result) ? $result : null;
    }
}
