<?php

namespace App\Services\Analytics;

use App\Services\WalletPriceService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every number the product dashboard shows, computed here rather than in a
 * page or a query string.
 *
 * The point of putting them in one class is that the definitions stop being
 * negotiable. "Active user" is `EventTaxonomy::MEANINGFUL` and nothing else;
 * "funded" is a stamped milestone, never a balance read at report time; a
 * conversion is always distinct users, never event counts — six quotes are one
 * person who wanted a quote. A dashboard that redefines any of those in SQL is
 * a dashboard that will disagree with this one within a month.
 *
 * The queries are plain aggregates against the indexes the migration declares,
 * and the cohort arithmetic is done in PHP from two small reads — the same
 * shape `UserAnalyticsService` uses, for the same reason: this SQLite file is
 * shared with the Telegram bot, and portability beats window functions here.
 * Nothing is pre-aggregated yet, because nothing needs to be; the moment a
 * dashboard load stops being instant, the daily rollup goes in front of
 * `activeOverTime` and `retentionCohorts` and nowhere else.
 */
class ProductMetricsService
{
    public function __construct(private WalletPriceService $prices) {}

    /* ------------------------------------------------------------ scopes -- */

    /**
     * The population: installations that match the user-level filters.
     *
     * Not date-bounded — a cohort question ("who was acquired in March") and
     * an activity question ("who acted last week") bound it differently, so
     * each caller says which it means.
     */
    private function users(AnalyticsFilters $filters): Builder
    {
        $query = DB::table('analytics_users');

        if ($filters->platform !== null) {
            $query->where('platform', $filters->platform);
        }

        if ($filters->appVersion !== null) {
            $query->where('app_version', $filters->appVersion);
        }

        if ($filters->source !== null) {
            $query->where('source', $filters->source);
        }

        if ($filters->campaign !== null) {
            $query->where('campaign', $filters->campaign);
        }

        return $query;
    }

    /**
     * Events, narrowed to the population and to the chain.
     *
     * The population narrows through a subquery on the primary key rather than
     * a join, which keeps `(event, created_at)` usable as the driving index.
     */
    private function events(AnalyticsFilters $filters): Builder
    {
        $query = DB::table('analytics_events');

        if ($filters->narrowsUsers()) {
            $query->whereIn('user_id', $this->users($filters)->select('id'));
        }

        if ($filters->chain !== null) {
            $query->where('chain', $filters->chain);
        }

        return $query;
    }

    /** Distinct installations that did something meaningful in a window. */
    private function activeUsers(AnalyticsFilters $filters, Carbon $from, Carbon $to): Builder
    {
        return $this->events($filters)
            ->whereIn('event', EventTaxonomy::MEANINGFUL)
            ->whereBetween('created_at', [$from, $to]);
    }

    /* ---------------------------------------------------------- overview -- */

    /**
     * The headline row.
     *
     * @return array<string, mixed>
     */
    public function overview(AnalyticsFilters $filters): array
    {
        $acquired = $this->users($filters)
            ->whereBetween('created_at', [$filters->from, $filters->to]);

        $newUsers = (clone $acquired)->count();
        $wallets = (clone $acquired)->whereNotNull('wallet_created_at')->count();
        $funded = (clone $acquired)->whereNotNull('funded_at')->count();
        $activated = (clone $acquired)->whereNotNull('activated_at')->count();

        $window = fn (int $days): Builder => $this->activeUsers(
            $filters,
            $filters->to->copy()->subDays($days),
            $filters->to,
        );

        $transactions = $this->outcomeRate($filters, 'transaction');

        return [
            // The one number this whole system exists to produce.
            'north_star' => $this->weeklyActiveFundedUsers($filters),
            'new_users' => $newUsers,
            'wallets' => $wallets,
            'funded_users' => $funded,
            'activated_users' => $activated,
            'dau' => (clone $window(1))->distinct()->count('user_id'),
            'wau' => (clone $window(7))->distinct()->count('user_id'),
            'mau' => (clone $window(30))->distinct()->count('user_id'),
            'returning_users' => $this->returningUsers($filters),
            'activation_rate' => $this->rate($activated, $newUsers),
            'funded_rate' => $this->rate($funded, $newUsers),
            'transaction_success_rate' => $transactions['rate'],
            'error_rate' => $transactions['rate'] === null ? null : round(100 - $transactions['rate'], 1),
            'swap_volume_usd' => $this->volume($filters, 'swap_completed'),
            'bridge_volume_usd' => $this->volume($filters, 'bridge_completed'),
            'sponsored_gas_usd' => $this->gasSponsorship($filters)['total_usd'],
            'd7_retention' => $this->headlineRetention($filters),
        ];
    }

