<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\ContributionEpoch;
use App\Models\ContributionRepository;
use App\Models\ContributionReward;
use App\Models\User;
use App\Services\Contributions\EpochBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The open-source reward engine, read from outside.
 *
 * `show` is the board everybody sees — the rules, the pool, the repositories
 * and who is ahead — plus, for a signed-in account, its own standing: whether
 * GitHub and a wallet are linked (both are needed to be paid), every
 * contribution with its verdict, and points still waiting for an epoch.
 *
 * `claims` is keyed by address and needs no session: a proof pays only the
 * address in its leaf, so publishing it costs nothing, and it lets any wallet
 * claim without signing in first. Only leaves of *published* epochs are
 * claimable; a built epoch's leaves are a draft and are not sent at all.
 */
class ContributionsController extends Controller
{
    private const BOARD_LIMIT = 25;

    private const BOARD_TTL = 300;

    public function show(Request $request): JsonResponse
    {
        $board = Cache::remember('contributions.board', self::BOARD_TTL, fn (): array => $this->board());

        return response()->json($board + ['me' => $this->me($request->user())]);
    }

    public function claims(string $address): JsonResponse
    {
        $address = strtolower($address);

        if (preg_match('/^0x[0-9a-f]{40}$/', $address) !== 1) {
            return response()->json(['message' => 'Not an EVM address.'], 422);
        }

        $rewards = ContributionReward::query()
            ->where('address', $address)
            ->whereHas('epoch', fn ($query) => $query->where('status', ContributionEpoch::PUBLISHED))
            ->with('epoch:id,number,asset,decimals,merkle_root')
            ->orderBy('contribution_epoch_id')
            ->get();

        return response()->json([
            'address' => $address,
            'contract' => $this->contract(),
            'claims' => $rewards->map(fn (ContributionReward $reward): array => [
                'epoch' => $reward->epoch->number,
                'asset' => $reward->epoch->asset,
                'decimals' => $reward->epoch->decimals,
                'amountRaw' => $reward->amount_raw,
                'points' => $reward->points / 100,
                'root' => $reward->epoch->merkle_root,
                'proof' => $reward->proof,
                'claimedAt' => $reward->claimed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function board(): array
    {
        $next = app(EpochBuilder::class)->nextPeriod();

        $leaders = Contribution::query()
            ->where('status', Contribution::COUNTED)
            ->select('github_id', DB::raw('max(login) as login'), DB::raw('sum(points) as points'), DB::raw('count(*) as contributions'))
            ->groupBy('github_id')
            ->orderByDesc('points')
            ->limit(self::BOARD_LIMIT)
            ->get();

        $linked = User::query()
            ->whereIn('github_id', $leaders->pluck('github_id'))
            ->whereNotNull('wallet_address')
            ->pluck('github_id')
            ->flip();

        return [
            'pool' => [
                'asset' => (string) config('contributions.pool.asset'),
                'symbol' => (string) config('contributions.pool.symbol'),
                'decimals' => (int) config('contributions.pool.decimals'),
                // The human figure, and null rather than "0" when nothing is
                // configured: an unset pool is undecided, not empty.
                'perEpoch' => EpochBuilder::poolRaw() === '0' ? null : (string) config('contributions.pool.amount'),
                'maxShareBps' => (int) config('contributions.pool.max_share_bps'),
                'minPoints' => (float) config('contributions.pool.min_points'),
            ],
            'rules' => [
                'minAccountAgeDays' => (int) config('contributions.sybil.min_account_age_days'),
                'minChangedLines' => (int) config('contributions.points.min_changed_lines'),
                'maxPerDay' => (int) config('contributions.sybil.max_per_day'),
                'refuseSelfMerged' => (bool) config('contributions.sybil.refuse_self_merged'),
                'issueLabels' => array_values((array) config('contributions.points.issue_labels')),
                'verificationFile' => (string) config('contributions.verification_file'),
                'points' => [
                    'pullRequest' => (float) config('contributions.points.pull_request'),
                    'documentation' => (float) config('contributions.points.documentation'),
                    'issue' => (float) config('contributions.points.issue'),
                    'maxSizeMultiplier' => (float) config('contributions.points.max_size_multiplier'),
                ],
            ],
            'nextEpoch' => [
                'number' => $next['number'],
                'startsAt' => $next['starts_at']->toIso8601String(),
                'endsAt' => $next['ends_at']->toIso8601String(),
            ],
            'contract' => $this->contract(),
            'repositories' => ContributionRepository::query()
                ->where('active', true)
                ->orderBy('full_name')
                ->get()
                ->map(fn (ContributionRepository $repository): array => [
                    'name' => $repository->full_name,
                    'url' => $repository->url(),
                    'verified' => $repository->verified_at !== null,
                    'weight' => $repository->weight,
                ])->values(),
            'leaders' => $leaders->map(fn ($row): array => [
                'login' => (string) $row->login,
                'points' => (int) $row->points / 100,
                'contributions' => (int) $row->contributions,
                'walletLinked' => $linked->has($row->github_id),
            ])->values(),
            'epochs' => ContributionEpoch::query()
                ->orderByDesc('number')
                ->limit(12)
                ->get()
                ->map(fn (ContributionEpoch $epoch): array => [
                    'number' => $epoch->number,
                    'startsAt' => $epoch->starts_at->toIso8601String(),
                    'endsAt' => $epoch->ends_at->toIso8601String(),
                    'status' => $epoch->status,
                    'allocatedRaw' => $epoch->allocated_raw,
                    'decimals' => $epoch->decimals,
                    'root' => $epoch->status === ContributionEpoch::PUBLISHED ? $epoch->merkle_root : null,
                    'publishTx' => $epoch->publish_tx,
                ])->values(),
        ];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function me(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $githubId = $user->github_id !== null ? (string) $user->github_id : null;

        $contributions = $githubId === null ? collect() : Contribution::query()
            ->where('github_id', $githubId)
            ->with(['repository:id,full_name', 'epoch:id,number'])
            ->orderByDesc('accepted_at')
            ->limit(100)
            ->get();

        return [
            'github' => $githubId === null ? null : ['login' => $user->github_username],
            'linkUrl' => route('settings.connections.github.redirect'),
            'wallet' => $user->wallet_address !== null ? strtolower((string) $user->wallet_address) : null,
            // Counted and not yet in any epoch: what the next build will see.
            'pendingPoints' => $githubId === null ? 0 : Contribution::query()
                ->where('github_id', $githubId)
                ->where('status', Contribution::COUNTED)
                ->whereNull('contribution_epoch_id')
                ->sum('points') / 100,
            'contributions' => $contributions->map(fn (Contribution $contribution): array => [
                'repository' => $contribution->repository?->full_name,
                'kind' => $contribution->kind,
                'number' => $contribution->number,
                'title' => $contribution->title,
                'url' => $contribution->url,
                'acceptedAt' => $contribution->accepted_at->toIso8601String(),
                'points' => $contribution->points / 100,
                'status' => $contribution->status,
                'reason' => $contribution->reason,
                'epoch' => $contribution->epoch?->number,
            ])->values(),
        ];
    }

    /**
     * @return array{address: ?string, chainId: int}
     */
    private function contract(): array
    {
        $address = (string) config('contributions.contract.address');

        return [
            'address' => preg_match('/^0x[0-9a-fA-F]{40}$/', $address) === 1 ? $address : null,
            'chainId' => (int) config('contributions.contract.chain_id'),
        ];
    }
}
