<?php

namespace App\Services;

use App\Models\UserStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-user analytics for the CRM: how many people come back, and how long
 * after their first visit they stop.
 *
 * The funnel in CrmAnalyticsController answers "did this visit convert"; this
 * answers "did this person return", which is the number the gamification work
 * is supposed to move. Both read site_events, but retention is keyed on
 * session_id, which track.ts persists in localStorage — so it survives across
 * visits and behaves like a device id rather than a per-tab session.
 *
 * Everything is computed in PHP from two small aggregate queries rather than
 * window functions, because the same SQLite file is shared with the Telegram
 * bot and portability beats cleverness here.
 */
class UserAnalyticsService
{
    /** Offsets (days after first visit) reported as retention buckets. */
    private const BUCKETS = [1, 7, 30];

    /**
     * Active-device counts over the usual three windows, plus the DAU/MAU
     * stickiness ratio that says how much of the monthly base shows up daily.
     *
     * @return array<string, int|float>
     */
    public function activity(): array
    {
        $now = Carbon::now('UTC');

        $active = fn (int $days): int => DB::table('site_events')
            ->where('created_at', '>=', $now->copy()->subDays($days))
            ->distinct()
            ->count('session_id');

        $dau = $active(1);
        $mau = $active(30);

        return [
            'dau' => $dau,
            'wau' => $active(7),
            'mau' => $mau,
            'stickiness' => $mau > 0 ? round($dau / $mau * 100, 1) : 0.0,
        ];
    }

    /**
     * New vs returning devices inside the window. "Returning" means the device
     * was first seen before the window opened and came back during it — the
     * closest thing to a loyalty count this data supports.
     *
     * @return array<string, int>
     */
    public function newVsReturning(int $days): array
    {
        $windowStart = Carbon::now('UTC')->subDays($days);
        $firstSeen = $this->firstSeenDays();

        $sessions = DB::table('site_events')
            ->where('created_at', '>=', $windowStart)
            ->distinct()
            ->pluck('session_id');

        $new = 0;
        $returning = 0;

        foreach ($sessions as $session) {
            $first = $firstSeen[$session] ?? null;

            if ($first !== null && $first < $windowStart->toDateString()) {
                $returning++;
            } else {
                $new++;
            }
        }

        return ['new' => $new, 'returning' => $returning];
    }