    /**
     * Weekly Active Funded Users — the North Star.
     *
     * Distinct installations that are funded *and* performed at least one
     * meaningful action in the last seven days. Both halves are load-bearing:
     * a funded wallet nobody uses is not a user of this product, and an active
     * wallet with no money in it is somebody looking around.
     *
     * Counted by `analytics_users.id`, never by address. One person with an
     * EVM account, a Solana account and two imported keys is one user; the
     * whole reason this system has an anonymous id at all is that counting
     * addresses would quietly multiply them.
     *
     *   SELECT COUNT(DISTINCT e.user_id)
     *     FROM analytics_events e
     *     JOIN analytics_users u ON u.id = e.user_id
     *    WHERE u.funded_at IS NOT NULL
     *      AND e.event IN (<meaningful>)
     *      AND e.created_at >= :to - 7 days
     */
    public function weeklyActiveFundedUsers(AnalyticsFilters $filters): int
    {
        $days = (int) config('analytics.north_star_days', 7);

        return $this->activeUsers($filters, $filters->to->copy()->subDays($days), $filters->to)
            ->whereIn('user_id', $this->users($filters)->whereNotNull('funded_at')->select('id'))
            ->distinct()
            ->count('user_id');
    }

    /**
     * Installations active in the window that were first seen before it — the
     * closest thing to loyalty a single window supports.
     */
    private function returningUsers(AnalyticsFilters $filters): int
    {
        return $this->activeUsers($filters, $filters->from, $filters->to)
            ->whereIn(
                'user_id',
                $this->users($filters)->where('first_seen_at', '<', $filters->from)->select('id'),
            )
            ->distinct()
            ->count('user_id');
    }

    /* ------------------------------------------------------- time series -- */

    /**
     * Active users per day, in both senses, because the gap between them is
     * the product question: how many people opened the wallet, and how many
     * of them actually did something.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeOverTime(AnalyticsFilters $filters): array
    {
        $opened = $this->dailyDistinctUsers($this->events($filters), $filters);
        $active = $this->dailyDistinctUsers(
            $this->events($filters)->whereIn('event', EventTaxonomy::MEANINGFUL),
            $filters,
        );

        $newUsers = $this->users($filters)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();

        $rows = [];

        for ($day = $filters->from->copy()->startOfDay(); $day->lessThanOrEqualTo($filters->to); $day->addDay()) {
            $key = $day->toDateString();

            $rows[] = [
                'day' => $key,
                'opened' => (int) ($opened[$key] ?? 0),
                'active' => (int) ($active[$key] ?? 0),
                'new' => (int) ($newUsers[$key] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function dailyDistinctUsers(Builder $query, AnalyticsFilters $filters): array
    {
        return $query
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->selectRaw('DATE(created_at) as day, COUNT(DISTINCT user_id) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();
    }

    /* ------------------------------------------------------------ funnel -- */

