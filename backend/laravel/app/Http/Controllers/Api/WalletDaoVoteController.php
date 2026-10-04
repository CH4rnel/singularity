<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\ProposalVote;
use App\Services\Dao\ProposalPublisher;
use App\Services\Dao\ProposalVoter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Voting, and proposing, from inside the wallet.
 *
 * The wallet used to show a proposal and then send people to the site to vote
 * on it — on a phone that meant leaving the app for a page that wants a
 * browser wallet the phone does not have. A vote needs an account, and the
 * wallet gets one the way the feed composer does: it signs the site's login
 * challenge with the active key. After that this is an ordinary authenticated
 * write, through the same `ProposalVoter` the site's panel uses, so a vote
 * weighs the same wherever it was cast.
 *
 * Unlike the site's form, nothing here takes an address or a voting power from
 * the request: the vote is cast as the address this session signed in with,
 * and its weight is that address's snapshot. The address the wallet *thinks*
 * it is voting as is sent only to be compared — a browser still signed in as a
 * different account gets a 409 and re-signs, rather than voting as somebody
 * the screen is not showing.
 */
class WalletDaoVoteController extends Controller
{
    /**
     * Put a proposal up from the wallet.
     *
     * The same rules as the site's form (`StoreProposalRequest`) and the same
     * sequence after it (`ProposalPublisher`). The address check is the vote's:
     * the author is whoever this session signed in as, and a session as some
     * other key is refused rather than publishing under the wrong name.
     */
    public function propose(Request $request, ProposalPublisher $publisher): JsonResponse
    {
        $validated = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'dao_id' => ['required', 'exists:daos,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'ends_at' => ['required', 'date', 'after:now'],
        ]);

        $user = $request->user();

        if ($user->wallet_address === null
            || strcasecmp($user->wallet_address, $validated['address']) !== 0) {
            return response()->json([
                'message' => 'This browser is signed in as a different wallet.',
                'reason' => 'otherAccount',
            ], 409);
        }

        unset($validated['address']);
        $proposal = $publisher->publish($user, $validated);

        return response()->json(['id' => $proposal->id], 201);
    }

    /** This account's vote on a proposal, or null when it has not voted. */
    public function show(Request $request, Proposal $proposal): JsonResponse
    {
        $vote = $proposal->votes()->where('user_id', $request->user()->id)->first();

        return response()->json([
            'address' => $request->user()->wallet_address,
            'vote' => $this->present($vote),
        ]);
    }

    public function store(Request $request, Proposal $proposal, ProposalVoter $voter): JsonResponse
    {
        $validated = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'support' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        if ($user->wallet_address === null
            || strcasecmp($user->wallet_address, $validated['address']) !== 0) {
            return response()->json([
                'message' => 'This browser is signed in as a different wallet.',
                'reason' => 'otherAccount',
            ], 409);
        }

        if (! $proposal->isOpen()) {
            return response()->json([
                'message' => 'Voting is closed for this proposal.',
                'reason' => 'closed',
            ], 422);
        }

        $vote = $voter->cast($user, $proposal, $user->wallet_address, (bool) $validated['support']);

        return response()->json(['vote' => $this->present($vote)]);
    }

    /** @return array{support: bool, power: string}|null */
    private function present(?ProposalVote $vote): ?array
    {
        return $vote === null ? null : [
            'support' => (bool) $vote->support,
            // A decimal(*,18): a string, like the tally it is part of.
            'power' => (string) $vote->voting_power,
        ];
    }
}
