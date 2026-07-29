<?php

namespace App\Http\Controllers;

use App\Models\BridgeEvent;
use App\Models\BridgeRequest;
use App\Models\SiteEvent;
use App\Services\UserAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM funnel: where users drop off between visiting, connecting a wallet and
 * actually moving money. Session-level counts come from site_events (whole
 * site) and bridge_events (bridge flow); on-chain ground truth comes from the
 * Telegram bot's activity_events / bridge_requests, so the two can disagree —
 * that gap (e.g. many visitors, zero swaps) is exactly the signal.
 *
 * UserAnalyticsService answers the other half of the question: of the people
 * who did show up, how many came back, and is the progression system (levels,
 * streaks) moving that number.
 */
class CrmAnalyticsController extends Controller
{
    public function index(Request $request, UserAnalyticsService $users): Response
    {
        $days = max(1, min((int) $request->query('days', 30), 90));
        $since = now('UTC')->subDays($days);

        $sessions = fn (string|array $events): int => SiteEvent::query()
            ->whereIn('event', (array) $events)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->count('session_id');

        $funnel = [
            'visitors' => $sessions(['landing_view', 'page_view']),
            'wallets' => $sessions('wallet_connected'),
            'swaps' => $sessions(['swap_completed', 'swap_executed']),
            'liquidity' => $sessions('liquidity_added'),
            'bridge' => BridgeEvent::query()
                ->where('event_type', 'bridge_submitted')
                ->where('created_at', '>=', $since)
                ->distinct()
                ->count('session_id'),
        ];

        // The bot writes created_at as sqlite text datetime('now') (UTC).
        $sinceText = $since->format('Y-m-d H:i:s');
        $hasActivity = Schema::hasTable('activity_events');

        $onchainRow = fn (string $kind) => $hasActivity
            ? DB::table('activity_events')
                ->where('kind', $kind)
                ->where('created_at', '>=', $sinceText)
                ->selectRaw('COUNT(*) as count, COUNT(DISTINCT user_addr) as actors, COALESCE(SUM(usd), 0) as usd')
                ->first()
            : null;

        $onchain = [
            'swaps' => $onchainRow('swap'),
            'liq_adds' => $onchainRow('liq_add'),
            'bridge_completed' => BridgeRequest::query()
                ->where('status', 'completed')
                ->where('created_at', '>=', $since)
                ->count(),
        ];

        $daily = SiteEvent::query()
            ->whereIn('event', ['landing_view', 'page_view'])
            ->where('created_at', '>=', now('UTC')->subDays(min($days, 30)))
            ->selectRaw('DATE(created_at) as day, COUNT(DISTINCT session_id) as visitors')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $recent = SiteEvent::query()
            ->with('user:id,name')
            ->latest('created_at')
            ->limit(30)
            ->get(['id', 'session_id', 'user_id', 'wallet_address', 'event', 'page', 'created_at']);

        return Inertia::render('crm/Analytics', [
            'days' => $days,
            'funnel' => $funnel,
            'onchain' => $onchain,
            'daily' => $daily,
            'recent' => $recent,
            // Retention: the funnel says whether a visit converted, this says
            // whether the person came back.
            'activity' => $users->activity(),
            'newVsReturning' => $users->newVsReturning($days),
            'cohorts' => $users->cohorts(),
            'progression' => $users->progression(),
            'topMembers' => $users->topMembers(),
        ]);
    }
}
