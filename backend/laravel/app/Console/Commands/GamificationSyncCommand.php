<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GamificationService;
use Illuminate\Console\Command;

/**
 * Credit XP for everything the platform recorded but the browser could not be
 * trusted to report: on-chain swaps/liquidity/lending/staking from the
 * Telegram bot's indexer, completed bridges, and any governance rows written
 * while the gamification layer was off.
 *
 * Idempotent by construction (the xp_entries unique index), so it is safe to
 * run on a schedule, re-run after a data repair, or run for a single user.
 */
class GamificationSyncCommand extends Command
{
    protected $signature = 'gamification:sync
                            {--user= : Sync only this user id}
                            {--days=7 : Only consider users active within this many days (0 = everyone)}';

    protected $description = 'Award XP for on-chain and platform activity recorded outside the app';

    public function handle(GamificationService $gamification): int
    {
        $days = (int) $this->option('days');

        $query = User::query()->whereNull('merged_into_id');

        if ($userId = $this->option('user')) {
            $query->whereKey((int) $userId);
        } elseif ($days > 0) {
            // Dormant accounts have nothing new to credit; skipping them keeps
            // a scheduled run proportional to the active user base.
            $cutoff = now('UTC')->subDays($days);
            $query->where(function ($scope) use ($cutoff) {
                $scope->whereHas('stat', fn ($stat) => $stat->where('last_active_on', '>=', $cutoff->startOfDay()))
                    ->orWhere('created_at', '>=', $cutoff)
                    ->orWhereDoesntHave('stat');
            });
        }

        $users = 0;
        $granted = 0;

        $query->chunkById(200, function ($chunk) use ($gamification, &$users, &$granted) {
            foreach ($chunk as $user) {
                $users++;
                $granted += array_sum($gamification->backfill($user));
            }
        });

        $this->info("gamification:sync — {$users} users scanned, {$granted} XP granted.");

        return self::SUCCESS;
    }
}
