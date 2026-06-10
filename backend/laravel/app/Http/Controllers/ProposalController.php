<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProposalRequest;
use App\Http\Requests\UpdateProposalRequest;
use App\Models\Dao;
use App\Models\Proposal;
use App\Services\TokenSnapshotService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProposalController extends Controller
{
    public function __construct(private TokenSnapshotService $snapshotService) {}

    public function index(Dao $dao)
    {
        return Inertia::render('dao/Show', [
            'dao' => $dao,
            'proposals' => $dao->proposals()
                ->with(['user', 'votes'])
                ->withCount(['comments'])
                ->withSum('votesFor as power_for', 'voting_power')
                ->withSum('votesAgainst as power_against', 'voting_power')
                ->latest()
                ->get(),
        ]);
    }

    public function show(Proposal $proposal)
    {
        $proposal->load([
            'dao',
            'user',
            'comments.user',
            'votes.user',
        ]);

        $proposal->loadSum('votesFor as power_for', 'voting_power');
        $proposal->loadSum('votesAgainst as power_against', 'voting_power');

        return Inertia::render('proposals/Show', [
            'proposal' => $proposal,
            'userVote' => auth()->check()
                ? $proposal->votes()->where('user_id', auth()->id())->first()
                : null,
        ]);
    }

    public function store(StoreProposalRequest $request): RedirectResponse
    {
        $proposal = Proposal::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        $this->snapshotService->createSnapshot($proposal);

        return back()->with('success', 'Proposal created');
    }

    public function update(UpdateProposalRequest $request, Proposal $proposal): RedirectResponse
    {
        $proposal->update($request->validated());

        return back()->with('success', 'Proposal updated');
    }

    public function destroy(Proposal $proposal): RedirectResponse
    {
        $proposal->delete();

        return back()->with('success', 'Proposal deleted');
    }
}
