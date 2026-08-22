<?php

namespace App\Services\Console;

use App\Models\BridgeEvent;
use App\Models\BridgeRequest;
use App\Models\SiteEvent;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ProductMetricsService;
use App\Services\UserAnalyticsService;
use Illuminate\Support\Facades\DB;

/**
 * "Числа" — six questions, each with an answer, its evidence and one line of
 * what to do about it.
 *
 * What this replaces: ten tiles and eight tables spread over two pages, one
 * counting site sessions and one counting wallet installations. The subject is
 * now a switch rather than two addresses, because the confusion between "a
 * browser reading pages" and "an installation of the wallet" cost a quarter of
 * arguments, and a switch makes the choice explicit every time.
 *
 * The rule for every block is the same: a number that cannot be read is null
 * and renders as an em dash, and a question this subject cannot answer says so
 * instead of borrowing the other subject's number. "D7 fell to 18%" without
 * "and all of it is on android 1.0.0" is a meeting; with it, it is a task.
 */
class NumbersReport
{
    public const SUBJECTS = ['installs', 'sessions'];

    public function __construct(
        private ProductMetricsService $metrics,
        private UserAnalyticsService $site,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function questions(string $subject, AnalyticsFilters $filters): array
    {
        return $subject === 'sessions'
            ? $this->sessionQuestions($filters)
            : $this->installQuestions($filters);
    }

    /* --------------------------------------------- the wallet, installed -- */

    /** @return array<int, array<string, mixed>> */
    private function installQuestions(AnalyticsFilters $filters): array
    {
        $funnel = $this->metrics->mainFunnel($filters);
        $cohorts = $this->metrics->retentionCohorts($filters);
        $acquisition = $this->metrics->acquisition($filters, 12);
        $transactions = $this->metrics->outcomeRate($filters, 'transaction');
        $errors = $this->metrics->errors($filters, 8);
        $gas = $this->metrics->gasSponsorship($filters);
        $series = $this->metrics->activeOverTime($filters);

        $installs = $funnel[0]['value'] ?? 0;
        $previousInstalls = $this->previousInstalls($filters);
        $activated = $this->step($funnel, 'activated');
        $matured = array_values(array_filter($cohorts, fn (array $c) => ($c['rates']['d7'] ?? null) !== null));
        $best = $this->bestSource($acquisition);

        usort($acquisition, function (array $a, array $b) {
            return ($b['d7'] ?? -1) <=> ($a['d7'] ?? -1);
        });

        return [
            [
                'key' => 'growth',
                'answer' => [
                    'value' => $installs,
                    'unit' => 'count',
                    'tone' => 'plain',
                ],
                'conclusion' => [
                    'key' => $previousInstalls > 0 ? 'numbers.growth.delta' : 'numbers.growth.first',
                    'params' => [
                        'delta' => $previousInstalls > 0
                            ? round(($installs - $previousInstalls) / $previousInstalls * 100, 1)
                            : 0,
                        'source' => $best['source'] ?? '—',
                        'campaign' => $best['campaign'] ?? '—',
                        'sourceUsers' => $best['users'] ?? 0,
                        'without' => max(0, $installs - (int) ($best['users'] ?? 0)),
                    ],
                ],
                'evidence' => [
                    'type' => 'bars',
                    'rows' => array_map(fn (array $row) => [
                        'day' => $row['day'],
                        'total' => $row['opened'],
                        'part' => $row['active'],
                    ], $series),
                    'legend' => ['total' => 'numbers.growth.opened', 'part' => 'numbers.growth.acted'],
                ],
            ],
            [
                'key' => 'money',
                'answer' => [
                    // The step that costs the most and is least about the
                    // interface: money has to arrive from outside.
                    'value' => $funnel[3]['of_top'] ?? null,
                    'unit' => 'percent',
                    'tone' => 'plain',
                ],
                'conclusion' => [
                    'key' => 'numbers.money.conclusion',
                    'params' => [
                        'wallets' => $this->step($funnel, 'wallet'),
                        'funded' => $this->step($funnel, 'funded'),
                        'drop' => (int) $this->step($funnel, 'wallet') - (int) $this->step($funnel, 'funded'),
                    ],
                ],
                'evidence' => ['type' => 'funnel', 'steps' => $funnel],
            ],
            [
                'key' => 'return',
                'answer' => [
                    'value' => $matured[0]['rates']['d7'] ?? null,
                    'unit' => 'percent',
                    'tone' => $this->retentionTone($matured),
                    'before' => $matured[1]['rates']['d7'] ?? null,
                ],
                'conclusion' => $this->retentionConclusion($filters, $matured),
                'evidence' => ['type' => 'cohorts', 'rows' => $cohorts],
            ],
            [
                'key' => 'sources',
                'answer' => [
                    'value' => $acquisition[0]['source'] ?? null,
                    'unit' => 'text',
                    'tone' => 'accent',
                ],
                'conclusion' => [
                    'key' => $acquisition === [] ? 'numbers.sources.empty' : 'numbers.sources.conclusion',
                    'params' => [
                        'source' => $acquisition[0]['source'] ?? '—',
                        'd7' => $acquisition[0]['d7'] ?? 0,
                        'users' => $acquisition[0]['users'] ?? 0,
                    ],
                ],
                'evidence' => ['type' => 'sources', 'rows' => array_slice($acquisition, 0, 8)],
            ],
            [
                'key' => 'breaks',
                'answer' => [
                    'value' => $transactions['rate'],
                    'unit' => 'percent',
                    'tone' => 'plain',
                ],
                'conclusion' => [
                    'key' => 'numbers.breaks.conclusion',
                    'params' => [
                        'failures' => $transactions['failures'],
                        'attempts' => $transactions['attempts'],
                    ],
                ],
                'evidence' => ['type' => 'errors', 'rows' => $errors],
            ],
            [
                'key' => 'cost',
                'answer' => [
                    'value' => $gas['usd_per_activated_user'],
                    'unit' => 'usd',
                    'tone' => 'accent',
                ],
                'conclusion' => [
                    'key' => $gas['transactions'] === 0 ? 'numbers.cost.idle' : 'numbers.cost.conclusion',
                    'params' => [
                        'drips' => $gas['transactions'],
                        'addresses' => $gas['addresses'],
                        'activated' => $activated,
                    ],
                ],
                'evidence' => [
                    'type' => 'tiles',
                    'rows' => [
                        ['key' => 'drips', 'value' => $gas['transactions'], 'unit' => 'count', 'note' => 'numbers.cost.addresses', 'params' => ['addresses' => $gas['addresses']]],
                        ['key' => 'spent', 'value' => $gas['total_usd'], 'unit' => 'usd', 'note' => 'numbers.cost.fromLedger', 'params' => []],
                        ['key' => 'perAddress', 'value' => $gas['addresses'] > 0 && $gas['total_usd'] !== null ? round($gas['total_usd'] / $gas['addresses'], 4) : null, 'unit' => 'usd', 'note' => 'numbers.cost.fixedDrip', 'params' => []],
                        ['key' => 'perActivation', 'value' => $gas['usd_per_activated_user'], 'unit' => 'usd', 'note' => 'numbers.cost.ratio', 'params' => ['drips' => $gas['transactions'], 'activated' => $activated]],
                        ['key' => 'refused', 'value' => $gas['failed'], 'unit' => 'count', 'note' => 'numbers.cost.refusedNote', 'params' => []],
                    ],
                ],
            ],
        ];
    }

    /* ------------------------------------------------ the site, in a tab -- */

    /** @return array<int, array<string, mixed>> */
    private function sessionQuestions(AnalyticsFilters $filters): array
    {
        $days = $filters->days();
        $since = $filters->from;

        $sessions = fn (array $events): int => SiteEvent::query()
            ->whereIn('event', $events)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->count('session_id');

        $visitors = $sessions(['landing_view', 'page_view']);
        $wallets = $sessions(['wallet_connected']);
        $swaps = $sessions(['swap_completed', 'swap_executed']);
        $liquidity = $sessions(['liquidity_added']);

        $bridged = BridgeEvent::query()
            ->where('event_type', 'bridge_submitted')
            ->where('created_at', '>=', $since)
            ->distinct()
            ->count('session_id');

        $previous = SiteEvent::query()
            ->whereIn('event', ['landing_view', 'page_view'])
            ->whereBetween('created_at', [$since->copy()->subDays($days), $since])
            ->distinct()
            ->count('session_id');

        $daily = SiteEvent::query()
            ->whereIn('event', ['landing_view', 'page_view'])
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(DISTINCT session_id) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $funnel = $this->shares([
            ['key' => 'visitors', 'value' => $visitors],
            ['key' => 'wallet_connected', 'value' => $wallets],
            ['key' => 'swap', 'value' => $swaps],
            ['key' => 'liquidity', 'value' => $liquidity],
            ['key' => 'bridge', 'value' => $bridged],
        ]);

        $cohorts = $this->site->cohorts();
        $matured = array_values(array_filter($cohorts, fn (array $c) => ($c['rates']['d7'] ?? null) !== null));

        $requests = BridgeRequest::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $completed = (int) ($requests['completed'] ?? 0);
        $failed = (int) ($requests['failed'] ?? 0) + (int) ($requests['expired'] ?? 0);
        $finished = $completed + $failed;

        return [
            [
                'key' => 'growth',
                'answer' => ['value' => $visitors, 'unit' => 'count', 'tone' => 'plain'],
                'conclusion' => [
                    'key' => $previous > 0 ? 'numbers.growth.deltaSessions' : 'numbers.growth.first',
                    'params' => ['delta' => $previous > 0 ? round(($visitors - $previous) / $previous * 100, 1) : 0],
                ],
                'evidence' => [
                    'type' => 'bars',
                    'rows' => $daily->map(fn ($row) => [
                        'day' => $row->day,
                        'total' => (int) $row->total,
                        'part' => 0,
                    ])->all(),
                    'legend' => ['total' => 'numbers.growth.sessions', 'part' => null],
                ],
            ],
            [
                'key' => 'money',
                'answer' => [
                    'value' => $funnel[4]['of_top'] ?? null,
                    'unit' => 'percent',
                    'tone' => 'plain',
                ],
                'conclusion' => [
                    'key' => 'numbers.money.sessions',
                    'params' => ['visitors' => $visitors, 'wallets' => $wallets],
                ],
                'evidence' => ['type' => 'funnel', 'steps' => $funnel],
            ],
            [
                'key' => 'return',
                'answer' => [
                    'value' => $matured[0]['rates']['d7'] ?? null,
                    'unit' => 'percent',
                    'tone' => $this->retentionTone($matured),
                    'before' => $matured[1]['rates']['d7'] ?? null,
                ],
                'conclusion' => [
                    'key' => 'numbers.return.sessions',
                    'params' => ['weeks' => count($cohorts)],
                ],
                'evidence' => ['type' => 'cohorts', 'rows' => $cohorts],
            ],
            [
                'key' => 'sources',
                'answer' => ['value' => null, 'unit' => 'text', 'tone' => 'plain'],
                // The honest answer, and the reason the switch exists: the
                // site does not record where a visit came from, and borrowing
                // the installations' answer here would be a different subject
                // wearing this one's label.
                'conclusion' => ['key' => 'numbers.sources.unmeasured', 'params' => []],
                'evidence' => ['type' => 'unmeasured', 'note' => 'numbers.sources.unmeasuredNote'],
            ],
            [
                'key' => 'breaks',
                'answer' => [
                    'value' => $finished > 0 ? round($completed / $finished * 100, 1) : null,
                    'unit' => 'percent',
                    'tone' => 'plain',
                ],
                'conclusion' => [
                    'key' => 'numbers.breaks.sessions',
                    'params' => ['failed' => $failed, 'finished' => $finished],
                ],
                'evidence' => [
                    'type' => 'statuses',
                    'rows' => $requests->map(fn ($total, $status) => [
                        'status' => $status,
                        'total' => (int) $total,
                    ])->values()->all(),
                ],
            ],
            [
                'key' => 'cost',
                'answer' => ['value' => null, 'unit' => 'usd', 'tone' => 'plain'],
                'conclusion' => ['key' => 'numbers.cost.unmeasured', 'params' => []],
                'evidence' => ['type' => 'unmeasured', 'note' => 'numbers.cost.unmeasuredNote'],
            ],
        ];
    }

    /* ----------------------------------------------------------- helpers -- */

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function shares(array $steps): array
    {
        $top = $steps[0]['value'] ?? 0;
        $previous = null;

        return array_map(function (array $step) use ($top, &$previous): array {
            $row = [
                'key' => $step['key'],
                'value' => $step['value'],
                'of_top' => $top > 0 ? round($step['value'] / $top * 100, 1) : null,
                'of_previous' => $previous === null || $previous === 0
                    ? null
                    : round($step['value'] / $previous * 100, 1),
            ];

            $previous = $step['value'];

            return $row;
        }, $steps);
    }

    /** @param array<int, array<string, mixed>> $funnel */
    private function step(array $funnel, string $key): int
    {
        foreach ($funnel as $step) {
            if ($step['key'] === $key) {
                return (int) $step['value'];
            }
        }

        return 0;
    }

    private function previousInstalls(AnalyticsFilters $filters): int
    {
        $days = $filters->days();

        return DB::table('analytics_users')
            ->whereBetween('created_at', [
                $filters->from->copy()->subDays($days),
                $filters->from,
            ])
            ->count();
    }

    /**
     * @param  array<int, array<string, mixed>>  $acquisition
     * @return array<string, mixed>|null
     */
    private function bestSource(array $acquisition): ?array
    {
        if ($acquisition === []) {
            return null;
        }

        $sorted = $acquisition;
        usort($sorted, fn (array $a, array $b) => $b['users'] <=> $a['users']);

        return $sorted[0];
    }

    /** @param array<int, array<string, mixed>> $matured */
    private function retentionTone(array $matured): string
    {
        if (count($matured) < 2) {
            return 'plain';
        }

        $drop = (float) $matured[1]['rates']['d7'] - (float) $matured[0]['rates']['d7'];

        return $drop >= (float) config('crm.console.retention_drop_points', 3) ? 'critical' : 'plain';
    }

    /**
     * Where a retention drop actually lives.
     *
     * The version breakdown is the whole point of the block: a fall that is
     * evenly spread is the market, and a fall that sits entirely on one build
     * is a regression with an owner.
     *
     * @param  array<int, array<string, mixed>>  $matured
     * @return array<string, mixed>
     */
    private function retentionConclusion(AnalyticsFilters $filters, array $matured): array
    {
        if (count($matured) < 2) {
            return ['key' => 'numbers.return.young', 'params' => []];
        }

        $drop = (float) $matured[1]['rates']['d7'] - (float) $matured[0]['rates']['d7'];

        if ($drop < (float) config('crm.console.retention_drop_points', 3)) {
            return [
                'key' => 'numbers.return.steady',
                'params' => ['now' => $matured[0]['rates']['d7'], 'before' => $matured[1]['rates']['d7']],
            ];
        }

        $versions = [];

        foreach ($this->metrics->filterOptions()['app_versions'] as $version) {
            $scoped = new AnalyticsFilters(
                from: $filters->from,
                to: $filters->to,
                platform: $filters->platform,
                appVersion: $version,
            );

            $cohorts = $this->metrics->retentionCohorts($scoped);

            foreach ($cohorts as $cohort) {
                if (($cohort['rates']['d7'] ?? null) !== null) {
                    $versions[$version] = $cohort['rates']['d7'];
                    break;
                }
            }
        }

        if ($versions === []) {
            return [
                'key' => 'numbers.return.drop',
                'params' => ['now' => $matured[0]['rates']['d7'], 'before' => $matured[1]['rates']['d7']],
            ];
        }

        asort($versions);
        $worst = array_key_first($versions);
        $best = array_key_last($versions);

        return [
            'key' => $worst === $best ? 'numbers.return.drop' : 'numbers.return.dropVersion',
            'params' => [
                'now' => $matured[0]['rates']['d7'],
                'before' => $matured[1]['rates']['d7'],
                'worst' => (string) $worst,
                'worstRate' => $versions[$worst],
                'best' => (string) $best,
                'bestRate' => $versions[$best],
            ],
        ];
    }
}
