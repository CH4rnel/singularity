<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\ProposalVote;
use App\Models\User;
use App\Services\GamificationService;
use App\Services\ProfileOnchainService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Inertia\Inertia;

class UserProfileController extends Controller
{
    public function show(
        User $user,
        GamificationService $gamification,
        ProfileOnchainService $onchain,
    ) {
        $onchain->syncNickname($user);

        return Inertia::render('users/Show', [
            // Public standing only — no quest board, no XP ledger.
            'progress' => $gamification->publicProgressFor($user),
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'wallet_address' => $user->wallet_address,
                'solana_wallet_address' => $user->solana_wallet_address,
                'created_at' => $user->created_at?->toISOString(),
            ],
            'stats' => [
                'posts' => $user->posts()->count(),
                'proposals' => $user->proposals()->count(),
                'votes' => $user->proposalVotes()->count(),
                'comments' => $user->proposalComments()->count(),
            ],
            'posts' => $user->posts()
                ->with(['user:id,name,onchain_nickname,avatar_path,wallet_address'])
                ->latest('id')
                ->paginate(10, pageName: 'posts'),
            'activities' => $user->activities()
                ->with([
                    'user:id,name,onchain_nickname,avatar_path,wallet_address',
                    'dao:id,name',
                    'subject' => function (MorphTo $morphTo) {
                        $morphTo->morphWith([
                            Proposal::class => ['dao:id,name'],
                            ProposalComment::class => ['proposal:id,title'],
                            ProposalVote::class => ['proposal:id,title'],
                        ]);
                    },
                ])
                ->latest('id')
                ->paginate(20, pageName: 'activities'),
        ]);
    }
}
