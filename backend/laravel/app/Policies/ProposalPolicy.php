<?php

namespace App\Policies;

use App\Http\Middleware\EnsureCrmAdmin;
use App\Models\Proposal;
use App\Models\User;

class ProposalPolicy
{
    public function update(User $user, Proposal $proposal): bool
    {
        return $proposal->user_id === $user->id;
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return $proposal->user_id === $user->id;
    }

    /**
     * Ending the vote early is moderation, not authorship, so it reaches
     * wider than update(): the author, the DAO's owner, and an operator.
     *
     * Most DAOs here were registered before ownership was recorded and have
     * no owner at all, which is why the operator is on this list — without
     * them a proposal in an ownerless DAO could only ever be closed by
     * whoever opened it.
     */
    public function close(User $user, Proposal $proposal): bool
    {
        return $this->update($user, $proposal)
            || ($proposal->dao?->user_id !== null && $proposal->dao->user_id === $user->id)
            || EnsureCrmAdmin::allows($user);
    }
}
