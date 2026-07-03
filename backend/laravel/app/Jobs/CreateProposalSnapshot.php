<?php

namespace App\Jobs;

use App\Models\Proposal;
use App\Services\TokenSnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Snapshot token balances of all holders for a new proposal.
 *
 * Dispatched with dispatchAfterResponse() from ProposalController::store —
 * production runs no queue worker, so the heavy eth_getLogs holder scan runs
 * in the same process after the response is flushed instead of blocking the
 * request. Voting also lazily snapshots a wallet on first vote, so a missing
 * scan only degrades, never breaks. Switch to dispatch() once a real worker
 * runs in production.
 */
class CreateProposalSnapshot implements ShouldQueue
{
    use Queueable;

    /** The holder scan walks eth_getLogs in 10k-block windows — allow time. */
    public int $timeout = 600;

    public function __construct(public Proposal $proposal) {}

    public function handle(TokenSnapshotService $snapshotService): void
    {
        $snapshotService->createSnapshot($this->proposal);
    }
}
