<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProposalRequest;
use App\Http\Requests\UpdateProposalRequest;
use App\Jobs\CreateProposalSnapshot;
use App\Models\Proposal;
use App\Services\Dao\ActivityRecorder;
use App\Services\Dao\DaoNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProposalController extends Controller
{
    public function __construct(
        private ActivityRecorder $activityRecorder,
        private DaoNotifier $notifier,
    ) {}

    public function show(Proposal $proposal)
    {
        $proposal->load([
            'dao',
            'user:id,name,onchain_nickname,avatar_path,wallet_address',
            'votes.user:id,name,onchain_nickname,avatar_path,wallet_address',
            'reactions:id,user_id,reactable_type,reactable_id,emoji',
        ]);

        $proposal->loadSum('votesFor as power_for', 'voting_power');
        $proposal->loadSum('votesAgainst as power_against', 'voting_power');

        return Inertia::render('proposals/Show', [
            'proposal' => $proposal,
            'comments' => $proposal->comments()
                ->whereNull('parent_id')
                ->with([
                    'user:id,name,onchain_nickname,avatar_path,wallet_address',
                    'reactions:id,user_id,reactable_type,reactable_id,emoji',
                    'replies.user:id,name,onchain_nickname,avatar_path,wallet_address',
                    'replies.reactions:id,user_id,reactable_type,reactable_id,emoji',
                ])
                ->latest()
                ->paginate(20),
            'userVote' => auth()->check()
                ? $proposal->votes()->where('user_id', auth()->id())->first()
                : null,
        ]);
    }

    public function store(StoreProposalRequest $request): RedirectResponse
    {
        $proposal = Proposal::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        $this->activityRecorder->record(
            'proposal.created',
            $request->user(),
            $proposal,
            $proposal->dao,
        );

        // Heavy holder scan runs after the response; see the job docblock.
        CreateProposalSnapshot::dispatchAfterResponse($proposal);

        $this->notifier->proposalCreated($proposal);

        return back()->with('success', 'Proposal created');
    }

    public function update(UpdateProposalRequest $request, Proposal $proposal): RedirectResponse
    {
        Gate::authorize('update', $proposal);

        $proposal->update($request->validated());

        return back()->with('success', 'Proposal updated');
    }

    /**
     * End the vote now, without waiting for the deadline.
     *
     * The deadline closes a proposal on its own; this is the lever for the
     * cases it cannot answer — a question that was settled early, one nobody
     * is voting on any more, and the proposals that predate deadlines
     * entirely. It only ever moves the end *closer*: a proposal that has
     * already closed is left exactly as it is, so pressing this twice cannot
     * rewrite when the vote ended.
     */
    public function close(Request $request, Proposal $proposal): RedirectResponse
    {
        Gate::authorize('close', $proposal);

        if (! $proposal->isOpen()) {
            return back()->with('success', 'Voting was already closed');
        }

        $proposal->update(['ends_at' => now()]);

        $this->activityRecorder->record(
            'proposal.closed',
            $request->user(),
            $proposal,
            $proposal->dao,
        );

        return back()->with('success', 'Voting closed');
    }

    public function destroy(Proposal $proposal): RedirectResponse
    {
        Gate::authorize('delete', $proposal);

        $proposal->delete();

        return redirect()
            ->route('dao.show', $proposal->dao_id)
            ->with('success', 'Proposal deleted');
    }
}
