<?php

namespace App\Console\Commands;

use App\Models\UserStat;
use App\Models\XpEntry;
use App\Services\GamificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Rebuild every balance from the ledger, and drop XP nothing pays for any more.
 *
 * XP became a currency, so what is in circulation has to mean one thing. Some
 * of it was paid for opening a page, for coming back, and for writing a DAO
 * comment — free to produce, and now spendable on permanent things. Leaving it
 * would mean the first purchases on this platform were partly bought with
 * points somebody got for loading a URL.
 *
 * `--prune` is the destructive half and is opt-in. Without it this only
 * recomputes `user_stats` from the entries that exist, which is worth having
 * on its own: the stats table is a running total and a running total that is
 * never checked against its ledger drifts.
 *
 * Levels fall for some people. That is the point rather than a side effect,
 * and it is why the run prints who moved.
 */
#[Signature('gamification:recount {--prune : Delete XP from sources that no longer pay} {--dry-run : Report without writing}')]
#[Description('Recompute XP totals from the ledger, optionally dropping sources that no longer pay')]
class GamificationRecountCommand extends Command
{
    public function handle(GamificationService $gamification): int
    {
        $paying = array_keys((array) config('gamification.xp', []));

        /*
         * Two sources are real and have no entry in the XP table, because
         * neither is an action anybody performs: `quest` is a completion bonus
         * priced per quest, and `streak` is a milestone priced in
         * `streak_bonuses`. Deriving the list from `config('gamification.xp')`
         * alone deleted every streak bonus ever paid.
         */
        $paying[] = 'quest';
        $paying[] = 'streak';

        /*
         * Except the ones whose quest is gone. `daily_explore` paid 20 XP for
         * opening three pages and accounts for most of the quest bonuses ever
         * awarded; keeping them because their *source* is still called `quest`
         * would leave exactly the browser-earned currency this is removing.
         * The reference is `<quest key>:<period>`, which is how they are told
         * apart.
         */
        $live = array_column((array) config('gamification.quests', []), 'key');

        /*
         * One definition of "stale", used by the delete and by the dry run's
         * projection alike. They diverged once — the projection filtered on
         * `source` only, so it under-reported the fall for anybody holding a
         * bonus from a deleted quest, which is the worst way for a dry run to
         * be wrong.
         */
        $isStale = function ($query) use ($paying, $live) {
            $query->whereNotIn('source', $paying)
                ->orWhere(function ($quests) use ($live) {
                    $quests->where('source', 'quest');

                    foreach ($live as $key) {
                        $quests->where('reference', 'not like', $key.':%');
                    }
                });
        };

        $stale = XpEntry::query()->where($isStale);
        $staleTotal = (int) (clone $stale)->sum('amount');
        $staleCount = (clone $stale)->count();

        $this->line('Sources that no longer pay:');

        foreach ((clone $stale)->get(['source', 'reference', 'amount'])
            ->groupBy(fn (XpEntry $entry): string => $entry->source === 'quest'
                ? 'quest:'.explode(':', (string) $entry->reference)[0]
                : $entry->source)
            ->sortByDesc(fn ($group) => $group->sum('amount')) as $label => $group) {
            $this->line(sprintf('  %-26s %-6s %s XP', $label, $group->count(), $group->sum('amount')));
        }

        if ($staleCount === 0) {
            $this->info('  none.');
        }

        $this->newLine();

        if ($this->option('prune') && ! $this->option('dry-run')) {
            $stale->delete();
            $this->warn(sprintf('Deleted %d entries worth %d XP.', $staleCount, $staleTotal));
        } elseif ($this->option('prune')) {
            $this->comment(sprintf('Would delete %d entries worth %d XP.', $staleCount, $staleTotal));
        }

        $moved = 0;

        foreach (UserStat::query()->with('user')->get() as $stats) {
            $ledger = (int) XpEntry::query()
                ->where('user_id', $stats->user_id)
                // On a dry run the rows are still there, so project the total
                // the delete would leave rather than the one that exists.
                ->when(
                    $this->option('prune') && $this->option('dry-run'),
                    fn ($query) => $query->whereNot($isStale),
                )
                ->sum('amount');

            $level = $gamification->levelFor($ledger);

            if ($ledger === (int) $stats->xp && $level === (int) $stats->level) {
                continue;
            }

            $moved++;
            $this->line(sprintf(
                'user %-5s xp %-7s → %-7s  level %-3s → %s',
                $stats->user_id, $stats->xp, $ledger, $stats->level, $level,
            ));

            if (! $this->option('dry-run')) {
                $stats->forceFill(['xp' => $ledger, 'level' => $level])->save();
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%d of %d accounts moved%s',
            $moved,
            UserStat::query()->count(),
            $this->option('dry-run') ? ' (dry run, nothing written)' : '',
        ));

        return self::SUCCESS;
    }
}
