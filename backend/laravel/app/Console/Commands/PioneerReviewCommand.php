<?php

namespace App\Console\Commands;

use App\Models\PioneerCheckpoint;
use App\Models\PioneerFinisher;
use App\Services\AchievementService;
use App\Services\PioneerSeasonService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The human half of the season's sybil control.
 *
 * The first `pioneer.review_first` finishers wait here. Without options it
 * lists them with the records that passed each checkpoint, so a reviewer can
 * open every one on the explorer; `--approve` pays the completion XP and mints
 * the badge, `--reject` keeps the checkpoint XP and gives nothing else.
 */
#[Signature('pioneer:review
    {--approve= : User id to approve}
    {--reject= : User id to reject}
    {--note= : Why, stored on the finisher}
    {--by=console : Who reviewed}')]
#[Description('List Pioneer Season finishers awaiting review, or approve/reject one')]
class PioneerReviewCommand extends Command
{
    public function handle(PioneerSeasonService $pioneer, AchievementService $achievements): int
    {
        $approve = $this->option('approve');
        $reject = $this->option('reject');

        if ($approve === null && $reject === null) {
            return $this->listPending($pioneer);
        }

        $finisher = PioneerFinisher::query()
            ->where('season', $pioneer->seasonKey())
            ->where('user_id', (int) ($approve ?? $reject))
            ->first();

        if (! $finisher) {
            $this->error('That user has not finished this season.');

            return self::FAILURE;
        }

        $by = (string) $this->option('by');
        $note = $this->option('note');

        if ($reject !== null) {
            $pioneer->reject($finisher, $by, $note);
            $this->info("Rejected user {$finisher->user_id}.");

            return self::SUCCESS;
        }

        $pioneer->approve($finisher, $by, $note);
        $this->info("Approved user {$finisher->user_id}.");

        if ($finisher->user) {
            $result = $achievements->award($finisher->user);

            $this->line(match (true) {
                $result['awarded'] !== [] => 'Badge minted.',
                $result['failed'] !== [] => 'Badge mint failed; achievements:sync will retry.',
                default => 'Badge not minted now (no EVM wallet, or the chain was unreadable); achievements:sync will retry.',
            });
        }

        return self::SUCCESS;
    }

    private function listPending(PioneerSeasonService $pioneer): int
    {
        $pending = PioneerFinisher::query()
            ->with('user')
            ->where('season', $pioneer->seasonKey())
            ->where('status', PioneerFinisher::PENDING)
            ->orderBy('completed_at')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nobody is waiting for review.');

            return self::SUCCESS;
        }

        foreach ($pending as $finisher) {
            $this->line(sprintf(
                '#%d %s %s finished %s',
                $finisher->user_id,
                $finisher->user?->name ?? '—',
                $finisher->user?->wallet_address ?? $finisher->user?->solana_wallet_address ?? '—',
                $finisher->completed_at?->toDateTimeString(),
            ));

            PioneerCheckpoint::query()
                ->where('season', $pioneer->seasonKey())
                ->where('user_id', $finisher->user_id)
                ->orderBy('checkpoint')
                ->get()
                ->each(fn (PioneerCheckpoint $row) => $this->line(sprintf(
                    '    %02d  %s  %s',
                    $row->checkpoint,
                    $row->qualified_at?->toDateTimeString(),
                    $row->reference,
                )));
        }

        return self::SUCCESS;
    }
}
