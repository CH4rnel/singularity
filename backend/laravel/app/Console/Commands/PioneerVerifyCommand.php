<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PioneerSeasonService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Check every profile with a wallet against the season's checkpoints.
 *
 * The page verifies whoever opens it; this is for everybody who swapped and
 * did not come back to look, and for the last minutes of the season after the
 * page stopped being visited. Idempotent, so the schedule can run it as often
 * as it likes; outside the season (and a three-day tail after it, for the
 * indexer to catch up) it does nothing.
 */
#[Signature('pioneer:verify
    {--user= : One user id, instead of a sweep}')]
#[Description('Verify Pioneer Season checkpoints from the indexer and the bridge table')]
class PioneerVerifyCommand extends Command
{
    public function handle(PioneerSeasonService $pioneer): int
    {
        $now = Carbon::now('UTC');

        if (! $this->option('user')
            && ($pioneer->state($now) === 'upcoming' || $now->greaterThan($pioneer->endsAt()->addDays(3)))) {
            $this->info('pioneer:verify — the season is not running.');

            return self::SUCCESS;
        }

        $query = User::query()
            ->whereNull('merged_into_id')
            ->where(fn ($scope) => $scope->whereNotNull('wallet_address')->orWhereNotNull('solana_wallet_address'));

        if ($userId = $this->option('user')) {
            $query->whereKey((int) $userId);
        }

        $users = 0;
        $recorded = 0;
        $finished = 0;

        $query->chunkById(200, function ($chunk) use ($pioneer, &$users, &$recorded, &$finished) {
            foreach ($chunk as $user) {
                $users++;
                $result = $pioneer->verify($user);
                $recorded += count($result['recorded']);
                $finished += $result['finished'] && $result['recorded'] !== [] ? 1 : 0;
            }
        });

        $this->info("pioneer:verify — {$users} users checked, {$recorded} checkpoints recorded, {$finished} newly finished.");

        return self::SUCCESS;
    }
}
