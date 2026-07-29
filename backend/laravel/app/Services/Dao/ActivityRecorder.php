<?php

namespace App\Services\Dao;

use App\Models\Activity;
use App\Models\Dao;
use App\Models\User;
use App\Services\GamificationService;
use Illuminate\Database\Eloquent\Model;

class ActivityRecorder
{
    /** Feed entry type => the XP action it pays for. */
    private const XP_ACTIONS = [
        'proposal.created' => 'proposal',
        'vote.cast' => 'vote',
        'comment.posted' => 'comment',
    ];

    public function __construct(private readonly GamificationService $gamification) {}

    /**
     * Append one entry to the DAO activity feed.
     *
     * Governance is the single choke point for proposals, votes and comments,
     * so it is also where those actions are paid: XP is keyed by the subject
     * row id, making a replayed request worth nothing.
     *
     * @param  string  $type  proposal.created | vote.cast | comment.posted
     */
    public function record(string $type, User $actor, Model $subject, ?Dao $dao = null): Activity
    {
        $activity = Activity::create([
            'type' => $type,
            'user_id' => $actor->id,
            'dao_id' => $dao?->id,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
        ]);

        if (isset(self::XP_ACTIONS[$type])) {
            $this->gamification->recordAction(
                $actor,
                self::XP_ACTIONS[$type],
                (string) $subject->getKey(),
            );
        }

        return $activity;
    }
}
