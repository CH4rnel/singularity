<?php

namespace App\Console\Commands;

use App\Models\UserStat;
use App\Models\XpEntry;
use App\Services\GamificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        // Quest bonuses are paid for completing quests, and every quest now
        // requires a transaction — so they are earned by chain work even
        // though `quest` is not itself an action anybody performs.
        $paying[] = 'quest';

        $stale = XpEntry::query()->whereNotIn('source', $paying);
        $staleTotal = (int) (clone $stale)->sum('amount');
        $staleCount = (clone $stale)->count();

        $this->line('Sources that no longer pay:');

        foreach ((clone $stale)->select('source', DB::raw('count(*) as c'), DB::raw('sum(amount) as amt'))
            ->groupBy('source')->orderByDesc('amt')->get() as $row) {
            $this->line(sprintf('  %-12s %-6s %s XP', $row->source, $row->c, $row->amt));
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
                ->when($this->option('prune') && $this->option('dry-run'),
                    fn ($query) => $query->whereIn('source', $paying))
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
