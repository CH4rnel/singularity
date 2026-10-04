<?php

namespace App\Services\Dao;

use App\Models\Proposal;
use App\Models\ProposalSnapshot;
use App\Models\ProposalVote;
use App\Models\User;
use App\Services\TokenSnapshotService;

/**
 * Casting a vote on a proposal: one rule, whichever screen pressed the button.
 *
 * The site's vote panel and the wallet's proposal screen both end here, so the
 * weight of a vote cannot depend on where it was cast. Power is the address's
 * balance of the DAO's token at the moment of its first vote on that proposal
 * (the snapshot), read once and then reused, so changing a vote never re-reads
 * a balance that may have moved since.
 */
class ProposalVoter
{
    public function __construct(
        private TokenSnapshotService $snapshotService,
        private ActivityRecorder $activityRecorder,
        private DaoNotifier $notifier,
    ) {}

    public function cast(
        User $user,
        Proposal $proposal,
        string $walletAddress,
        bool $support,
        float|int|string $fallbackPower = 1,
    ): ProposalVote {
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

        $vote = $proposal->votes()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'wallet_address' => $walletAddress,
                'voting_power' => $snapshot?->balance ?? $fallbackPower,
                'support' => $support,
            ],
        );

        // Only a first-time vote lands in the feed — re-votes would spam it.
        if ($vote->wasRecentlyCreated) {
            $this->activityRecorder->record('vote.cast', $user, $vote, $proposal->dao);
            $this->notifier->voteCast($vote);
        }

        return $vote;
    }
}