    /**
     * The main funnel, measured on the cohort acquired inside the window.
     *
     * It is a cohort rather than five independent counts on purpose: counting
     * "wallets created in the window" against "users first seen in the window"
     * mixes people who arrived last month into the numerator and makes every
     * conversion above 100% eventually.
     *
     * "Retained" is a meaningful action at least a day after activation — the
     * one step that cannot be satisfied in a single sitting, and the one that
     * needs no maturity window to be honest.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mainFunnel(AnalyticsFilters $filters): array
    {
        $cohort = fn (): Builder => $this->users($filters)
            ->whereBetween('created_at', [$filters->from, $filters->to]);

        $first = $cohort()->count();
        $wallets = $cohort()->whereNotNull('wallet_created_at')->count();
        $funded = $cohort()->whereNotNull('funded_at')->count();
        $activated = $cohort()->whereNotNull('activated_at')->count();

        $retained = $cohort()
            ->whereNotNull('activated_at')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('analytics_events')
                    ->whereColumn('analytics_events.user_id', 'analytics_users.id')
                    ->whereIn('analytics_events.event', EventTaxonomy::MEANINGFUL)
                    ->whereRaw("analytics_events.created_at >= datetime(analytics_users.activated_at, '+1 day')");
            })
            ->count();

        return $this->steps([
            ['key' => 'first_open', 'value' => $first],
            ['key' => 'wallet', 'value' => $wallets],
            ['key' => 'funded', 'value' => $funded],
            ['key' => 'activated', 'value' => $activated],
            ['key' => 'retained', 'value' => $retained],
        ]);
    }

    /**
     * The product funnels named in the taxonomy, in distinct users per step.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function productFunnels(AnalyticsFilters $filters): array
    {
        $funnels = [];

        foreach (EventTaxonomy::FUNNELS as $name => $events) {
            $steps = [];

            foreach ($events as $event) {
                $steps[] = [
                    'key' => $event,
                    'value' => $this->events($filters)
                        ->where('event', $event)
                        ->whereBetween('created_at', [$filters->from, $filters->to])
                        ->distinct()
                        ->count('user_id'),
                ];
            }

            $funnels[$name] = $this->steps($steps);
        }

        return $funnels;
    }

    /**
     * Attach absolute-to-top and step-to-step conversion to a list of counts.
     *
     * @param  array<int, array{key: string, value: int}>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function steps(array $steps): array
    {
        $top = $steps[0]['value'] ?? 0;
        $previous = null;

        return array_map(function (array $step) use ($top, &$previous): array {
            $row = [
                'key' => $step['key'],
                'value' => $step['value'],
                'of_top' => $this->rate($step['value'], $top),
                'of_previous' => $previous === null ? null : $this->rate($step['value'], $previous),
            ];

            $previous = $step['value'];

            return $row;
        }, $steps);
    }

    /* -------------------------------------------------------- activation -- */

    /**
     * How well and how fast the product turns an installation into a user.
     *
     * The medians are over the cohort acquired in the window, and only over
     * the members who reached the milestone — a median that counted people who
     * never funded as "infinity" would not be a median of anything.
     *
     * @return array<string, mixed>
     */
    public function activation(AnalyticsFilters $filters): array
    {
        $cohort = fn (): Builder => $this->users($filters)
            ->whereBetween('created_at', [$filters->from, $filters->to]);

        $total = $cohort()->count();

        return [
            'cohort' => $total,
            'funded' => $cohort()->whereNotNull('funded_at')->count(),
            'activated' => $cohort()->whereNotNull('activated_at')->count(),
            'funded_rate' => $this->rate($cohort()->whereNotNull('funded_at')->count(), $total),
            'activation_rate' => $this->rate($cohort()->whereNotNull('activated_at')->count(), $total),
            'median_seconds_to_funding' => $this->medianGap($cohort(), 'funded_at'),
            'median_seconds_to_first_transaction' => $this->medianGap($cohort(), 'first_transaction_at'),
            // Kept apart because they mean different things: one was read off
            // a chain by this server, the other is a browser's word for it.
            'funded_onchain' => $cohort()->where('funded_source', 'onchain')->count(),
            'funded_claimed' => $cohort()->where('funded_source', 'client')->count(),
        ];
    }

    /** Median seconds from first_seen_at to a milestone column. */
    private function medianGap(Builder $cohort, string $column): ?int
    {
        $gaps = $cohort
            ->whereNotNull($column)
            ->selectRaw("(julianday($column) - julianday(first_seen_at)) * 86400 as gap")
            ->pluck('gap')
            ->map(fn ($value) => (int) round((float) $value))
            ->filter(fn (int $value) => $value >= 0)
            ->sort()
            ->values();

        if ($gaps->isEmpty()) {
            return null;
        }

        $middle = intdiv($gaps->count(), 2);

        return $gaps->count() % 2 === 1
            ? $gaps[$middle]
            : (int) round(($gaps[$middle - 1] + $gaps[$middle]) / 2);
    }

    /* --------------------------------------------------------- retention -- */