    /**
     * Weekly acquisition cohorts with day-1 / day-7 / day-30 return rates.
     *
     * A cohort is only counted for a bucket once it has had time to reach it,
     * so this week's cohort reports null for day-30 instead of a misleading 0.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cohorts(int $weeks = 6): array
    {
        $now = Carbon::now('UTC');
        $cohortStart = $now->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($weeks - 1);
        $firstSeen = $this->firstSeenDays();

        // Only devices acquired inside the reported window form cohorts, but
        // their return days are read from the whole table.
        $members = [];

        foreach ($firstSeen as $session => $day) {
            if ($day >= $cohortStart->toDateString()) {
                $members[$session] = $day;
            }
        }

        if ($members === []) {
            return [];
        }

        $activeDays = $this->activeDaysFor(array_keys($members), $cohortStart);

        $cohorts = [];

        foreach ($members as $session => $firstDay) {
            $first = Carbon::parse($firstDay, 'UTC');
            $week = $first->copy()->startOfWeek(Carbon::MONDAY)->toDateString();

            $cohorts[$week] ??= ['week' => $week, 'size' => 0, 'returned' => array_fill_keys(self::BUCKETS, 0)];
            $cohorts[$week]['size']++;

            foreach (self::BUCKETS as $bucket) {
                // "Returned by day N": any activity from the day after signup
                // through day N. Same-day activity is the visit itself.
                for ($offset = 1; $offset <= $bucket; $offset++) {
                    if (in_array($first->copy()->addDays($offset)->toDateString(), $activeDays[$session] ?? [], true)) {
                        $cohorts[$week]['returned'][$bucket]++;
                        break;
                    }
                }
            }
        }

        krsort($cohorts);

        return array_values(array_map(function (array $cohort) use ($now) {
            $weekStart = Carbon::parse($cohort['week'], 'UTC');
            $rates = [];

            foreach (self::BUCKETS as $bucket) {
                // The youngest member of the cohort is the one that gates the
                // bucket: the week must be fully aged before a rate is honest.
                $matured = $weekStart->copy()->addDays(6 + $bucket)->lessThanOrEqualTo($now);

                $rates['d'.$bucket] = $matured && $cohort['size'] > 0
                    ? round($cohort['returned'][$bucket] / $cohort['size'] * 100, 1)
                    : null;
            }

            return [
                'week' => $cohort['week'],
                'size' => $cohort['size'],
                'rates' => $rates,
            ];
        }, $cohorts));
    }

    /**
     * How the progression system is actually doing: how many accounts hold a
     * live streak, and how they are spread across levels.
     *
     * @return array<string, mixed>
     */
    public function progression(): array
    {
        // A streak is only alive while it was touched today or yesterday.
        // last_active_on is a date cast, which Eloquent still stores in the
        // model's full datetime format, so compare against an instant.
        $aliveSince = Carbon::now('UTC')->subDay()->startOfDay();

        $levels = UserStat::query()
            ->selectRaw('level, COUNT(*) as accounts')
            ->groupBy('level')
            ->orderBy('level')
            ->get()
            ->map(fn ($row) => ['level' => (int) $row->level, 'accounts' => (int) $row->accounts])
            ->all();

        return [
            'tracked' => UserStat::query()->count(),
            'with_xp' => UserStat::query()->where('xp', '>', 0)->count(),
            'live_streaks' => UserStat::query()
                ->where('current_streak', '>', 0)
                ->where('last_active_on', '>=', $aliveSince)
                ->count(),
            'streaks_over_week' => UserStat::query()
                ->where('current_streak', '>=', 7)
                ->where('last_active_on', '>=', $aliveSince)
                ->count(),
            'longest_streak' => (int) UserStat::query()->max('longest_streak'),
            'levels' => $levels,
        ];
    }

    /**
     * session_id => first-seen UTC date, across the whole table.
     *
     * @return array<string, string>
     */
    private function firstSeenDays(): array
    {
        return DB::table('site_events')
            ->selectRaw('session_id, MIN(created_at) as first_at')
            ->groupBy('session_id')
            ->pluck('first_at', 'session_id')
            ->map(fn ($value) => Carbon::parse($value)->utc()->toDateString())
            ->all();
    }

    /**
     * session_id => list of UTC dates the device was active on.
     *
     * @param  array<int, string>  $sessions
     * @return array<string, array<int, string>>
     */
    private function activeDaysFor(array $sessions, Carbon $since): array
    {
        $days = [];

        // Chunked to stay clear of SQLite's variable limit on the IN clause.
        foreach (array_chunk($sessions, 500) as $chunk) {
            DB::table('site_events')
                ->whereIn('session_id', $chunk)
                ->where('created_at', '>=', $since)
                ->selectRaw('session_id, created_at')
                ->orderBy('id')
                ->get()
                ->each(function ($row) use (&$days) {
                    $day = Carbon::parse($row->created_at)->utc()->toDateString();
                    $days[$row->session_id][$day] = true;
                });
        }

        return array_map(fn (array $set) => array_keys($set), $days);
    }

    /**
     * The most engaged signed-in accounts, for the operator to actually talk
     * to. Ordered by live streak first, then XP.
     *
     * @return Collection<int, object>
     */
    public function topMembers(int $limit = 10): Collection
    {
        return UserStat::query()
            ->join('users', 'users.id', '=', 'user_stats.user_id')
            ->whereNull('users.merged_into_id')
            ->where('user_stats.xp', '>', 0)
            ->orderByDesc('user_stats.current_streak')
            ->orderByDesc('user_stats.xp')
            ->limit($limit)
            ->get([
                'users.id as user_id',
                'users.name',
                'users.wallet_address',
                'user_stats.xp',
                'user_stats.level',
                'user_stats.current_streak',
                'user_stats.last_active_on',
            ]);
    }
}
