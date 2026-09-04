<?php

namespace App\Services\Console;

use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ProductMetricsService;
use App\Services\GasSponsorService;
use App\Services\Monitoring\HeartbeatFleet;
use App\Services\Monitoring\ServiceRegistry;
use App\Services\WalletPriceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Сейчас" — what requires a person, in one queue.
 *
 * The old CRM answered "what do you want to look at": five sections, each with
 * filters and a table. There are three operators and they come in between
 * other work, so the real question is the other one — what requires me right
 * now — and it is answered by a single stream ordered by urgency, in which an
 * incident, an overdue task, a whale's first trade, a sagging retention curve
 * and an emptying gas tank stand in the same line.
 *
 * Three properties hold the design up:
 *
 *   - Every item carries how long it has been in this state. That column is
 *     the priority; a status board that only shows the current state makes
 *     twelve minutes and three hours look the same.
 *   - Every item has exactly one obvious next step and a way to put it down.
 *     Three equal buttons is a decision, and the reader already has one.
 *   - Silence is a designed state, not an empty list (see `quiet`): an empty
 *     screen has to say when the last sweep ran, or it is indistinguishable
 *     from collection having broken.
 *
 * Nothing here writes anything or touches a chain except through services
 * that already cache — the console is read-only by construction, which is what
 * makes it safe to leave open on a second monitor.
 */
class ConsoleFeed
{
    public function __construct(
        private ServiceRegistry $registry,
        private ServiceStrips $strips,
        private Snooze $snooze,
        private ProductMetricsService $metrics,
        private GasSponsorService $gas,
        private WalletPriceService $prices,
    ) {}

    /** The cache key the whole queue lives under. */
    public const CACHE_KEY = 'crm.console.feed';

    /**
     * The queue, computed at most every few seconds.
     *
     * Not for speed — for agreement. The rail's badge, the banner in the top
     * bar and the list itself are three renderings of one answer, and a
     * console whose badge says five while the list shows four is a console
     * that gets refreshed instead of read. Snoozing an item drops this.
     *
     * @return array<string, mixed>
     */
    public function cached(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('crm.console.cache_seconds', 30),
            fn () => $this->build(),
        );
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $items = [
            ...$this->incidents(),
            ...$this->overdueTasks(),
            ...$this->freshWhales(),
            ...$this->gasTank(),
            ...$this->retention(),
            ...$this->failedBridges(),
        ];

        $attention = [];
        $sleeping = [];

        // Read once per build: the sleeping set must not change halfway
        // through sorting the queue it is filtering.
        $asleep = $this->snooze->active();

        foreach ($items as $item) {
            $until = $asleep[$item->key] ?? null;

            if ($until !== null) {
                $sleeping[] = new FeedItem(
                    key: $item->key,
                    kind: $item->kind,
                    severity: $item->severity,
                    titleKey: $item->titleKey,
                    bodyKey: $item->bodyKey,
                    params: $item->params,
                    since: $item->since,
                    evidence: $item->evidence,
                    action: $item->action,
                    snoozable: false,
                    snoozedUntil: $until,
                );

                continue;
            }

            $attention[] = $item;
        }

        usort($attention, function (FeedItem $a, FeedItem $b): int {
            $weight = FeedItem::weight($a->severity) <=> FeedItem::weight($b->severity);

            if ($weight !== 0) {
                return $weight;
            }

            // Freshest state change first inside a severity: a two-day-old
            // outage already has somebody on it, a twelve-minute-old one does
            // not. The duration is printed either way, which is what makes
            // this readable rather than arbitrary.
            return ($b->since?->getTimestamp() ?? 0) <=> ($a->since?->getTimestamp() ?? 0);
        });