    /**
     * Retention cohorts keyed on the week a user *activated*, not the week
     * they arrived: this product's question is whether people who did
     * something once do it again, and an install that never activated has
     * nothing to be retained from.
     *
     * "Returned by day N" — any meaningful action from the day after
     * activation through day N — matching `UserAnalyticsService` so the two
     * dashboards in this app never report retention two different ways. A
     * bucket the cohort is too young to have reached is reported as null
     * rather than as zero.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retentionCohorts(AnalyticsFilters $filters, int $weeks = 8): array
    {
        $buckets = (array) config('analytics.retention_buckets', [1, 7, 30]);
        $now = Carbon::now('UTC');
        $start = $filters->to->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($weeks - 1);

        $members = $this->users($filters)
            ->whereNotNull('activated_at')
            ->where('activated_at', '>=', $start)
            ->where('activated_at', '<=', $filters->to)
            ->pluck('activated_at', 'id');

        if ($members->isEmpty()) {
            return [];
        }

        $activeDays = $this->meaningfulDaysFor($members->keys()->all(), $start, $filters);

        $cohorts = [];

        foreach ($members as $id => $activatedAt) {
            $activated = Carbon::parse($activatedAt, 'UTC');
            $week = $activated->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

            $cohorts[$week] ??= [
                'week' => $week,
                'size' => 0,
                'returned' => array_fill_keys($buckets, 0),
            ];
            $cohorts[$week]['size']++;

            foreach ($buckets as $bucket) {
                for ($offset = 1; $offset <= $bucket; $offset++) {
                    $day = $activated->copy()->addDays($offset)->toDateString();

                    if (isset($activeDays[$id][$day])) {
                        $cohorts[$week]['returned'][$bucket]++;
                        break;
                    }
                }
            }
        }

        krsort($cohorts);

        return array_values(array_map(function (array $cohort) use ($buckets, $now): array {
            $weekStart = Carbon::parse($cohort['week'], 'UTC');
            $rates = [];

            foreach ($buckets as $bucket) {
                // The youngest member gates the bucket: a week that is not
                // fully aged would report a rate that only goes up.
                $matured = $weekStart->copy()->addDays(6 + $bucket)->lessThanOrEqualTo($now);

                $rates['d'.$bucket] = $matured ? $this->rate($cohort['returned'][$bucket], $cohort['size']) : null;
            }

            return ['week' => $cohort['week'], 'size' => $cohort['size'], 'rates' => $rates];
        }, $cohorts));
    }

    /**
     * user id => set of UTC dates with a meaningful action.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array<string, bool>>
     */
    private function meaningfulDaysFor(array $ids, Carbon $since, AnalyticsFilters $filters): array
    {
        $days = [];

        // Chunked to stay clear of SQLite's bound-variable limit.
        foreach (array_chunk($ids, 400) as $chunk) {
            $rows = $this->events($filters)
                ->whereIn('user_id', $chunk)
                ->whereIn('event', EventTaxonomy::MEANINGFUL)
                ->where('created_at', '>=', $since)
                ->selectRaw('user_id, DATE(created_at) as day')
                ->distinct()
                ->get();

            foreach ($rows as $row) {
                $days[$row->user_id][$row->day] = true;
            }
        }

        return $days;
    }

    /** The dashboard's single retention figure: the newest matured D7 cohort. */
    private function headlineRetention(AnalyticsFilters $filters): ?float
    {
        foreach ($this->retentionCohorts($filters) as $cohort) {
            if ($cohort['rates']['d7'] !== null) {
                return $cohort['rates']['d7'];
            }
        }

        return null;
    }

    /* ------------------------------------------------------- acquisition -- */

