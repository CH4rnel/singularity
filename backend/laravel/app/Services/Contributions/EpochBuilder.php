<?php

namespace App\Services\Contributions;

use App\Models\Contribution;
use App\Models\ContributionEpoch;
use App\Models\ContributionReward;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns one closed period of counted contributions into a Merkle tree.
 *
 * Who is paid is decided by the GitHub account → site account → EVM wallet
 * chain at the moment of the build. Work by somebody who has not linked a
 * wallet yet is not lost and not paid to anybody else: it stays unassigned
 * and rolls into the next epoch, so linking late costs a contributor time and
 * never money. For the same reason the first epoch carries everything counted
 * before it — the retroactive reward for the work that built the project.
 *
 * A built epoch is a draft: rebuilding it is safe, and nothing is claimable
 * until its root is published to CyberiaContributorRewards and marked here.
 * A published epoch is never rebuilt.
 */
class EpochBuilder
{
    public function __construct(private readonly EpochAllocator $allocator) {}

    /**
     * The period the next build covers.
     *
     * @return array{number: int, starts_at: Carbon, ends_at: Carbon}
     */
    public function nextPeriod(): array
    {
        $last = ContributionEpoch::query()->orderByDesc('number')->first();
        $startsAt = $last
            ? Carbon::parse($last->ends_at)
            : Carbon::parse((string) config('contributions.epochs.first_starts_at'), 'UTC');

        return [
            'number' => ($last->number ?? 0) + 1,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addDays(max(1, (int) config('contributions.epochs.length_days', 30))),
        ];
    }

    public static function poolRaw(): string
    {
        $amount = trim((string) config('contributions.pool.amount', '0'));

        if (preg_match('/^\d+(\.\d+)?$/', $amount) !== 1) {
            throw new RuntimeException('contributions.pool.amount is not a number.');
        }

        return (string) BigDecimal::of($amount)
            ->multipliedBy(BigDecimal::ten()->power((int) config('contributions.pool.decimals', 18)))
            ->toScale(0, RoundingMode::DOWN);
    }