        return [
            'attention' => array_map(fn (FeedItem $item) => $item->toArray(), $attention),
            'watch' => $this->watch($sleeping),
            'quiet' => $this->quiet($attention),
            'tiles' => $this->tiles(),
        ];
    }

    /* -------------------------------------------------- requires action -- */

    /**
     * Open incidents, which are the monitor's own opinion rather than this
     * page's: an incident row exists only after two consecutive failures, so
     * a single flapped sweep never reaches a person here either.
     *
     * @return array<int, FeedItem>
     */
    private function incidents(): array
    {
        $definitions = $this->registry->all();
        $items = [];

        foreach (ServiceIncident::query()->open()->orderByDesc('started_at')->get() as $incident) {
            $definition = $definitions[$incident->service] ?? null;
            $started = $incident->started_at ? CarbonImmutable::parse($incident->started_at) : null;

            $items[] = new FeedItem(
                key: 'incident:'.$incident->service,
                kind: 'incident',
                severity: $incident->status === 'down' ? 'critical' : 'warning',
                titleKey: 'feed.incident.'.$incident->status,
                bodyKey: 'feed.incident.body',
                params: [
                    'service' => $definition?->label ?? $incident->service,
                    'reason' => $incident->reason ?? '—',
                    'critical' => $definition?->critical ? 1 : 0,
                    'notified' => $incident->notified_at !== null ? 1 : 0,
                ],
                since: $started,
                evidence: [
                    'type' => 'strip',
                    'label' => 'evidence.day',
                    'cells' => $this->strips->for($incident->service) ?? [],
                ],
                action: ['key' => 'openMachine', 'href' => '/crm/machines#'.$incident->service],
            );
        }

        return $items;
    }

    /**
     * Overdue tasks. A promise made to a person and missed is the same kind of
     * event as a service being down, which is why it stands in the same queue
     * instead of on a board somebody has to remember to open.
     *
     * @return array<int, FeedItem>
     */
    private function overdueTasks(): array
    {
        $tasks = CrmTask::query()
            ->overdue()
            ->with(['assignee:id,name', 'contact:id,name'])
            ->orderBy('due_at')
            ->limit(10)
            ->get();

        return $tasks->map(function (CrmTask $task): FeedItem {
            $due = CarbonImmutable::parse($task->due_at);

            return new FeedItem(
                key: 'task:'.$task->id,
                kind: 'task',
                // A high-priority promise that is already late is not a
                // warning; everything else is.
                severity: $task->priority === 'high' ? 'critical' : 'warning',
                titleKey: 'feed.task.title',
                bodyKey: 'feed.task.body',
                params: [
                    'task' => $task->title,
                    'assignee' => $task->assignee?->name ?? '',
                    'priority' => $task->priority,
                    'contact' => $task->contact?->name ?? '',
                    'note' => $task->description ?? '',
                ],
                since: $due,
                evidence: [
                    'type' => 'text',
                    'label' => 'evidence.dueWas',
                    'text' => $due->format('d.m'),
                ],
                action: ['key' => 'openTask', 'href' => '/crm/tasks#task-'.$task->id],
            );
        })->all();
    }

    /**
     * Whales the sync has only just learned about.
     *
     * Money is its own colour here on purpose: this is not a failure and never
     * becomes one, but it has a shelf life of hours — a first message to
     * somebody who moved five figures today reads very differently tomorrow.
     *
     * @return array<int, FeedItem>
     */
    private function freshWhales(): array
    {
        $window = (int) config('crm.console.whale_window_days', 7);
        $price = $this->prices->quotes()['prices']['cyberia'] ?? null;

        $whales = CrmContact::query()
            ->where('type', 'whale')
            ->where('created_at', '>=', now()->subDays($window))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return $whales->map(function (CrmContact $contact) use ($price): FeedItem {
            $cyber = (float) ($contact->cyber_balance ?? 0);
            $sol = (float) ($contact->cyber_sol_balance ?? 0);
            $usd = $price === null ? null : round(($cyber + $sol) * $price);

            return new FeedItem(
                key: 'whale:'.$contact->id,
                kind: 'whale',
                severity: 'money',
                titleKey: 'feed.whale.title',
                bodyKey: 'feed.whale.body',
                params: [
                    'name' => $contact->name ?? $contact->telegram ?? $contact->evm_address ?? '#'.$contact->id,
                    'cyber' => $cyber,
                    'cyberSol' => $sol,
                    'chains' => ($cyber > 0 ? 1 : 0) + ($sol > 0 ? 1 : 0),
                ],
                since: CarbonImmutable::parse($contact->created_at),
                evidence: $usd === null
                    // A price this server could not read is "—", never zero:
                    // a whale rendered as $0 is a whale nobody calls.
                    ? ['type' => 'text', 'label' => 'evidence.noPrice', 'text' => '—']
                    : ['type' => 'value', 'label' => 'evidence.holdings', 'value' => $usd, 'unit' => 'usd', 'tone' => 'money'],
                action: ['key' => 'openPerson', 'href' => '/crm/'.$contact->id],
            );
        })->all();
    }

    /**
     * The gas station's tank.
     *
     * An empty tank breaks nothing loudly. It switches off the first payment
     * of every newcomer who arrived holding a token and no coin, which shows
     * up two weeks later as a retention number nobody can explain — so it is
     * worth a line while it is still six hours away.
     *
     * @return array<int, FeedItem>
     */
    private function gasTank(): array
    {
        $summary = $this->gas->summary();

        if ($summary === null) {
            return [];
        }

        // The station answers in wei. The floor is written in CYBER, the way
        // an operator says it, so the two are put in the same unit here —
        // before this, the row compared 9.9e17 against 60 and stayed silent
        // no matter how the tank was doing.
        $tank = (float) $this->gas->cyber((string) ($summary['tank'] ?? '0'));
        $drip = (float) $this->gas->cyber((string) ($summary['drip'] ?? '0'));
        $floor = (float) config('crm.console.gas_tank_floor', 60);

        if ($tank > $floor) {
            return [];
        }

        return [new FeedItem(
            key: 'gas:tank',
            kind: 'gas',
            severity: $tank <= 0 ? 'critical' : 'warning',
            titleKey: $tank <= 0 ? 'feed.gas.empty' : 'feed.gas.low',
            bodyKey: 'feed.gas.body',
            params: [
                'tank' => round($tank, 2),
                'drips' => $drip > 0 ? (int) floor($tank / $drip) : 0,
                'dailyCap' => (float) $this->gas->cyber((string) ($summary['dailyCap'] ?? '0')),
            ],
            // The tank has no "since": it did not enter this state at a
            // moment anything recorded, so the row shows the level instead of
            // inventing an age for it.
            since: null,
            evidence: [
                'type' => 'value',
                'label' => 'evidence.tank',
                'value' => round($tank, 1),
                'unit' => 'cyber',
                'tone' => 'warning',
            ],
            action: ['key' => 'topUpTank', 'href' => '/crm/machines#gas-station'],
        )];
    }

    /**
     * A D7 retention drop, against the last cohort that had time to mature.
     *
     * Reported with the version breakdown attached or not at all: "D7 fell to
     * 18%" is a meeting, "D7 fell to 18% and all of it is on android 1.0.0"
     * is a task for one person.
     *
     * @return array<int, FeedItem>
     */
    private function retention(): array
    {
        $filters = $this->window();
        $cohorts = $this->metrics->retentionCohorts($filters);

        $matured = array_values(array_filter(
            $cohorts,
            fn (array $cohort) => ($cohort['rates']['d7'] ?? null) !== null,
        ));

        if (count($matured) < 2) {
            return [];
        }

        [$latest, $previous] = $matured;
        $drop = (float) $previous['rates']['d7'] - (float) $latest['rates']['d7'];

        if ($drop < (float) config('crm.console.retention_drop_points', 3)) {
            return [];
        }

        return [new FeedItem(
            key: 'retention:d7:'.$latest['week'],
            kind: 'retention',
            severity: 'neutral',
            titleKey: 'feed.retention.title',
            bodyKey: 'feed.retention.body',
            params: [
                'now' => $latest['rates']['d7'],
                'before' => $previous['rates']['d7'],
                'cohort' => $latest['week'],
                'size' => $latest['size'],
            ],
            since: CarbonImmutable::parse($latest['week'])->addDays(13),
            evidence: [
                'type' => 'spark',
                'label' => 'evidence.d7',
                'values' => array_reverse(array_map(
                    fn (array $cohort) => (float) ($cohort['rates']['d7'] ?? 0),
                    array_filter($cohorts, fn (array $c) => ($c['rates']['d7'] ?? null) !== null),
                )),
                'tone' => 'neutral',
            ],
            action: ['key' => 'investigate', 'href' => '/crm/numbers#retention'],
        )];
    }

    /**
     * Bridge requests that failed today.
     *
     * A failed payout is the one thing here that can mean money left and
     * never arrived, so it stands with the incidents rather than in the watch
     * list — and a timeout on a slow chain can be a false failure, which is
     * exactly why a person has to look.
     *
     * @return array<int, FeedItem>
     */
    private function failedBridges(): array
    {
        $failed = BridgeRequest::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subDay())
            ->orderByDesc('updated_at')
            ->get(['id', 'token', 'amount', 'direction', 'updated_at']);

        if ($failed->isEmpty()) {
            return [];
        }

        $newest = $failed->first();

        return [new FeedItem(
            key: 'bridge:failed:'.$newest->id,
            kind: 'bridge',
            severity: 'critical',
            titleKey: 'feed.bridge.title',
            bodyKey: 'feed.bridge.body',
            params: [
                'count' => $failed->count(),
                'token' => (string) $newest->token,
                'amount' => (string) $newest->amount,
                'direction' => (string) $newest->direction,
            ],
            since: CarbonImmutable::parse($newest->updated_at),
            evidence: [
                'type' => 'value',
                'label' => 'evidence.failedRequests',
                'value' => $failed->count(),
                'unit' => 'count',
                'tone' => 'critical',
            ],
            action: ['key' => 'openBridge', 'href' => '/admin/bridge-analytics'],
        )];
    }

    /* ------------------------------------------------------- observation -- */

    /**
     * Things worth having in view that nobody has to do anything about.
     *
     * The snoozed items live here too, with their wake-up time printed. An
     * item that was put down still exists, and a console where "later" means
     * "gone" is a console nobody trusts twice.
     *
     * @param  array<int, FeedItem>  $sleeping
     * @return array<int, array<string, mixed>>
     */
    private function watch(array $sleeping): array
    {
        $rows = [];

        $waiting = $this->waitingBridges();

        if ($waiting !== null) {
            $rows[] = $waiting;
        }

        foreach ($this->silentHosts() as $host) {
            $rows[] = $host;
        }

        $campaign = $this->bestCampaign();

        if ($campaign !== null) {
            $rows[] = $campaign;
        }

        if ($sleeping !== []) {
            $wake = min(array_map(fn (FeedItem $item) => $item->snoozedUntil?->getTimestamp() ?? PHP_INT_MAX, $sleeping));

            $rows[] = [
                'key' => 'snoozed',
                'severity' => 'neutral',
                'title' => 'watch.snoozed.title',
                'body' => 'watch.snoozed.body',
                'params' => [
                    'count' => count($sleeping),
                    'items' => implode(', ', array_map(
                        fn (FeedItem $item) => $item->params['service'] ?? $item->params['task'] ?? $item->params['name'] ?? $item->kind,
                        array_slice($sleeping, 0, 3),
                    )),
                ],
                'value' => CarbonImmutable::createFromTimestamp($wake, 'UTC')->toIso8601String(),
                'value_kind' => 'time',
                'href' => null,
                'items' => array_map(fn (FeedItem $item) => $item->toArray(), $sleeping),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function waitingBridges(): ?array
    {
        $minutes = (int) config('crm.console.bridge_wait_minutes', 20);

        $waiting = BridgeRequest::query()
            ->whereIn('status', [
                BridgeRequest::PENDING,
                BridgeRequest::PROCESSING,
                BridgeRequest::AWAITING_LIQUIDITY,
                BridgeRequest::PAYING_OUT,
                BridgeRequest::BURN_PENDING,
            ])
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->get(['id', 'fee_usd', 'amount', 'token']);

        if ($waiting->isEmpty()) {
            return null;
        }

        return [
            'key' => 'bridge:waiting',
            'severity' => 'warning',
            'title' => 'watch.bridge.title',
            'body' => 'watch.bridge.body',
            'params' => ['count' => $waiting->count(), 'minutes' => $minutes],
            'value' => null,
            'value_kind' => 'count',
            'count' => $waiting->count(),
            'href' => '/admin/bridge-analytics',
        ];
    }

    /**
     * Hosts whose heartbeat stopped.
     *
     * Drawn hatched rather than red, because this says nothing about the
     * services it reports on — the reporter died, and everything behind it is
     * *unknown*, which is neither up nor down and never opens an incident.
     *
     * @return array<int, array<string, mixed>>
     */
    private function silentHosts(): array
    {
        $rows = [];

        foreach (HeartbeatFleet::load()->all() as $host => $snapshot) {
            if (! $snapshot->stale()) {
                continue;
            }

            $rows[] = [
                'key' => 'host:'.$host,
                'severity' => 'unknown',
                'title' => 'watch.host.title',
                'body' => 'watch.host.body',
                'params' => [
                    'host' => (string) $host,
                    'minutes' => (int) round(($snapshot->ageSeconds() ?? 0) / 60),
                ],
                'value' => null,
                'value_kind' => 'text',
                'href' => '/crm/machines#hosts',
            ];
        }

        return $rows;
    }

    /**
     * The source that brought the best people this week, by activation.
     *
     * The one positive row in the list, and it earns its place: it is the only
     * thing here that answers "do more of what".
     *
     * @return array<string, mixed>|null
     */
    private function bestCampaign(): ?array
    {
        $filters = new AnalyticsFilters(
            from: Carbon::now('UTC')->subDays(7)->startOfDay(),
            to: Carbon::now('UTC'),
        );

        $rows = array_values(array_filter(
            $this->metrics->acquisition($filters, 8),
            fn (array $row) => $row['users'] >= 25 && $row['activation_rate'] !== null,
        ));

        if ($rows === []) {
            return null;
        }

        usort($rows, fn (array $a, array $b) => $b['activation_rate'] <=> $a['activation_rate']);

        $best = $rows[0];

        return [
            'key' => 'campaign:'.$best['source'].':'.$best['campaign'],
            'severity' => 'good',
            'title' => 'watch.campaign.title',
            'body' => 'watch.campaign.body',
            'params' => [
                'source' => $best['source'],
                'campaign' => $best['campaign'],
                'users' => $best['users'],
                'rate' => $best['activation_rate'],
            ],
            'value' => $best['activation_rate'],
            'value_kind' => 'percent',
            'href' => '/crm/numbers#sources',
        ];
    }

    /* ------------------------------------------------------------- quiet -- */

    /**
     * What an empty queue says.
     *
     * Never just an empty list: how long it has been quiet, when the last
     * sweep ran and how many services answered it. Without those three, a
     * quiet screen and a broken collector look identical, and the second one
     * is the dangerous one.
     *
     * @param  array<int, FeedItem>  $attention
     * @return array<string, mixed>
     */
    private function quiet(array $attention): array
    {
        $lastSweep = DB::table('service_checks')->max('checked_at');
        $lastIncident = ServiceIncident::query()->whereNotNull('resolved_at')->max('resolved_at');

        return [
            'is_quiet' => $attention === [],
            'since' => $lastIncident ? CarbonImmutable::parse($lastIncident)->toIso8601String() : null,
            'last_sweep' => $lastSweep ? CarbonImmutable::parse($lastSweep)->toIso8601String() : null,
            'answered' => (int) DB::table('service_checks')
                ->whereIn('id', ServiceCheck::latestIds())
                ->whereIn('status', ['up', 'degraded', 'down'])
                ->count(),
            'registered' => count($this->registry->all()),
        ];
    }

    /* ---------------------------------------------------------- the floor -- */

    /**
     * Thirty days of background, along the bottom.
     *
     * Every one of these has a source that cannot be replayed or inflated by
     * a browser: milestones on installations, rows in the bridge ledger, the
     * indexer's own swap log. Where a number cannot be read it is null and
     * renders as an em dash — never zero, which reads as an answer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tiles(): array
    {
        $filters = new AnalyticsFilters(
            from: Carbon::now('UTC')->subDays(30)->startOfDay(),
            to: Carbon::now('UTC'),
        );

        $previous = new AnalyticsFilters(
            from: Carbon::now('UTC')->subDays(60)->startOfDay(),
            to: Carbon::now('UTC')->subDays(30),
        );

        $funded = $this->metrics->weeklyActiveFundedUsers($filters);
        $installs = DB::table('analytics_users')->where('created_at', '>=', $filters->from)->count();
        $installsBefore = DB::table('analytics_users')
            ->whereBetween('created_at', [$previous->from, $previous->to])
            ->count();

        $daily = DB::table('analytics_users')
            ->where('created_at', '>=', $filters->from)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total')
            ->map(fn ($value) => (float) $value)
            ->all();

        $bridged = DB::table('bridge_requests')
            ->where('status', 'completed')
            ->where('created_at', '>=', $filters->from)
            ->count();

        $swaps = $this->swapVolume($filters);

        $services = $this->serviceCounts();

        return [
            [
                'key' => 'funded_active',
                'value' => $funded,
                'unit' => 'count',
                'tone' => 'accent',
                'note' => 'tiles.fundedActive.note',
                'params' => ['days' => (int) config('analytics.north_star_days', 7)],
                'spark' => null,
            ],
            [
                'key' => 'installs',
                'value' => $installs,
                'unit' => 'count',
                'tone' => 'plain',
                'note' => $installsBefore > 0 ? 'tiles.installs.delta' : 'tiles.installs.note',
                'params' => [
                    'delta' => $installsBefore > 0 ? round(($installs - $installsBefore) / $installsBefore * 100, 1) : 0,
                ],
                'spark' => $daily,
            ],
            [
                'key' => 'swaps',
                'value' => $swaps['usd'],
                'unit' => $swaps['usd'] === null ? 'none' : 'usd',
                'tone' => 'plain',
                'note' => $swaps['note'],
                'params' => $swaps['params'],
                'spark' => null,
            ],
            [
                'key' => 'bridge',
                'value' => $bridged,
                'unit' => 'count',
                'tone' => 'plain',
                'note' => 'tiles.bridge.note',
                'params' => ['failed' => DB::table('bridge_requests')
                    ->where('status', 'failed')
                    ->where('created_at', '>=', $filters->from)
                    ->count()],
                'spark' => null,
            ],
            [
                'key' => 'services',
                'value' => $services['healthy'],
                'of' => $services['total'],
                'unit' => 'fraction',
                'tone' => $services['down'] > 0 ? 'critical' : 'plain',
                'note' => 'tiles.services.note',
                'params' => $services,
                'spark' => null,
            ],
            [
                'key' => 'tasks',
                'value' => CrmTask::query()->active()->count(),
                'unit' => 'count',
                'tone' => 'plain',
                'note' => 'tiles.tasks.note',
                'params' => [
                    'overdue' => CrmTask::query()->overdue()->count(),
                    'unassigned' => CrmTask::query()->active()->whereNull('assigned_to_user_id')->count(),
                ],
                'spark' => null,
            ],
        ];
    }

    /**
     * Swap volume, from the chain's own indexer where it exists.
     *
     * `activity_events` is written by the Telegram bot from chain logs and is
     * the only place that knows about swaps made outside this wallet. When
     * the table is absent — a fresh install, a test database — the wallet's
     * own completed swaps stand in, and the note says which of the two is on
     * screen so the number is never read as more than it is.
     *
     * @return array{usd: float|null, note: string, params: array<string, string|int|float>}
     */
    private function swapVolume(AnalyticsFilters $filters): array
    {
        if (Schema::hasTable('activity_events')) {
            $row = DB::table('activity_events')
                ->where('kind', 'swap')
                ->where('created_at', '>=', $filters->from->format('Y-m-d H:i:s'))
                ->selectRaw('COUNT(*) as swaps, COALESCE(SUM(usd), 0) as usd')
                ->first();

            if ($row !== null && (int) $row->swaps > 0) {
                return [
                    'usd' => round((float) $row->usd, 2),
                    'note' => 'tiles.swaps.chain',
                    'params' => ['swaps' => (int) $row->swaps],
                ];
            }
        }

        $wallet = $this->metrics->overview($filters)['swap_volume_usd'] ?? null;

        return [
            'usd' => $wallet === null ? null : round((float) $wallet, 2),
            'note' => 'tiles.swaps.wallet',
            'params' => [],
        ];
    }

    /** @return array{healthy: int, total: int, down: int, degraded: int, unknown: int} */
    private function serviceCounts(): array
    {
        $counts = ['up' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0, 'off' => 0];
        $latest = [];

        foreach (ServiceCheck::query()->whereIn('id', ServiceCheck::latestIds())->get(['service', 'status']) as $check) {
            $latest[$check->service] = $check->status;
        }

        foreach (array_keys($this->registry->all()) as $key) {
            $status = $latest[$key] ?? 'unknown';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return [
            'healthy' => $counts['up'],
            'total' => count($this->registry->all()),
            'down' => $counts['down'],
            'degraded' => $counts['degraded'],
            'unknown' => $counts['unknown'],
        ];
    }

    private function window(): AnalyticsFilters
    {
        return new AnalyticsFilters(
            from: Carbon::now('UTC')->subDays(60)->startOfDay(),
            to: Carbon::now('UTC'),
        );
    }
}
