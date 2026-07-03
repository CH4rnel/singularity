<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProposalCommentRequest;
use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Services\Dao\ActivityRecorder;
use App\Services\Dao\DaoNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProposalCommentController extends Controller
{
    public function __construct(
        private ActivityRecorder $activityRecorder,
        private DaoNotifier $notifier,
    ) {}

    public function store(StoreProposalCommentRequest $request, Proposal $proposal): RedirectResponse
    {
        $comment = $proposal->comments()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        $this->activityRecorder->record(
            'comment.posted',
            $request->user(),
            $comment,
            $proposal->dao,
        );

        $this->notifier->commentPosted($comment);

        return back()->with('success', 'Comment added');
    }

    public function destroy(ProposalComment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $comment->delete();

        return back()->with('success', 'Comment deleted');
    }
}
