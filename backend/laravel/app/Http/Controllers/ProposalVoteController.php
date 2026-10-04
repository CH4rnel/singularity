<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProposalVoteRequest;
use App\Models\Proposal;
use App\Services\Dao\ProposalVoter;
use Illuminate\Http\RedirectResponse;

class ProposalVoteController extends Controller
{
    public function store(StoreProposalVoteRequest $request, Proposal $proposal, ProposalVoter $voter): RedirectResponse
    {
        abort_unless($proposal->isOpen(), 403, 'Voting is closed for this proposal.');

        $voter->cast(
            $request->user(),
            $proposal,
            $request->validated('wallet_address'),
            (bool) $request->validated('support'),
            $request->validated('voting_power', 1),
        );

        return back()->with('success', 'Vote recorded');
    }
}
