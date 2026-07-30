<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\ProposalVote;
use App\Models\User;
use App\Services\GamificationService;
use App\Services\ProfileOnchainService;
use App\Support\ProfileHandle;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class UserProfileController extends Controller
{
    public function show(
        User $user,
        GamificationService $gamification,
        ProfileOnchainService $onchain,
    ): Response {
        $onchain->syncNickname($user);

        return $this->renderProfile($user, $gamification);
    }

    public function legacy(
        Request $request,
        User $user,
        GamificationService $gamification,
        ProfileOnchainService $onchain,
    ): RedirectResponse|Response {
        $onchain->syncNickname($user);

        if (ProfileHandle::isCanonical($user->onchain_nickname)) {
            return redirect()->route(
                'users.show',
                [...$request->query(), 'user' => $user->onchain_nickname],
                HttpResponse::HTTP_MOVED_PERMANENTLY,
            );
        }

        return $this->renderProfile($user, $gamification);
    }

    private function renderProfile(User $user, GamificationService $gamification): Response
    {
        $user->loadCount([
            'posts',
            'proposals',
            'proposalVotes as votes_count',
            'proposalComments as comments_count',
        ]);

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
                'posts' => $user->posts_count,
                'proposals' => $user->proposals_count,
                'votes' => $user->votes_count,
                'comments' => $user->comments_count,
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
