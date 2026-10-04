<?php

namespace App\Services\Dao;

use App\Jobs\CreateProposalSnapshot;
use App\Models\Proposal;
use App\Models\User;

/**
 * Putting a proposal up: one sequence, whether the site's form or the wallet
 * submitted it — the row, the feed entry, the holder snapshot and the
 * notification, so a proposal written from a phone is not a lesser one.
 */
class ProposalPublisher
{
    public function __construct(
        private ActivityRecorder $activityRecorder,
        private DaoNotifier $notifier,
    ) {}

    /**
     * @param  array{dao_id: int|string, title: string, description?: string|null, ends_at: mixed}  $attributes
     */
    public function publish(User $user, array $attributes): Proposal
    {
        $proposal = Proposal::create([
            ...$attributes,
            'user_id' => $user->id,
        ]);

        $this->activityRecorder->record('proposal.created', $user, $proposal, $proposal->dao);

        // Heavy holder scan runs after the response; see the job docblock.
        CreateProposalSnapshot::dispatchAfterResponse($proposal);

        $this->notifier->proposalCreated($proposal);

        return $proposal;
    }
}
