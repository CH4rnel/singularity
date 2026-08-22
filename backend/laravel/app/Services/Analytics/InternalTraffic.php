<?php

namespace App\Services\Analytics;

use App\Models\SiteEvent;
use Illuminate\Support\Facades\Cache;

/**
 * Which rows on this site are ours.
 *
 * `site_events` carries a user id, so the operators are identifiable there in
 * a way they deliberately are not in the product tables. The identification is
 * worth making for one reason: on a chain this young the two people who build
 * the product are most of its traffic, and every conversion rate that includes
 * them describes their testing rather than the market. Sixty-eight of the
 * seventy swaps ever recorded here were theirs.
 *
 * A *session* is the unit, not an event. An operator browses signed in, so
 * some of their rows carry a user id and some do not — a page view before the
 * session hydrated, an event fired from a component that never asked. Dropping
 * only the attributed half would leave the visits and remove the conversions,
 * which is worse than doing nothing: it would make the funnel look *worse*
 * than reality instead of better. So a session that ever carried an internal
 * user id is internal for its whole length.
 *
 * The set is small (a handful of sessions against a few thousand rows) and
 * every lens asks for it, so it is cached for a minute — long enough to serve
 * one page load's worth of queries from one read, short enough that marking a
 * new operator shows up while you are still looking at the screen.
 */
class InternalTraffic
{
    private const CACHE_KEY = 'analytics.internal.sessions';

    private const CACHE_SECONDS = 60;

    /**
     * The site accounts that are ours.
     *
     * Two sources, merged here rather than in `config/analytics.php`: config
     * files load alphabetically, `analytics` comes before `crm`, and a
     * `config('crm.…')` call from inside that file returns an empty array
     * without complaining — an exclusion that looks configured and excludes
     * nobody. Pinned by a test for exactly that reason.
     *
     * @return array<int, int>
     */
    public function userIds(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($value) => (int) trim((string) $value),
            [
                ...(array) config('analytics.internal.user_ids', []),
                ...(array) config('crm.admin_user_ids', []),
            ],
        ))));
    }

    /**
     * The addresses that mark an installation as ours.
     *
     * The console's own keys, plus whatever the environment adds — the wallet
     * somebody tests from is very often not the one that opens /crm.
     *
     * @return array<int, string>
     */
    public function wallets(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            [
                ...(array) config('analytics.internal.wallets', []),
                ...(array) config('crm.admin_wallets', []),
            ],
        ))));
    }

    /**
     * Every site session that belongs to us.
     *
     * Matched two ways because the two carry different information: a user id
     * is the person and survives a re-attached key, a wallet address is the
     * key and is present on rows written before anybody signed in.
     *
     * @return array<int, string>
     */
    public function sessionIds(): array
    {
        $users = $this->userIds();
        $wallets = $this->wallets();

        if ($users === [] && $wallets === []) {
            return [];
        }

        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_SECONDS,
            fn (): array => SiteEvent::query()
                ->where(function ($query) use ($users, $wallets) {
                    if ($users !== []) {
                        $query->orWhereIn('user_id', $users);
                    }

                    if ($wallets !== []) {
                        $query->orWhereIn('wallet_address', $wallets);
                    }
                })
                ->distinct()
                ->pluck('session_id')
                ->all(),
        );
    }

    /**
     * Narrow a `site_events` query to the outside world.
     *
     * A no-op when nothing is configured, which is the honest behaviour for a
     * fresh installation: with no operators declared, everybody is a stranger.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public function excludeFrom($query, string $column = 'session_id')
    {
        $sessions = $this->sessionIds();

        if ($sessions !== []) {
            $query->whereNotIn($column, $sessions);
        }

        return $query;
    }

    /** How many sessions this exclusion is responsible for, for saying so. */
    public function sessionCount(): int
    {
        return count($this->sessionIds());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
