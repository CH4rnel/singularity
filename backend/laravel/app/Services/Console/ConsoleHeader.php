<?php

namespace App\Services\Console;

use App\Models\CrmTask;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ProductMetricsService;
use App\Services\Monitoring\ServiceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The strip along the top of every lens.
 *
 * It answers the two questions a person has before they have decided what
 * they came for: is anything on fire, and are the big numbers where they were
 * yesterday. It is deliberately the same on all five lenses — the console has
 * one state, and a header that changed per page would be five headers.
 *
 * The counters are healthy-out-of-registered per group, and `unknown` is
 * counted apart from both: a service whose reporter died is not down, and
 * printing it as down turns one dead heartbeat into twenty invented outages.
 */
class ConsoleHeader
{
    public function __construct(
        private ServiceRegistry $registry,
        private ProductMetricsService $metrics,
        private ConsoleFeed $feed,
        private ChatRoom $chat,
    ) {}

    /**
     * @param  User|null  $viewer  Whose unread count the chat badge carries;
     *                             the rest of the strip is the same for all.
     * @return array<string, mixed>
     */
    public function build(?User $viewer = null): array
    {
        $queue = $this->feed->cached();
        $attention = $queue['attention'] ?? [];

        return [
            'groups' => $this->groups(),
            'background' => $this->background(),
            'sweep' => $this->lastSweep(),
            // The worst thing in the queue, so the banner and the list can
            // never contradict each other.
            'banner' => $attention[0] ?? null,
            'counts' => [
                'attention' => count($attention),
                'tasks' => CrmTask::query()->overdue()->count(),
                // Per-viewer, unlike everything else here: what nobody has
                // read is a fact about a person and not about the console.
                'chat' => $this->chat->unreadFor($viewer),
            ],
            'quiet' => $queue['quiet'] ?? null,
        ];
    }

    /**
     * Healthy / registered per group, plus what is wrong inside it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groups(): array
    {
        $latest = [];

        foreach (ServiceCheck::query()->whereIn('id', ServiceCheck::latestIds())->get(['service', 'status']) as $check) {
            $latest[$check->service] = $check->status;
        }

        $groups = [];

        foreach ($this->registry->grouped() as $group => $definitions) {
            $counts = ['up' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0, 'off' => 0];

            foreach ($definitions as $definition) {
                $status = $latest[$definition->key] ?? 'unknown';
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }

            $groups[] = [
                'group' => $group,
                'total' => count($definitions),
                'healthy' => $counts['up'],
                'counts' => $counts,
                'tone' => match (true) {
                    $counts['down'] > 0 => 'critical',
                    $counts['degraded'] > 0 => 'warning',
                    $counts['unknown'] > 0 => 'unknown',
                    default => 'calm',
                },
            ];
        }

        return $groups;
    }

    /**
     * The three numbers that are always true regardless of the lens.
     *
     * Cached separately and for longer than the queue: they move over days,
     * and recomputing a thirty-day aggregate on every navigation is how a
     * console becomes slow enough to stop being left open.
     *
     * @return array<string, mixed>
     */
    private function background(): array
    {
        return Cache::remember('crm.console.header.background', 300, function (): array {
            $filters = new AnalyticsFilters(
                from: Carbon::now('UTC')->subDays(30)->startOfDay(),
                to: Carbon::now('UTC'),
            );

            return [
                'funded_active' => $this->metrics->weeklyActiveFundedUsers($filters),
                'installs_30d' => DB::table('analytics_users')
                    ->where('created_at', '>=', $filters->from)
                    ->count(),
                'bridge_30d_usd' => (float) DB::table('bridge_requests')
                    ->where('status', 'completed')
                    ->where('created_at', '>=', $filters->from)
                    ->sum('fee_usd'),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function lastSweep(): array
    {
        $at = DB::table('service_checks')->max('checked_at');

        return [
            'at' => $at ? CarbonImmutable::parse($at)->toIso8601String() : null,
            'interval_seconds' => (int) config('monitoring.heartbeat.stale_seconds', 240),
        ];
    }
}
