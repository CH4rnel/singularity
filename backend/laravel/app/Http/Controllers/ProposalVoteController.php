<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProposalVoteRequest;
use App\Models\Proposal;
use App\Models\ProposalSnapshot;
use App\Services\Dao\ActivityRecorder;
use App\Services\Dao\DaoNotifier;
use App\Services\TokenSnapshotService;
use Illuminate\Http\RedirectResponse;

class ProposalVoteController extends Controller
{
    public function __construct(
        private TokenSnapshotService $snapshotService,
        private ActivityRecorder $activityRecorder,
        private DaoNotifier $notifier,
    ) {}

    public function store(StoreProposalVoteRequest $request, Proposal $proposal): RedirectResponse
    {
        abort_unless($proposal->isOpen(), 403, 'Voting is closed for this proposal.');

        $walletAddress = $request->validated('wallet_address');
        $support = $request->validated('support');
        $fallbackPower = $request->validated('voting_power', 1);

        // EVM addresses are case-insensitive hex and are looked up/stored
        // lowercased; Solana (e.g. Phantom) addresses are case-sensitive
        // base58 — lowercasing would corrupt them, and their balance can't
        // be read from the EVM RPC, so they always fall back to $fallbackPower.
        $isEvmAddress = (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress);
        $snapshotKey = $isEvmAddress ? strtolower($walletAddress) : $walletAddress;

        $snapshot = ProposalSnapshot::where('proposal_id', $proposal->id)
            ->where('wallet_address', $snapshotKey)
            ->first();

        if (! $snapshot && $isEvmAddress && $proposal->dao?->address) {
            $daoAddress = $proposal->dao->address;
            $isNative = $this->snapshotService->isNativeToken($daoAddress);

            $balance = $isNative
                ? $this->snapshotService->getNativeBalance($walletAddress)
                : $this->snapshotService->getTokenBalance($daoAddress, $walletAddress);

            $snapshot = ProposalSnapshot::create([
                'proposal_id' => $proposal->id,
                'wallet_address' => $snapshotKey,
                'balance' => $balance,
                'snapshot_at' => now(),
            ]);
        }

        $votingPower = $snapshot?->balance ?? $fallbackPower;

        $vote = $proposal->votes()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'wallet_address' => $walletAddress,
                'voting_power' => $votingPower,
                'support' => $support,
            ],
        );

        // Only a first-time vote lands in the feed — re-votes would spam it.
        if ($vote->wasRecentlyCreated) {
            $this->activityRecorder->record(
                'vote.cast',
                $request->user(),
                $vote,
                $proposal->dao,
            );

            $this->notifier->voteCast($vote);
        }

        return back()->with('success', 'Vote recorded');
    }
}
