<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserQuest;
use App\Models\UserStat;
use App\Models\XpEnchantment;
use App\Models\XpEntry;
use App\Notifications\ProgressNotification;
use App\Support\Localised;
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

        /*
         * "Open Cyberia today" is satisfied by *any* action and must not depend
         * on this being the first one. Nothing ever calls this with `visit` —
         * the site reports `page_view` — and advancing it from touch() looked
         * right and was wrong, because touch() returns early once the day is
         * already marked. Here it is idempotent instead of positional: a
         * completed quest short-circuits, so it costs a lookup and heals a day
         * that started without it.
         */
        if ($action !== 'visit') {
            $granted += $this->advanceQuests($user, 'visit', $at, null);
        }

        // A streak is a fact about the account rather than a thing anybody
        // does, so nothing would ever report it as an action.
        if ($action !== 'streak_day' && $this->statsFor($user)->current_streak >= 2) {
            $granted += $this->advanceQuests($user, 'streak_day', $at, null);
        }

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
    /**
     * Where somebody stands and what they can spend.
     *
     * One number, deliberately. There was briefly a second — the subset the
     * chain would vouch for — because XP was about to discount a real fee and
     * a browser can move `visit`. What XP buys is access to parts of this
     * project instead, so a farmed balance takes nothing from anybody and the
     * distinction stopped earning the cost of explaining it on every screen.
     *
     * @return array{xp: int, level: int, title: string, spendable: int, perks: array<string, int>}
     */
    public function standing(User $user): array
    {
        $stats = $this->statsFor($user);

        return [
            'xp' => (int) $stats->xp,
            'level' => (int) $stats->level,
            'title' => $this->titleFor((int) $stats->level),
            'spendable' => $this->spendable($user),
            'perks' => $this->perksFor($user),
        ];
    }

    /**
     * What is left to spend.
     *
     * Lifetime XP minus what has been spent on unlocks that still exist.
     *
     * That last clause is a refund rule and it is load-bearing. Prices are
     * recorded at purchase so a later change cannot rewrite what somebody
     * paid — but an unlock being *withdrawn* is not a price change, it is the
     * goods disappearing, and continuing to hold the charge would mean
     * somebody paying for something we took away. One operator is already in
     * that position: a cross-chain fee discount was on sale for two hours
     * before the rule against XP touching money was settled.
     *
     * Never negative, so a price rise after the fact cannot put anybody in
     * debt either.
     */
    public function spendable(User $user): int
    {
        $offered = array_column($this->catalogue(), 'key');

        $spent = (int) XpEnchantment::query()
            ->where('user_id', $user->id)
            ->whereIn('key', $offered)
            ->sum('cost');

        return max(0, (int) $this->statsFor($user)->xp - $spent);
    }

    /** @return array<int, string> */
    public function owned(User $user): array
    {
        return XpEnchantment::query()
            ->where('user_id', $user->id)
            ->pluck('key')
            ->all();
    }

    /**
     * What this user has unlocked, as effect flags.
     *
     * The highest value for each effect wins, so a ladder that ever grows one
     * is worth its best rung rather than the sum of them.
     *
     * @return array<string, int>
     */
    public function perksFor(User $user): array
    {
        $owned = $this->owned($user);
        $effects = [];

        foreach ($this->catalogue() as $enchantment) {
            if (! in_array($enchantment['key'], $owned, true)) {
                continue;
            }

            foreach ((array) ($enchantment['effects'] ?? []) as $name => $value) {
                $effects[$name] = max($effects[$name] ?? 0, (int) $value);
            }
        }

        return $effects;
    }

    /** @return array<int, array<string, mixed>> */
    public function catalogue(): array
    {
        return (array) config('gamification.unlocks', []);
    }

    /**
     * The table, as somebody standing at it sees it.
     *
     * Four states and they are not the same refusal: owned, affordable,
     * `level` (standing too low, so no amount of saving helps yet), and `xp`
     * (standing is fine, the balance is not). A single "unavailable" would
     * hide which of the two a person should go and do something about.
     *
     * @return array<int, array<string, mixed>>
     */
    public function enchantments(User $user): array
    {
        $owned = $this->owned($user);
        $balance = $this->spendable($user);
        $level = (int) $this->statsFor($user)->level;

        return array_map(function (array $enchantment) use ($owned, $balance, $level): array {
            $has = in_array($enchantment['key'], $owned, true);
            $needs = $enchantment['requires'] ?? null;

            $state = match (true) {
                $has => 'owned',
                $needs !== null && ! in_array($needs, $owned, true) => 'requires',
                $level < (int) $enchantment['level'] => 'level',
                $balance < (int) $enchantment['cost'] => 'xp',
                default => 'ready',
            };

            return [
                'key' => $enchantment['key'],
                'title' => $enchantment['title'],
                'description' => $enchantment['description'],
                'cost' => (int) $enchantment['cost'],
                'level' => (int) $enchantment['level'],
                'requires' => $needs,
                'effects' => (array) ($enchantment['effects'] ?? []),
                'state' => $state,
            ];
        }, $this->catalogue());
    }

    /**
     * Buy one. Returns the enchantment, or null with a reason it refused.
     *
     * The whole thing is one transaction over a locked balance, and the unique
     * index is the last word: two clicks that both pass the balance check
     * still result in one purchase and one charge, because the second insert
     * cannot land.
     *
     * @return array{ok: bool, reason: string, enchantment: ?array<string, mixed>}
     */
    public function enchant(User $user, string $key): array
    {
        $enchantment = collect($this->catalogue())->firstWhere('key', $key);

        if ($enchantment === null) {
            return ['ok' => false, 'reason' => 'unknown', 'enchantment' => null];
        }

        return DB::transaction(function () use ($user, $key, $enchantment): array {
            // Lock the person, not the row that does not exist yet.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $owned = $this->owned($user);

            if (in_array($key, $owned, true)) {
                return ['ok' => false, 'reason' => 'owned', 'enchantment' => null];
            }

            $needs = $enchantment['requires'] ?? null;

            if ($needs !== null && ! in_array($needs, $owned, true)) {
                return ['ok' => false, 'reason' => 'requires', 'enchantment' => null];
            }

            if ((int) $this->statsFor($user)->level < (int) $enchantment['level']) {
                return ['ok' => false, 'reason' => 'level', 'enchantment' => null];
            }

            if ($this->spendable($user) < (int) $enchantment['cost']) {
                return ['ok' => false, 'reason' => 'xp', 'enchantment' => null];
            }

            XpEnchantment::query()->create([
                'user_id' => $user->id,
                'key' => $key,
                'cost' => (int) $enchantment['cost'],
                'created_at' => Carbon::now('UTC'),
            ]);

            return ['ok' => true, 'reason' => 'ok', 'enchantment' => $enchantment];
        });
    }

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
            'current_streak' => $this->liveStreak($stats->last_active_on, (int) $stats->current_streak),
            'longest_streak' => $stats->longest_streak,
            'last_active_on' => $stats->last_active_on?->toDateString(),
            'active_today' => $stats->last_active_on?->toDateString() === Carbon::now('UTC')->toDateString(),
            'rank' => $this->rankOf($stats),
            /*
             * What is left to spend, and what has been bought with it. One
             * number: every source that pays XP is credited from ground
             * truth, so there is no longer a bigger figure that buys nothing.
             */
            'spendable' => $this->spendable($user),
            'perks' => $this->perksFor($user),
            'enchantments' => $this->enchantments($user),
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
            'current_streak' => $this->liveStreak($stats->last_active_on, (int) $stats->current_streak),
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
                'user_stats.last_active_on',
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
                'current_streak' => $this->liveStreak($row->last_active_on, (int) $row->current_streak),
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

            $row->progress++;

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
            title: [
                'en' => 'Level {level} — {rank}',
                'ru' => 'Уровень {level} — {rank}',
                'zh' => '等级 {level} — {rank}',
            ],
            body: [
                'en' => 'Your Cyberia rank went up. Keep the streak alive.',
                'ru' => 'Ранг в Cyberia вырос. Не теряйте серию.',
                'zh' => '你的 Cyberia 等级提升了。保持连续记录。',
            ],
            url: '/profile',
            replace: ['level' => $level, 'rank' => $this->titleFor($level)],
        ));
    }

    /**
     * @param  array<string, mixed>  $quest
     */
    private function notifyQuestComplete(User $user, array $quest): void
    {
        $user->notify(new ProgressNotification(
            type: 'progress.quest_completed',
            title: [
                'en' => 'Quest complete: {quest}',
                'ru' => 'Задание выполнено: {quest}',
                'zh' => '任务完成：{quest}',
            ],
            body: ['en' => '+{xp} XP'],
            url: '/profile',
            replace: [
                // Quest titles already ship in all three languages in
                // config/gamification.php, so the right one is picked here
                // rather than being folded into the sentence above.
                'quest' => Localised::pick(
                    is_array($quest['title'] ?? null) ? $quest['title'] : ['en' => $quest['key']],
                    $user->notification_locale,
                ),
                'xp' => $quest['xp'],
            ],
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

    /**
     * The streak as it stands *today*, rather than as it was last left.
     *
     * `current_streak` is a stored counter that only ever moves inside
     * `touch()`, so a person who stops coming back keeps the number they
     * walked away with — user 38 was showing five consecutive days on the
     * leaderboard a week after their last one. Nothing ever ran to break it,
     * because breaking a streak is the absence of an event.
     *
     * Yesterday still counts: the day is not over, so somebody who was here
     * yesterday has a streak that is alive and at risk, which is exactly what
     * `gamification:remind` writes to them about.
     */
    public function liveStreak(mixed $lastActiveOn, int $stored, ?CarbonInterface $at = null): int
    {
        $day = $this->day($lastActiveOn);

        if ($day === null || $stored <= 0) {
            return 0;
        }

        $now = $this->utc($at);

        return in_array($day, [$now->toDateString(), $now->copy()->subDay()->toDateString()], true)
            ? $stored
            : 0;
    }

    /** The date part of whatever shape a timestamp arrived in. */
    private function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 10);
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