    /**
     * Per source and campaign: not how much traffic it sent, but how much of
     * that traffic turned into someone who uses the wallet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function acquisition(AnalyticsFilters $filters, int $limit = 25): array
    {
        $rows = $this->users($filters)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->selectRaw("
                COALESCE(source, 'direct') as source,
                COALESCE(campaign, '—') as campaign,
                COUNT(*) as users,
                SUM(CASE WHEN wallet_created_at IS NOT NULL THEN 1 ELSE 0 END) as wallets,
                SUM(CASE WHEN funded_at IS NOT NULL THEN 1 ELSE 0 END) as funded,
                SUM(CASE WHEN activated_at IS NOT NULL THEN 1 ELSE 0 END) as activated
            ")
            ->groupBy('source', 'campaign')
            ->orderByDesc('users')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) use ($filters): array {
            $scoped = new AnalyticsFilters(
                from: $filters->from,
                to: $filters->to,
                platform: $filters->platform,
                appVersion: $filters->appVersion,
                source: $row->source === 'direct' ? null : $row->source,
                campaign: $row->campaign === '—' ? null : $row->campaign,
                chain: $filters->chain,
            );

            $retention = $this->retentionCohorts($scoped);

            return [
                'source' => $row->source,
                'campaign' => $row->campaign,
                'users' => (int) $row->users,
                'wallets' => (int) $row->wallets,
                'funded' => (int) $row->funded,
                'activated' => (int) $row->activated,
                'activation_rate' => $this->rate((int) $row->activated, (int) $row->users),
                'd1' => $this->firstMatured($retention, 'd1'),
                'd7' => $this->firstMatured($retention, 'd7'),
            ];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $cohorts
     */
    private function firstMatured(array $cohorts, string $bucket): ?float
    {
        foreach ($cohorts as $cohort) {
            if (($cohort['rates'][$bucket] ?? null) !== null) {
                return $cohort['rates'][$bucket];
            }
        }

        return null;
    }

    /* ----------------------------------------------------------- product -- */

    /**
     * What people actually do with the wallet: users, volume and how often it
     * worked, per feature.
     *
     * @return array<int, array<string, mixed>>
     */
    public function productUsage(AnalyticsFilters $filters): array
    {
        $features = [
            'swap' => ['event' => 'swap_completed', 'outcome' => 'swap'],
            'bridge' => ['event' => 'bridge_completed', 'outcome' => 'bridge'],
            'send' => ['event' => 'transaction_confirmed', 'outcome' => 'transaction'],
            'staking' => ['event' => 'staking_completed', 'outcome' => 'staking'],
            'liquidity' => ['event' => 'liquidity_added', 'outcome' => null],
            'nft' => ['event' => 'nft_minted', 'outcome' => 'nft'],
        ];

        $rows = [];

        foreach ($features as $name => $spec) {
            $completed = $this->events($filters)
                ->where('event', $spec['event'])
                ->whereBetween('created_at', [$filters->from, $filters->to]);

            $outcome = $spec['outcome'] === null
                ? ['rate' => null, 'attempts' => 0, 'failures' => 0]
                : $this->outcomeRate($filters, $spec['outcome']);

            $rows[] = [
                'feature' => $name,
                'users' => (clone $completed)->distinct()->count('user_id'),
                'actions' => (clone $completed)->count(),
                'volume_usd' => $this->volume($filters, $spec['event']),
                'success_rate' => $outcome['rate'],
                'failures' => $outcome['failures'],
            ];
        }

        return $rows;
    }

    /**
     * Success rate for one outcome pair.
     *
     * The denominator starts where the user committed — a signature, a
     * broadcast deposit, a claim request — not where they opened a screen. An
     * abandoned quote is not a failed swap, and folding it in would make the
     * number say "people change their minds" instead of "this breaks".
     *
     * @return array{rate: ?float, attempts: int, failures: int}
     */
    public function outcomeRate(AnalyticsFilters $filters, string $name): array
    {
        $pair = EventTaxonomy::OUTCOMES[$name] ?? null;

        if ($pair === null) {
            return ['rate' => null, 'attempts' => 0, 'failures' => 0];
        }

        $count = fn (string $event): int => $this->events($filters)
            ->where('event', $event)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->count();

        $success = $count($pair['success']);
        $failure = $count($pair['failure']);
        $total = $success + $failure;

        return [
            'rate' => $this->rate($success, $total),
            'attempts' => $total,
            'failures' => $failure,
        ];
    }

    /** USD moved by one event type, over the rows that carried a price. */
    private function volume(AnalyticsFilters $filters, string $event): float
    {
        $sum = $this->events($filters)
            ->where('event', $event)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->selectRaw("COALESCE(SUM(CAST(json_extract(properties, '$.amount_usd') AS REAL)), 0) as total")
            ->value('total');

        return round((float) $sum, 2);
    }

