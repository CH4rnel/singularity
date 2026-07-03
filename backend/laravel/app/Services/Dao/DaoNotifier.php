<?php

namespace App\Services\Dao;

use App\Models\Activity;
use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\ProposalVote;
use App\Models\Reaction;
use App\Models\User;
use App\Notifications\DaoActivityNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fans out DAO activity notifications (database + web push).
 *
 * Delivery is deferred with dispatch(...)->afterResponse() because production
 * runs no queue worker: the work happens in the same PHP process right after
 * the response is flushed, so the user never waits on push HTTP calls.
 */
class DaoNotifier
{
    /** Safety cap per event so one busy DAO can't stall the process. */
    private const MAX_RECIPIENTS = 100;

    public function proposalCreated(Proposal $proposal): void
    {
        $actor = $proposal->user;

        if (! $actor) {
            return;
        }

        // Everyone who previously acted in this DAO, minus the author.
        $recipientIds = Activity::where('dao_id', $proposal->dao_id)
            ->where('user_id', '!=', $actor->id)
            ->distinct()
            ->limit(self::MAX_RECIPIENTS)
            ->pluck('user_id');

        $this->send($recipientIds, new DaoActivityNotification(
            type: 'proposal.created',
            actor: $actor,
            title: 'New proposal in '.($proposal->dao?->name ?? 'DAO'),
            body: $this->actorName($actor).': '.Str::limit($proposal->title, 80),
            url: "/proposals/{$proposal->id}",
        ));
    }

    public function commentPosted(ProposalComment $comment): void
    {
        $actor = $comment->user;
        $proposal = $comment->proposal;

        if (! $actor || ! $proposal) {
            return;
        }

        $recipientIds = collect([$proposal->user_id, $comment->parent?->user_id])
            ->merge($proposal->comments()->pluck('user_id'))
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $actor->id)
            ->take(self::MAX_RECIPIENTS);

        $this->send($recipientIds, new DaoActivityNotification(
            type: 'comment.posted',
            actor: $actor,
            title: 'New comment on '.Str::limit($proposal->title, 60),
            body: $this->actorName($actor).': '.Str::limit($comment->body, 80),
            url: "/proposals/{$proposal->id}",
        ));
    }

    public function voteCast(ProposalVote $vote): void
    {
        $actor = $vote->user;
        $proposal = $vote->proposal;

        if (! $actor || ! $proposal || $proposal->user_id === $actor->id) {
            return;
        }

        $this->send(collect([$proposal->user_id]), new DaoActivityNotification(
            type: 'vote.cast',
            actor: $actor,
            title: 'New vote on '.Str::limit($proposal->title, 60),
            body: $this->actorName($actor).' voted '.($vote->support ? 'FOR' : 'AGAINST'),
            url: "/proposals/{$proposal->id}",
        ));
    }

    public function reactionAdded(Reaction $reaction): void
    {
        $actor = $reaction->user;
        $reactable = $reaction->reactable;

        if (! $actor || ! $reactable || $reactable->user_id === $actor->id) {
            return;
        }

        $proposalId = $reactable instanceof ProposalComment
            ? $reactable->proposal_id
            : $reactable->getKey();

        $target = $reactable instanceof ProposalComment ? 'your comment' : 'your proposal';

        $this->send(collect([$reactable->user_id]), new DaoActivityNotification(
            type: 'reaction.added',
            actor: $actor,
            title: $this->actorName($actor).' reacted '.$reaction->emoji,
            body: $reaction->emoji.' on '.$target,
            url: "/proposals/{$proposalId}",
        ));
    }

    /**
     * @param  Collection<int, int>  $recipientIds
     */
    private function send(Collection $recipientIds, DaoActivityNotification $notification): void
    {
        $ids = $recipientIds->values()->all();

        if ($ids === []) {
            return;
        }

        $deliver = function () use ($ids, $notification) {
            // Notify one at a time: a dead push endpoint for one user must not
            // block delivery to the rest.
            User::whereIn('id', $ids)->get()->each(function (User $user) use ($notification) {
                try {
                    $user->notify($notification);
                } catch (\Throwable $e) {
                    Log::warning('DAO notification delivery failed', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        };

        // Terminating callbacks accumulate across requests inside one test
        // (and would re-fire), so only defer in real HTTP lifecycles.
        if (app()->runningUnitTests()) {
            $deliver();

            return;
        }

        dispatch($deliver)->afterResponse();
    }

    private function actorName(User $actor): string
    {
        return $actor->name
            ?: ($actor->wallet_address
                ? substr($actor->wallet_address, 0, 6).'…'.substr($actor->wallet_address, -4)
                : 'Someone');
    }
}
