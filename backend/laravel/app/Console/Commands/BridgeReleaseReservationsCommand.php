<?php

namespace App\Console\Commands;

use App\Services\BridgeAdmissionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bridge:release-reservations')]
#[Description('Close out bridge capacity holds that lapsed before a source transfer.')]
class BridgeReleaseReservationsCommand extends Command
{
    /**
     * Tidying, not accounting.
     *
     * An expired hold already stops counting against capacity the moment it
     * expires — `BridgeReservation::scopeOutstanding()` applies the window on
     * every read, so nobody waits on a cron to get their liquidity back. This
     * command only writes the terminal status so the ledger reads honestly.
     *
     * It touches nothing that has a bridge request attached: once a transfer
     * exists, the claim is an obligation and only settlement ends it.
     */
    public function handle(BridgeAdmissionService $admission): int
    {
        $released = $admission->releaseExpired();

        $this->info($released === 0
            ? 'No lapsed bridge capacity holds.'
            : "Released {$released} lapsed bridge capacity hold(s).");

        return self::SUCCESS;
    }
}
