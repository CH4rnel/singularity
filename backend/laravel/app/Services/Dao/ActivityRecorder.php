<?php

namespace App\Services\Dao;

use App\Models\Activity;
use App\Models\Dao;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityRecorder
{
    /**
     * Append one entry to the DAO activity feed.
     *
     * @param  string  $type  proposal.created | vote.cast | comment.posted
     */
    public function record(string $type, User $actor, Model $subject, ?Dao $dao = null): Activity
    {
        return Activity::create([
            'type' => $type,
            'user_id' => $actor->id,
            'dao_id' => $dao?->id,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
        ]);
    }
}
