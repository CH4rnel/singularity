<?php

namespace App\Policies;

use App\Models\ProposalComment;
use App\Models\User;

class ProposalCommentPolicy
{
    public function delete(User $user, ProposalComment $comment): bool
    {
        return $comment->user_id === $user->id;
    }
}
