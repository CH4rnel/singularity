<?php

namespace App\Services;

use App\Models\SiteEvent;
use App\Models\User;
use App\Models\UserQuest;
use App\Models\UserStat;
use App\Models\XpEntry;
use App\Notifications\ProgressNotification;
use App\Support\ProfileHandle;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Off-chain progression: XP, levels, daily streaks and repeating quests.
 *
 * This is the "why come back tomorrow" layer, and it deliberately sits beside
 * the permanent on-chain badges in AchievementService rather than replacing
 * them: badges are rare, verifiable and forever; XP is frequent, cheap and
 * resettable, which is exactly what a habit loop needs.
 *
 * Everything is paid through the append-only xp_entries ledger, keyed by
 * (source, reference), which is what makes the whole thing forgery-resistant
 * and safe to recompute. The browser can only report visits and page views —
 * worth a day-capped visit award and nothing else. Value actions are credited
 * from ground truth instead: swaps and liquidity from the Telegram bot's
 * on-chain indexer (keyed by tx hash), bridges from bridge_requests, and
 * governance from the DAO tables at the moment the row is written. Rerunning
 * `gamification:sync` is therefore always a no-op for already-paid actions.
 *
 * All calendar maths is UTC so a streak means the same thing everywhere.
 */
class GamificationService
{
    /** Actions that only advance quests and never pay XP on their own. */
    private const QUEST_ONLY_ACTIONS = ['page_view'];

    /**
     * Record a user action: refresh the streak, pay the XP it is worth and
     * advance every quest that action feeds.
     *
     * @param  string  $action  an `xp` key from config/gamification.php, or
     *                          `page_view`
     * @param  string  $reference  natural key of the underlying event (tx hash,
     *                             row id). Empty means "day-stamp it", which
     *                             caps the award at one per UTC day.
     * @param  string|null  $page  path, for quests that count distinct pages
     * @return int XP actually granted (0 when everything was already paid)
     */
    public function recordAction(
        User $user,
        string $action,
        string $reference = '',
        ?CarbonInterface $at = null,
        ?string $page = null,
    ): int {
        $at = $this->utc($at);

        $granted = 0;

        if (! in_array($action, self::QUEST_ONLY_ACTIONS, true)) {
            $amount = (int) config("gamification.xp.{$action}", 0);

            if ($amount > 0) {
                $granted += $this->award(
                    $user,
                    $action,
                    $reference !== '' ? $reference : 'd:'.$at->toDateString(),
                    $amount,
                );
            }
        }

        $granted += $this->touch($user, $at);
        $granted += $this->advanceQuests($user, $action, $at, $page);

        return $granted;
    }

    /**
     * Mark the user active today, extending or restarting the streak and
     * paying any milestone bonus. Idempotent within a UTC day.
     *
     * @return int XP granted (visit award + streak bonus)
     */
    public function touch(User $user, ?CarbonInterface $at = null): int
    {
        $at = $this->utc($at);
        $today = $at->toDateString();
        $stats = $this->statsFor($user);

        if ($stats->last_active_on?->toDateString() === $today) {
            return 0;
        }

        $wasYesterday = $stats->last_active_on?->toDateString() === $at->copy()->subDay()->toDateString();

        $stats->current_streak = $wasYesterday ? $stats->current_streak + 1 : 1;
        $stats->longest_streak = max($stats->longest_streak, $stats->current_streak);
        $stats->streak_started_on = $wasYesterday
            ? ($stats->streak_started_on ?? $at)
            : $at;
        $stats->last_active_on = $at;
        $stats->save();

        $granted = $this->award($user, 'visit', 'd:'.$today, (int) config('gamification.xp.visit', 0));

        $bonus = (int) (config('gamification.streak_bonuses')[$stats->current_streak] ?? 0);

        if ($bonus > 0) {
            // Keyed by the run it belongs to, so a rebuilt streak can earn the
            // same milestone again — that is the point of restarting one.
            $reference = $stats->streak_started_on->toDateString().':'.$stats->current_streak;
            $granted += $this->award($user, 'streak', $reference, $bonus);
        }

        /*
         * "Open Cyberia today" is this moment and no other: the first activity
         * of a UTC day is exactly what a daily visit is, and this is the only
         * place that knows it.
         *
         * Without this the very first quest a new person is shown could never
         * be completed by anyone. `visit` XP is paid here, but quests were
         * advanced only from recordAction(), whose sole caller reports
         * `page_view` — so on prod there were 86 visit awards and not one
         * daily_visit row. Advancing again from recordAction() is harmless:
         * a completed quest short-circuits.
         */
        $granted += $this->advanceQuests($user, 'visit', $at, null);

        return $granted;
    }

