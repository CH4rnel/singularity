<?php

namespace App\Http\Controllers;

use App\Services\DexAprService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Liquidity (add/remove) page for the Ritual DEX (QuickSwap V2 fork).
 *
 * The page itself is client-side (ethers + wallet); we only seed it with the
 * known pools so the token/pool pickers are populated without the browser
 * enumerating the factory. Pools come from the `dex_pools` table the Telegram
 * bot's market snapshot maintains (see AnalyticsController). When the table is
 * empty the page falls back to "paste a token address".
 */
class LiquidityController extends Controller
{
    public function index(): Response
    {
        return $this->renderWithPools('Liquidity');
    }

    /** Swap page shares the same pool seed (token picker + routing graph). */
    public function swap(): Response
    {
        return $this->renderWithPools('Swap');
    }

    private function renderWithPools(string $page): Response
    {
        $hasPools = Schema::hasTable('dex_pools');
        $hasEvents = Schema::hasTable('activity_events');
        $swapHistorySince = now('UTC')->subDays(7)->toDateTimeString();

        return Inertia::render($page, [
            'pools' => $hasPools ? DB::table('dex_pools')->get() : collect(),
            'daily' => $hasEvents
                ? collect(DB::select(
                    "SELECT DATE(created_at) AS day,
                            COALESCE(SUM(usd), 0) AS swap_usd,
                            COUNT(*) AS swaps
                     FROM activity_events
                     WHERE kind = 'swap' AND created_at >= ?
                     GROUP BY day ORDER BY day",
                    [$swapHistorySince],
                ))
                : collect(),
            'priceHistory' => $hasEvents
                ? DB::table('activity_events')
                    ->where('kind', 'swap')
                    ->where('created_at', '>=', $swapHistorySince)
                    ->whereNotNull('sym_in')
                    ->whereNotNull('sym_out')
                    ->where('amt_in', '>', 0)
                    ->where('amt_out', '>', 0)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get(['id', 'sym_in', 'amt_in', 'sym_out', 'amt_out', 'meta', 'created_at'])
                : collect(),
            'indexerReady' => $hasPools,
            // Cached per-pool LP APR snapshot (scheduled dex:apr command);
            // null until the first run completes.
            'apr' => DexAprService::cached(),
        ]);
    }
}
