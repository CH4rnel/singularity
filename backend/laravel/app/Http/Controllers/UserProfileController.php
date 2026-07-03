<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\ProposalVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Inertia\Inertia;

class UserProfileController extends Controller
{
    public function show(User $user)
    {
        return Inertia::render('users/Show', [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'wallet_address' => $user->wallet_address,
                'solana_wallet_address' => $user->solana_wallet_address,
                'created_at' => $user->created_at?->toISOString(),
            ],
            'stats' => [
                'proposals' => $user->proposals()->count(),
                'votes' => $user->proposalVotes()->count(),
                'comments' => $user->proposalComments()->count(),
            ],
            'activities' => $user->activities()
                ->with([
                    'user:id,name,wallet_address',
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
                ->paginate(20),
        ]);
    }
}