    /**
     * Pay XP once for a given (source, reference). Returns the amount granted,
     * or 0 when the ledger already had that entry.
     */
    public function award(User $user, string $source, string $reference, int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        // insertOrIgnore leans on the unique index, so concurrent callers
        // cannot double-pay the same action.
        $inserted = XpEntry::query()->insertOrIgnore([
            'user_id' => $user->id,
            'source' => $source,
            'reference' => $reference,
            'amount' => $amount,
            'created_at' => Carbon::now('UTC'),
        ]);

        if ($inserted === 0) {
            return 0;
        }

        $stats = $this->statsFor($user);
        $before = $stats->level;

        $stats->xp += $amount;
        $stats->level = $this->levelFor($stats->xp);
        $stats->save();

        if ($stats->level > $before) {
            $this->notifyLevelUp($user, $stats->level);
        }

        return $amount;
    }

    public function statsFor(User $user): UserStat
    {
        return UserStat::firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Highest level whose cumulative XP requirement is met.
     */
    public function levelFor(int $xp): int
    {
        $step = max(1, (int) config('gamification.level_step', 50));
        // cumulative(L) = step * (L - 1) * L  =>  L = (1 + sqrt(1 + 4*xp/step)) / 2
        $level = (int) floor((1 + sqrt(1 + 4 * max(0, $xp) / $step)) / 2);

        return max(1, min($level, (int) config('gamification.max_level', 50)));
    }

    /** Cumulative XP required to reach a level. */
    public function xpForLevel(int $level): int
    {
        $step = (int) config('gamification.level_step', 50);

        return $step * max(0, $level - 1) * max(0, $level);
    }

    public function titleFor(int $level): string
    {
        $titles = config('gamification.titles', []);
        $title = 'Lurker';

        foreach ($titles as $from => $name) {
            if ($level >= (int) $from) {
                $title = $name;
            }
        }

        return $title;
    }

    /**
     * Everything the profile page needs: level, streak, live quest board and
     * the last few ledger entries.
     *
     * @return array<string, mixed>
     */
    public function progressFor(User $user): array
    {
        $stats = $this->statsFor($user);
        $level = $stats->level;
        $max = (int) config('gamification.max_level', 50);
        $floor = $this->xpForLevel($level);
        $ceiling = $level >= $max ? null : $this->xpForLevel($level + 1);

        return [
            'xp' => $stats->xp,
            'level' => $level,
            'title' => $this->titleFor($level),
            'level_floor_xp' => $floor,
            'next_level_xp' => $ceiling,
            'progress_pct' => $ceiling === null || $ceiling === $floor
                ? 100
                : (int) round(($stats->xp - $floor) / ($ceiling - $floor) * 100),
            'current_streak' => $stats->current_streak,
            'longest_streak' => $stats->longest_streak,
            'last_active_on' => $stats->last_active_on?->toDateString(),
            'active_today' => $stats->last_active_on?->toDateString() === Carbon::now('UTC')->toDateString(),
            'rank' => $this->rankOf($stats),
            'quests' => $this->questBoard($user),
            'recent' => XpEntry::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(8)
                ->get(['source', 'reference', 'amount', 'created_at'])
                ->map(fn (XpEntry $entry) => [
                    'source' => $entry->source,
                    'amount' => $entry->amount,
                    'created_at' => $entry->created_at?->toISOString(),
                ])
                ->all(),
        ];
    }

    /**
     * Public progress summary for someone else's profile — no quest board,
     * no ledger.
     *
     * @return array<string, mixed>
     */
    public function publicProgressFor(User $user): array
    {
        $stats = UserStat::query()->where('user_id', $user->id)->first();

        if (! $stats) {
            return ['xp' => 0, 'level' => 1, 'title' => $this->titleFor(1), 'current_streak' => 0, 'longest_streak' => 0, 'rank' => null];
        }

        return [
            'xp' => $stats->xp,
            'level' => $stats->level,
            'title' => $this->titleFor($stats->level),
            'current_streak' => $stats->current_streak,
            'longest_streak' => $stats->longest_streak,
            'rank' => $this->rankOf($stats),
        ];
    }

    /**
     * Leaderboard rows, highest XP first. Merged-away accounts are excluded so
     * one person never appears twice.
     *
     * @return array<int, array<string, mixed>>
     */
    public function leaderboard(int $limit = 50): array
    {
        return UserStat::query()
            ->join('users', 'users.id', '=', 'user_stats.user_id')
            ->whereNull('users.merged_into_id')
            ->where('user_stats.xp', '>', 0)
            ->orderByDesc('user_stats.xp')
            ->orderBy('user_stats.user_id')
            ->limit($limit)
            ->get([
                'users.id as user_id',
                'users.name',
                'users.onchain_nickname',
                'users.avatar_path',
                'users.wallet_address',
                'user_stats.xp',
                'user_stats.level',
                'user_stats.current_streak',
            ])
            ->values()
            ->map(fn ($row, int $index) => [
                'position' => $index + 1,
                'user_id' => (int) $row->user_id,
                'name' => $row->onchain_nickname ?: $row->name,
                'avatar' => $row->avatar_path
                    ? Storage::disk('public')->url($row->avatar_path)
                    : null,
                'profile_url' => ProfileHandle::url(
                    (int) $row->user_id,
                    $row->onchain_nickname,
                ),
                'wallet_address' => $row->wallet_address,
                'xp' => (int) $row->xp,
                'level' => (int) $row->level,
                'title' => $this->titleFor((int) $row->level),
                'current_streak' => (int) $row->current_streak,
            ])
            ->all();
    }

    /**
     * The user's live quest board for the current day and week.
     *
     * @return array<int, array<string, mixed>>
     */
    public function questBoard(User $user): array
    {
        $now = Carbon::now('UTC');

        $rows = UserQuest::query()
            ->where('user_id', $user->id)
            ->whereIn('period_key', [$this->periodKey('daily', $now), $this->periodKey('weekly', $now)])
            ->get()
            ->keyBy(fn (UserQuest $quest) => $quest->quest_key.'@'.$quest->period_key);

        return array_map(function (array $quest) use ($rows, $now) {
            $periodKey = $this->periodKey($quest['period'], $now);
            $row = $rows->get($quest['key'].'@'.$periodKey);

            return [
                'key' => $quest['key'],
                'period' => $quest['period'],
                'title' => $quest['title'],
                'description' => $quest['description'],
                'target' => (int) $quest['target'],
                'progress' => min((int) ($row->progress ?? 0), (int) $quest['target']),
                'completed' => $row?->completed_at !== null,
                'xp' => (int) $quest['xp'],
            ];
        }, config('gamification.quests', []));
    }

    /**
     * Advance every quest fed by this action and pay out the ones that just
     * completed.
     */
    private function advanceQuests(User $user, string $action, CarbonInterface $at, ?string $page): int
    {
        $granted = 0;

        foreach (config('gamification.quests', []) as $quest) {
            if (! in_array($action, $quest['actions'], true)) {
                continue;
            }

            $periodKey = $this->periodKey($quest['period'], $at);
            $row = UserQuest::firstOrCreate(
                ['user_id' => $user->id, 'quest_key' => $quest['key'], 'period_key' => $periodKey],
                ['progress' => 0, 'target' => (int) $quest['target']],
            );

            if ($row->completed_at !== null) {
                continue;
            }

            $row->progress = ($quest['distinct_pages'] ?? false)
                ? $this->distinctPagesToday($user, $at, $page)
                : $row->progress + 1;

            if ($row->progress >= $row->target) {
                $row->progress = $row->target;
                $row->completed_at = Carbon::now('UTC');
            }

            $row->save();

            if ($row->completed_at !== null) {
                $granted += $this->award($user, 'quest', $quest['key'].':'.$periodKey, (int) $quest['xp']);
                $this->notifyQuestComplete($user, $quest);
            }
        }

        return $granted;
    }

    /**
     * How many different pages the user has opened today. Counted from
     * site_events rather than a per-quest counter so a reload of the same page
     * never advances the quest.
     */
    private function distinctPagesToday(User $user, CarbonInterface $at, ?string $page): int
    {
        $pages = SiteEvent::query()
            ->where('user_id', $user->id)
            ->whereNotNull('page')
            ->whereBetween('created_at', [
                $this->utc($at)->startOfDay(),
                $this->utc($at)->endOfDay(),
            ])
            ->distinct()
            ->pluck('page')
            ->all();

        // The event that triggered this call may not be committed yet.
        if ($page !== null && ! in_array($page, $pages, true)) {
            $pages[] = $page;
        }

        return count($pages);
    }

    private function periodKey(string $period, CarbonInterface $at): string
    {
        $at = $this->utc($at);

        return $period === 'weekly' ? $at->format('o-\WW') : $at->toDateString();
    }

    /** 1-based leaderboard position, or null when the user has no XP yet. */
    private function rankOf(UserStat $stats): ?int
    {
        if ($stats->xp <= 0) {
            return null;
        }

        return 1 + UserStat::query()
            ->join('users', 'users.id', '=', 'user_stats.user_id')
            ->whereNull('users.merged_into_id')
            ->where(function ($query) use ($stats) {
                $query->where('user_stats.xp', '>', $stats->xp)
                    ->orWhere(function ($tie) use ($stats) {
                        $tie->where('user_stats.xp', $stats->xp)
                            ->where('user_stats.user_id', '<', $stats->user_id);
                    });
            })
            ->count();
    }

    private function notifyLevelUp(User $user, int $level): void
    {
        $user->notify(new ProgressNotification(
            type: 'progress.level_up',
            title: "Level {$level} — ".$this->titleFor($level),
            body: 'Your Cyberia rank went up. Keep the streak alive.',
            url: '/profile',
        ));
    }

    /**
     * @param  array<string, mixed>  $quest
     */
    private function notifyQuestComplete(User $user, array $quest): void
    {
        $user->notify(new ProgressNotification(
            type: 'progress.quest_completed',
            title: 'Quest complete: '.($quest['title']['en'] ?? $quest['key']),
            body: '+'.$quest['xp'].' XP',
            url: '/profile',
        ));
    }

    /**
     * Backfill XP from everything the platform already recorded: the on-chain
     * indexer feed, completed bridges and DAO participation. Idempotent, so it
     * can run on a schedule and after any data repair.
     *
     * @return array<string, int> XP granted per source
     */
    public function backfill(User $user): array
    {
        return [
            'onchain' => $this->backfillOnchain($user),
            'bridge' => $this->backfillBridges($user),
            'governance' => $this->backfillGovernance($user),
        ];
    }

    private function backfillOnchain(User $user): int
    {
        $wallet = strtolower((string) $user->wallet_address);

        if ($wallet === '' || ! $this->hasTable('activity_events')) {
            return 0;
        }

        $kinds = [
            'swap' => 'swap',
            'liq_add' => 'liquidity',
            'convert' => 'convert',
            'stake' => 'staking',
        ];

        $granted = 0;

        DB::table('activity_events')
            ->whereRaw('LOWER(user_addr) = ?', [$wallet])
            ->whereNotNull('tx_hash')
            ->orderBy('id')
            ->select(['kind', 'tx_hash', 'created_at'])
            ->chunk(500, function ($rows) use ($user, $kinds, &$granted) {
                foreach ($rows as $row) {
                    $action = str_starts_with((string) $row->kind, 'lend_')
                        ? 'lending'
                        : ($kinds[$row->kind] ?? null);

                    if ($action === null) {
                        continue;
                    }

                    $granted += $this->awardHistoric(
                        $user,
                        $action,
                        (string) $row->tx_hash,
                        $this->parseTimestamp($row->created_at),
                    );
                }
            });

        return $granted;
    }

    private function backfillBridges(User $user): int
    {
        $addresses = array_values(array_filter([
            strtolower((string) $user->wallet_address),
            strtolower((string) $user->solana_wallet_address),
        ]));

        $granted = 0;

        DB::table('bridge_requests')
            ->where('status', 'completed')
            ->where(function ($query) use ($user, $addresses) {
                // Signed-in transfers carry a user_id; wallet-only ones are
                // matched by either side of the transfer.
                $query->where('user_id', $user->id);

                if ($addresses !== []) {
                    $query->orWhereIn(DB::raw('LOWER(sender_address)'), $addresses)
                        ->orWhereIn(DB::raw('LOWER(recipient_address)'), $addresses);
                }
            })
            ->orderBy('id')
            ->get(['id', 'updated_at'])
            ->each(function ($row) use ($user, &$granted) {
                $granted += $this->awardHistoric(
                    $user,
                    'bridge',
                    'request:'.$row->id,
                    $this->parseTimestamp($row->updated_at),
                );
            });

        return $granted;
    }

    private function backfillGovernance(User $user): int
    {
        $granted = 0;

        foreach ([
            'proposal' => $user->proposals()->get(['id', 'created_at']),
            'vote' => $user->proposalVotes()->get(['id', 'created_at']),
            'comment' => $user->proposalComments()->get(['id', 'created_at']),
        ] as $action => $rows) {
            foreach ($rows as $row) {
                $granted += $this->awardHistoric(
                    $user,
                    $action,
                    (string) $row->id,
                    $this->parseTimestamp($row->created_at),
                );
            }
        }

        return $granted;
    }

    /** Normalise an optional instant to UTC, defaulting to now. */
    private function utc(?CarbonInterface $at = null): Carbon
    {
        return $at ? Carbon::parse($at)->utc() : Carbon::now('UTC');
    }

    /**
     * Pay for a historic action and, when it is recent enough to still matter,
     * let it count towards the live streak and quest board too. Quest progress
     * only moves when the award was actually new, so a rerun of the sync never
     * inflates a board.
     */
    private function awardHistoric(User $user, string $action, string $reference, ?Carbon $at): int
    {
        $granted = $this->award($user, $action, $reference, (int) config("gamification.xp.{$action}", 0));

        if ($granted === 0 || $at === null) {
            return $granted;
        }

        $now = Carbon::now('UTC');

        if ($at->isSameDay($now)) {
            $granted += $this->touch($user, $at);
        }

        // Weekly quests run Monday-to-Monday, matching the `o-\WW` period key.
        if ($at->greaterThanOrEqualTo($now->copy()->startOfWeek(CarbonInterface::MONDAY))) {
            $granted += $this->advanceQuests($user, $action, $at, null);
        }

        return $granted;
    }

    /**
     * Timestamps arrive as Carbon from Eloquent and as sqlite UTC text from
     * the bot's tables; both are normalised here, unparseable values dropped.
     */
    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** activity_events is written by the Telegram bot and may not exist yet. */
    private function hasTable(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }
}