    /**
     * Failures, grouped so they can be acted on: which step, which code, how
     * many people. A raw message is never stored, which is what makes this
     * groupable at all.
     *
     * @return array<int, array<string, mixed>>
     */
    public function errors(AnalyticsFilters $filters, int $limit = 25): array
    {
        return $this->events($filters)
            ->whereIn('event', EventTaxonomy::FAILURES)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->selectRaw("
                event,
                COALESCE(json_extract(properties, '$.error_code'), 'unknown') as error_code,
                COUNT(*) as total,
                COUNT(DISTINCT user_id) as users
            ")
            ->groupBy('event', 'error_code')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'event' => $row->event,
                'error_code' => $row->error_code,
                'total' => (int) $row->total,
                'users' => (int) $row->users,
            ])
            ->all();
    }

    /* --------------------------------------------------- sponsored fees -- */

    /**
     * What sponsorship costs and what it buys.
     *
     * The cost side is not read from events at all. `gas_sponsorships` is
     * written by the server that actually signed the drip, with the amount the
     * contract actually released — a browser could neither know nor be trusted
     * with that number, and a retried client event must never be able to add
     * a cent to a spend report. The client events only supply the funnel:
     * how many asked, and why the ones who were refused were refused.
     *
     * Per-user cost needs the address link, which exists for exactly this and
     * for funding verification. A drip to an address no installation reported
     * still counts in the total, and simply has no user to attribute it to.
     *
     * @return array<string, mixed>
     */
    public function gasSponsorship(AnalyticsFilters $filters): array
    {
        $drips = DB::table('gas_sponsorships')
            ->whereBetween('created_at', [$filters->from, $filters->to]);

        $rows = (clone $drips)->get(['address', 'amount_wei', 'grounds']);

        $totalWei = '0';

        foreach ($rows as $row) {
            $amount = (string) $row->amount_wei;

            if (preg_match('/^\d+$/', $amount) === 1) {
                $totalWei = bcadd($totalWei, $amount);
            }
        }

        $price = $this->prices->quotes()['prices']['cyberia'] ?? null;
        $cyber = (float) bcdiv($totalWei, bcpow('10', '18', 0), 18);
        $totalUsd = $price === null ? null : round($cyber * $price, 4);

        // Distinct installations behind those addresses — the denominator that
        // makes "cost per sponsored user" mean a person rather than a key.
        $addresses = $rows->pluck('address')->map(fn ($a) => mb_strtolower((string) $a))->unique()->values();

        $sponsoredUsers = $addresses->isEmpty() ? 0 : DB::table('analytics_addresses')
            ->whereIn('address', $addresses->all())
            ->when(
                $filters->narrowsUsers(),
                fn ($query) => $query->whereIn('user_id', $this->users($filters)->select('id')),
            )
            ->distinct()
            ->count('user_id');

        $activated = $this->users($filters)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->whereNotNull('activated_at')
            ->count();

        $funnel = $this->outcomeRate($filters, 'gas');

        return [
            'transactions' => $rows->count(),
            'addresses' => $addresses->count(),
            'sponsored_users' => $sponsoredUsers,
            'total_cyber' => round($cyber, 6),
            'total_usd' => $totalUsd,
            'cyber_price' => $price,
            'usd_per_sponsored_user' => $totalUsd !== null && $sponsoredUsers > 0
                ? round($totalUsd / $sponsoredUsers, 4)
                : null,
            'usd_per_activated_user' => $totalUsd !== null && $activated > 0
                ? round($totalUsd / $activated, 4)
                : null,
            'requested' => $funnel['attempts'],
            'failed' => $funnel['failures'],
            'success_rate' => $funnel['rate'],
            'grounds' => $rows->groupBy('grounds')->map->count()->all(),
        ];
    }

    /* ------------------------------------------------------------ filters -- */

    /**
     * The values each filter can actually take, read from the data so the
     * dashboard never offers a platform nobody uses.
     *
     * @return array<string, array<int, string>>
     */
    public function filterOptions(): array
    {
        $distinct = fn (string $column): array => DB::table('analytics_users')
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->limit(50)
            ->pluck($column)
            ->all();

        return [
            'platforms' => $distinct('platform'),
            'app_versions' => $distinct('app_version'),
            'sources' => $distinct('source'),
            'campaigns' => $distinct('campaign'),
            'chains' => DB::table('analytics_events')
                ->whereNotNull('chain')
                ->distinct()
                ->orderBy('chain')
                ->limit(50)
                ->pluck('chain')
                ->all(),
        ];
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
