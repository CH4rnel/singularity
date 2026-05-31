<?php

namespace App\Http\Controllers;

use App\Services\BridgeEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BridgeAnalyticsController extends Controller
{
    private const FUNNEL_STEPS = [
        'page_view',
        'direction_selected',
        'evm_wallet_connected',
        'solana_wallet_connected',
        'amount_entered',
        'destination_entered',
        'lock_tx_submitted',
        'lock_tx_confirmed',
        'lock_tx_rejected',
        'bridge_submitted',
        'bridge_submit_failed',
        'bridge_request_created',
        'relayer_started',
        'relayer_succeeded',
        'relayer_failed',
    ];

    public function index(Request $request): Response
    {
        $days = (int) $request->query('days', 30);
        $days = max(1, min($days, 365));
        $since = Carbon::now()->subDays($days);

        $eventCounts = DB::table('bridge_events')
            ->where('created_at', '>=', $since)
            ->select('event_type', DB::raw('COUNT(DISTINCT session_id) as sessions'), DB::raw('COUNT(*) as total'))
            ->groupBy('event_type')
            ->get()
            ->keyBy('event_type');

        $funnel = collect(self::FUNNEL_STEPS)->map(fn (string $step) => [
            'event_type' => $step,
            'sessions' => (int) ($eventCounts[$step]->sessions ?? 0),
            'total' => (int) ($eventCounts[$step]->total ?? 0),
        ])->values();

        $dailyVolume = DB::table('bridge_events')
            ->where('created_at', '>=', $since)
            ->select(
                DB::raw('DATE(created_at) as day'),
                'event_type',
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy('day', 'event_type')
            ->orderBy('day')
            ->get();

        $topErrors = DB::table('bridge_events')
            ->where('created_at', '>=', $since)
            ->whereNotNull('error_message')
            ->select('event_type', 'error_message', DB::raw('COUNT(*) as total'))
            ->groupBy('event_type', 'error_message')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $completionRows = DB::table('bridge_requests')
            ->where('created_at', '>=', $since)
            ->whereNotNull('completed_at')
            ->select('created_at', 'completed_at')
            ->get();

        $seconds = $completionRows
            ->map(fn ($row) => Carbon::parse($row->completed_at)->diffInSeconds(Carbon::parse($row->created_at)))
            ->map(fn ($s) => (int) abs($s))
            ->values()
            ->all();

        $histogram = $this->bucket($seconds);

        $totals = [
            'requests' => DB::table('bridge_requests')->where('created_at', '>=', $since)->count(),
            'completed' => DB::table('bridge_requests')->where('created_at', '>=', $since)->where('status', 'completed')->count(),
            'failed' => DB::table('bridge_requests')->where('created_at', '>=', $since)->where('status', 'failed')->count(),
            'sessions' => DB::table('bridge_events')->where('created_at', '>=', $since)->distinct('session_id')->count('session_id'),
        ];

        return Inertia::render('admin/BridgeAnalytics', [
            'days' => $days,
            'totals' => $totals,
            'funnel' => $funnel,
            'dailyVolume' => $dailyVolume,
            'topErrors' => $topErrors,
            'completionHistogram' => $histogram,
            'eventTypes' => BridgeEventLogger::EVENT_TYPES,
        ]);
    }

    /**
     * @param  array<int, int>  $seconds
     * @return array<int, array{label: string, count: int}>
     */
    private function bucket(array $seconds): array
    {
        $buckets = [
            ['label' => '<10s', 'max' => 10, 'count' => 0],
            ['label' => '10-30s', 'max' => 30, 'count' => 0],
            ['label' => '30-60s', 'max' => 60, 'count' => 0],
            ['label' => '1-5m', 'max' => 300, 'count' => 0],
            ['label' => '5-15m', 'max' => 900, 'count' => 0],
            ['label' => '>15m', 'max' => PHP_INT_MAX, 'count' => 0],
        ];

        foreach ($seconds as $s) {
            foreach ($buckets as $i => $b) {
                if ($s <= $b['max']) {
                    $buckets[$i]['count']++;
                    break;
                }
            }
        }

        return array_map(fn ($b) => ['label' => $b['label'], 'count' => $b['count']], $buckets);
    }
}