    /**
     * Build (or rebuild) an epoch. With no number, the next one — and only
     * once its period has ended, because a tree over a period still open
     * would be a promise about work nobody has done yet.
     */
    public function build(?int $number = null): ContributionEpoch
    {
        $poolRaw = self::poolRaw();

        if ($poolRaw === '0') {
            throw new RuntimeException('No pool is configured (CONTRIBUTIONS_POOL_AMOUNT). Nothing would be paid.');
        }

        $existing = $number !== null ? ContributionEpoch::query()->where('number', $number)->first() : null;

        if ($number !== null && $existing === null && $number !== $this->nextPeriod()['number']) {
            throw new RuntimeException("Epoch {$number} does not exist and is not the next one.");
        }

        if ($existing?->status === ContributionEpoch::PUBLISHED) {
            throw new RuntimeException("Epoch {$number} is published; its root is on chain and cannot change.");
        }

        $period = $existing
            ? ['number' => $existing->number, 'starts_at' => Carbon::parse($existing->starts_at), 'ends_at' => Carbon::parse($existing->ends_at)]
            : $this->nextPeriod();

        if ($period['ends_at']->isFuture()) {
            throw new RuntimeException("Epoch {$period['number']} is still open until {$period['ends_at']->toDateTimeString()} UTC.");
        }

        return DB::transaction(function () use ($existing, $period, $poolRaw): ContributionEpoch {
            $epoch = $existing ?? new ContributionEpoch;

            if ($existing !== null) {
                $existing->rewards()->delete();
                Contribution::query()->where('contribution_epoch_id', $existing->id)->update(['contribution_epoch_id' => null]);
            }

            $epoch->fill([
                'number' => $period['number'],
                'starts_at' => $period['starts_at'],
                'ends_at' => $period['ends_at'],
                'asset' => (string) config('contributions.pool.asset', 'native'),
                'decimals' => (int) config('contributions.pool.decimals', 18),
                'pool_raw' => $poolRaw,
                'status' => ContributionEpoch::BUILT,
            ])->save();

            $contributions = Contribution::query()
                ->where('status', Contribution::COUNTED)
                ->whereNull('contribution_epoch_id')
                ->where('accepted_at', '<', $period['ends_at'])
                ->where('points', '>', 0)
                ->get(['id', 'github_id', 'points']);

            $wallets = $this->wallets($contributions->pluck('github_id')->unique()->values()->all());

            $pointsByAddress = [];
            $owners = [];

            foreach ($contributions as $contribution) {
                $wallet = $wallets[$contribution->github_id] ?? null;

                if ($wallet === null) {
                    continue;
                }

                $pointsByAddress[$wallet['address']] = ($pointsByAddress[$wallet['address']] ?? 0) + $contribution->points;
                $owners[$wallet['address']] ??= ['user_id' => $wallet['user_id'], 'github_ids' => []];
                $owners[$wallet['address']]['github_ids'][$contribution->github_id] = true;
            }

            $shares = $this->allocator->allocate(
                $poolRaw,
                $pointsByAddress,
                (int) config('contributions.pool.max_share_bps', 2500),
                (int) round((float) config('contributions.pool.min_points', 0) * 100),
            );

            $allocated = BigDecimal::zero();
            $root = null;

            if ($shares !== []) {
                $leaves = [];

                foreach ($shares as $address => $amount) {
                    $leaves[$address] = MerkleTree::leaf($epoch->number, $address, $amount);
                    $allocated = $allocated->plus($amount);
                }

                $tree = new MerkleTree(array_values($leaves));
                $root = $tree->root();

                foreach ($shares as $address => $amount) {
                    ContributionReward::query()->create([
                        'contribution_epoch_id' => $epoch->id,
                        'user_id' => $owners[$address]['user_id'],
                        'github_id' => (string) array_key_first($owners[$address]['github_ids']),
                        'address' => $address,
                        'points' => $pointsByAddress[$address],
                        'amount_raw' => $amount,
                        'leaf' => $leaves[$address],
                        'proof' => $tree->proof($leaves[$address]),
                    ]);
                }

                // Only the work that was actually paid is pinned to this epoch;
                // a contributor under the minimum keeps their points for the next.
                $paidGithubIds = [];

                foreach (array_keys($shares) as $address) {
                    array_push($paidGithubIds, ...array_map('strval', array_keys($owners[$address]['github_ids'])));
                }

                Contribution::query()
                    ->whereIn('id', $contributions->whereIn('github_id', $paidGithubIds)->pluck('id'))
                    ->update(['contribution_epoch_id' => $epoch->id]);
            }

            $epoch->forceFill([
                'merkle_root' => $root,
                'allocated_raw' => (string) $allocated,
                'total_points' => array_sum(array_intersect_key($pointsByAddress, $shares)),
            ])->save();

            return $epoch->refresh();
        });
    }

    public function markPublished(int $number, string $txHash): ContributionEpoch
    {
        $epoch = ContributionEpoch::query()->where('number', $number)->firstOrFail();

        if ($epoch->merkle_root === null) {
            throw new RuntimeException("Epoch {$number} has no root to publish.");
        }

        if (preg_match('/^0x[0-9a-fA-F]{64}$/', $txHash) !== 1) {
            throw new RuntimeException('Not a transaction hash.');
        }

        $epoch->forceFill([
            'status' => ContributionEpoch::PUBLISHED,
            'publish_tx' => strtolower($txHash),
            'published_at' => now(),
        ])->save();

        return $epoch;
    }

    /**
     * GitHub id => the linked account's EVM wallet.
     *
     * @param  list<string>  $githubIds
     * @return array<string, array{address: string, user_id: int}>
     */
    private function wallets(array $githubIds): array
    {
        if ($githubIds === []) {
            return [];
        }

        $out = [];

        User::query()
            ->whereIn('github_id', $githubIds)
            ->whereNull('merged_into_id')
            ->whereNotNull('wallet_address')
            ->get(['id', 'github_id', 'wallet_address'])
            ->each(function (User $user) use (&$out): void {
                $address = strtolower((string) $user->wallet_address);

                if (preg_match('/^0x[0-9a-f]{40}$/', $address) === 1) {
                    $out[(string) $user->github_id] = ['address' => $address, 'user_id' => (int) $user->id];
                }
            });

        return $out;
    }
}
