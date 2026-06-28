<?php

namespace App\Http\Controllers;

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
        $hasPools = Schema::hasTable('dex_pools');

        return Inertia::render('Liquidity', [
            'pools' => $hasPools ? DB::table('dex_pools')->get() : collect(),
            'indexerReady' => $hasPools,
        ]);
    }
}
