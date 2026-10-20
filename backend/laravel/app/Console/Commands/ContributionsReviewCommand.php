<?php

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Models\ContributorAccount;
use App\Services\Contributions\ContributionScorer;
use Illuminate\Console\Command;

/**
 * The human half of anti-sybil: overrule the guard on one contribution, or
 * ban an account outright.
 *
 *   contributions:review reject owner/name#12 "farmed typo fixes"
 *   contributions:review count  owner/name#12
 *   contributions:review ban    <login> "sybil ring"
 *
 * A reviewed row is frozen: the sync never re-judges it. A row an epoch has
 * already paid cannot be reviewed at all — that money is in a root.
 */
class ContributionsReviewCommand extends Command
{
    protected $signature = 'contributions:review {action : reject|count|ban} {target : owner/name#number or a login} {reason?}';

    protected $description = 'Overrule the reward engine on one contribution, or ban a GitHub account';

    public function handle(ContributionScorer $scorer): int
    {
        $action = (string) $this->argument('action');
        $target = (string) $this->argument('target');
        $reason = (string) ($this->argument('reason') ?? 'operator');

        if ($action === 'ban') {
            $account = ContributorAccount::query()->where('login', $target)->first();

            if ($account === null) {
                $this->error("No contributor named {$target}.");

                return self::FAILURE;
            }

            $account->forceFill(['banned_at' => now(), 'ban_reason' => $reason])->save();
            $refused = Contribution::query()
                ->where('github_id', $account->github_id)
                ->whereNull('contribution_epoch_id')
                ->update(['status' => Contribution::REJECTED, 'reason' => 'banned', 'points' => 0, 'reviewed_at' => now()]);

            $this->info("Banned {$target}; {$refused} unpaid contributions refused.");

            return self::SUCCESS;
        }

        if (preg_match('/^([\w.-]+\/[\w.-]+)#(\d+)$/', $target, $match) !== 1) {
            $this->error('Target must look like owner/name#12.');

            return self::FAILURE;
        }

        $row = Contribution::query()
            ->whereHas('repository', fn ($query) => $query->where('full_name', strtolower($match[1])))
            ->where('number', (int) $match[2])
            ->with('repository')
            ->first();

        if ($row === null) {
            $this->error("{$target} is not indexed.");

            return self::FAILURE;
        }

        if ($row->contribution_epoch_id !== null) {
            $this->error("{$target} was paid in an epoch and cannot change.");

            return self::FAILURE;
        }

        $counted = $action === 'count';

        $row->forceFill([
            'status' => $counted ? Contribution::COUNTED : Contribution::REJECTED,
            'reason' => $counted ? null : $reason,
            'points' => $counted ? $scorer->score($row->kind, $row->additions, $row->deletions, (float) $row->repository->weight) : 0,
            'reviewed_at' => now(),
        ])->save();

        $this->info("{$target}: {$row->status}".($counted ? sprintf(' (%.2f points)', $row->points / 100) : ''));

        return self::SUCCESS;
    }
}
