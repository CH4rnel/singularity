<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\ProposalVote;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\GamificationService;
use App\Services\ProfileOnchainService;
use App\Support\ProfileHandle;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class UserProfileController extends Controller
{
    public function show(
        Request $request,
        User $user,
        GamificationService $gamification,
        ProfileOnchainService $onchain,
    ): Response {
        $onchain->syncNickname($user);

        return $this->renderProfile($user, $gamification, $request->user());
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

        return $this->renderProfile($user, $gamification, $request->user());
    }

    /**
     * The badges this wallet holds on-chain.
     *
     * Cached briefly: it is an eth_call, the answer changes when somebody
     * earns a badge, and a profile page is the sort of address that gets
     * refreshed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function achievements(User $user): array
    {
        if (! $user->wallet_address) {
            return [];
        }

        return Cache::remember(
            'profile:achievements:'.Str::lower($user->wallet_address),
            now()->addMinutes(5),
            fn (): array => array_values(array_filter(
                app(AchievementService::class)->forProfile($user),
                fn (array $definition): bool => (bool) $definition['earned'],
            )),
        );
    }

    /**
     * What this address has done on the chain, as the indexer saw it.
     *
     * `activity_events` is the Telegram bot's announcer feed and is
     * forward-only — each announcer starts at the head of the chain the first
     * time it runs — so these are the recent actions and not a lifetime
     * record. The page says so rather than implying a total.
     *
     * @return array{events: array<int, array<string, mixed>>, kinds: array<string, int>, since: ?string}
     */
    private function onchain(User $user): array
    {
        if (! $user->wallet_address || ! Schema::hasTable('activity_events')) {
            return ['events' => [], 'kinds' => [], 'since' => null];
        }

        $address = Str::lower($user->wallet_address);

        $rows = DB::table('activity_events')
            ->whereRaw('LOWER(user_addr) = ?', [$address])
            ->orderByDesc('id')
            ->limit(20)
            ->get(['kind', 'usd', 'sym_in', 'amt_in', 'sym_out', 'amt_out', 'tx_hash', 'created_at']);

        $kinds = DB::table('activity_events')
            ->whereRaw('LOWER(user_addr) = ?', [$address])
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->map(fn ($total): int => (int) $total)
            ->all();

        return [
            'events' => $rows->map(fn ($row): array => [
                'kind' => (string) $row->kind,
                'usd' => $row->usd === null ? null : round((float) $row->usd, 2),
                'in' => $row->sym_in === null ? null : trim(($row->amt_in === null ? '' : rtrim(rtrim(number_format((float) $row->amt_in, 4, '.', ''), '0'), '.').' ').$row->sym_in),
                'out' => $row->sym_out === null ? null : trim(($row->amt_out === null ? '' : rtrim(rtrim(number_format((float) $row->amt_out, 4, '.', ''), '0'), '.').' ').$row->sym_out),
                'tx' => (string) $row->tx_hash,
                'at' => (string) $row->created_at,
            ])->all(),
            'kinds' => $kinds,
            // What the feed can see at all, so "3 swaps" is readable as
            // "3 swaps since then" rather than "3 swaps ever".
            'since' => DB::table('activity_events')->min('created_at'),
        ];
    }

    private function renderProfile(
        User $user,
        GamificationService $gamification,
        ?User $viewer,
    ): Response {
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
                'is_following' => $viewer?->following()
                    ->whereKey($user->id)
                    ->exists() ?? false,
            ],
            'stats' => [
                'posts' => $user->posts_count,
                'proposals' => $user->proposals_count,
                'votes' => $user->votes_count,
                'comments' => $user->comments_count,
            ],
            /*
             * The chain half of this profile, which is what the page was
             * missing: it showed a wall and a DAO feed on a platform whose
             * whole point is what happens on the chain, so somebody with
             * hundreds of swaps looked like they had done nothing.
             *
             * Two sources, and they answer different questions. Achievements
             * are read from CyberiaProfile — permanent, public, and true about
             * all of history. The event list is the indexer's feed, which is
             * forward-only and started in August; it is labelled as recent
             * rather than presented as a complete record.
             */
            'achievements' => $this->achievements($user),
            'onchain' => $this->onchain($user),
            'unlocks' => $gamification->owned($user),
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
