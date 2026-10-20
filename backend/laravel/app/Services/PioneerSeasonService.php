<?php

namespace App\Services;

use App\Models\BridgeRequest;
use App\Models\PioneerCheckpoint;
use App\Models\PioneerFinisher;
use App\Models\User;
use App\Support\ProfileHandle;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Pioneer Season verifier: five checkpoints, each answered from a record
 * this platform already keeps, never from what a browser says.
 *
 *   01 wallet     — a wallet bound to the profile by its signature
 *   02 bridge     — a completed bridge request with a hash on both sides
 *   03 swap       — an indexed swap at or above the season minimum
 *   04 liquidity  — an indexed liq_add or stake at or above the minimum
 *   05 return     — another qualifying action on a later UTC date, and at
 *                   least `return_after_hours` after the first one
 *
 * Every checkpoint is a row keyed (season, user, checkpoint), so it is passed
 * once per profile however many wallets it holds, and (season, checkpoint,
 * reference), so one transaction credits one profile. Verification is
 * idempotent: it is run on every visit to the page and by `pioneer:verify`,
 * and a rerun records and pays nothing new.
 *
 * Round trips do not count: a swap whose reverse the same address made within
 * `round_trip_minutes`, and a routed swap that comes back to the asset it
 * started from. Team and treasury addresses take no part at all.
 *
 * A profile that passes them all becomes a finisher. The first `review_first`
 * finishers wait for a person; after that, approval is automatic. Approval
 * pays the completion XP and makes the on-chain Pioneer badge owing, which
 * the hourly `achievements:sync` then mints.
 */
class PioneerSeasonService
{
    public const WALLET = 1;

    public const BRIDGE = 2;

    public const SWAP = 3;

    public const LIQUIDITY = 4;

    public const RETURN = 5;

    public function __construct(private readonly GamificationService $gamification) {}

    /**
     * @return array<int, array{number: int, key: string, title: string, description: string, verifier: string}>
     */
    public function definitions(): array
    {
        return [
            self::WALLET => ['number' => self::WALLET, 'key' => 'wallet', 'title' => 'Connect a wallet', 'description' => 'Sign in with a wallet, so later on-chain actions can be matched to you.', 'verifier' => 'Wallet ownership signature bound to your profile'],
            self::BRIDGE => ['number' => self::BRIDGE, 'key' => 'bridge', 'title' => 'Complete a bridge', 'description' => 'Bridge into or out of Cyberia and have it settle on both sides.', 'verifier' => 'Completed bridge request with source and destination hashes'],
            self::SWAP => ['number' => self::SWAP, 'key' => 'swap', 'title' => 'Complete a swap', 'description' => 'Swap on Cyberia above the season minimum.', 'verifier' => 'Indexed Cyberia Swap event; round trips excluded'],
            self::LIQUIDITY => ['number' => self::LIQUIDITY, 'key' => 'liquidity', 'title' => 'Add liquidity or stake', 'description' => 'Provide liquidity to a pool or stake, above the season minimum.', 'verifier' => 'Indexed liq_add or stake event'],
            self::RETURN => ['number' => self::RETURN, 'key' => 'return', 'title' => 'Return on another day', 'description' => 'Make another qualifying action on a later UTC date, at least a day after your first.', 'verifier' => 'Distinct-day indexed event'],
        ];
    }

    public function seasonKey(): string
    {
        return (string) config('pioneer.season', 's1');
    }

    public function startsAt(): Carbon
    {
        return Carbon::parse((string) config('pioneer.starts_at'), 'UTC');
    }

    public function endsAt(): Carbon
    {
        return Carbon::parse((string) config('pioneer.ends_at'), 'UTC');
    }

    /**
     * @return 'upcoming'|'open'|'closed'
     */
    public function state(?CarbonInterface $now = null): string
    {
        $now = $now ? Carbon::instance($now)->utc() : Carbon::now('UTC');

        return match (true) {
            $now->lessThan($this->startsAt()) => 'upcoming',
            $now->greaterThan($this->endsAt()) => 'closed',
            default => 'open',
        };
    }

    /**
     * @return array<int, int>
     */
    public function requiredCheckpoints(): array
    {
        return array_values(array_filter(
            array_keys($this->definitions()),
            fn (int $number) => $number !== self::BRIDGE || (bool) config('pioneer.bridge_required', true),
        ));
    }

    /**
     * Team accounts and treasury addresses take no part.
     */
    public function isExcluded(User $user): bool
    {
        if (in_array((int) $user->id, array_map('intval', (array) config('crm.admin_user_ids', [])), true)) {
            return true;
        }

        $excluded = [
            ...array_map('strtolower', (array) config('pioneer.excluded_addresses', [])),
            ...array_map('strtolower', (array) config('crm.admin_wallets', [])),
        ];

        return array_intersect($this->addressesOf($user), $excluded) !== [];
    }

    /**
     * Check one profile against the records and write down what it passed.
     *
     * Nothing is recorded before the season opens; after it closes, actions
     * from inside the window are still found (a sweep may lag the last
     * minutes), but the wallet checkpoint, which has no time of its own, is
     * not.
     *
     * The wallet checkpoint needs the person to have shown up: signed in on
     * the page (`$present`) or acted on chain in the window. Otherwise the
     * first sweep would pass it for every wallet ever bound, and "confirmed
     * pioneers" would count accounts that never heard of the season.
     *
     * @return array{recorded: array<int, int>, finished: bool}
     */
    public function verify(User $user, ?CarbonInterface $now = null, bool $present = false): array
    {
        $now = $now ? Carbon::instance($now)->utc() : Carbon::now('UTC');
        $state = $this->state($now);

        if ($state === 'upcoming' || $this->isExcluded($user)) {
            return ['recorded' => [], 'finished' => false];
        }

        $recorded = [];
        $have = $this->checkpointsOf($user);

        $candidates = [
            self::WALLET => [],
            self::BRIDGE => $this->bridgeCandidates($user),
            self::SWAP => $this->swapCandidates($user),
            self::LIQUIDITY => $this->liquidityCandidates($user),
        ];

        $acted = $candidates[self::BRIDGE] !== [] || $candidates[self::SWAP] !== [] || $candidates[self::LIQUIDITY] !== [];

        if ($state === 'open' && ($present || $acted)) {
            $candidates[self::WALLET] = $this->walletCandidates($user, $now);
        }

        $candidates[self::RETURN] = $this->returnCandidates([
            ...$candidates[self::BRIDGE],
            ...$candidates[self::SWAP],
            ...$candidates[self::LIQUIDITY],
        ]);

        foreach ($candidates as $checkpoint => $events) {
            if (isset($have[$checkpoint])) {
                continue;
            }

            foreach ($events as $event) {
                if ($this->record($user, $checkpoint, $event['reference'], $event['at'])) {
                    $recorded[] = $checkpoint;
                    break;
                }
            }
        }

        return ['recorded' => $recorded, 'finished' => $this->finish($user)];
    }

    /**
     * Approve a pending (or re-approve a rejected) finisher: the completion
     * XP is paid, and the badge becomes owing for `achievements:sync`.
     */
    public function approve(PioneerFinisher $finisher, ?string $by = null, ?string $note = null): void
    {
        $finisher->forceFill([
            'status' => PioneerFinisher::APPROVED,
            'reviewed_at' => $by !== null ? Carbon::now('UTC') : $finisher->reviewed_at,
            'reviewed_by' => $by ?? $finisher->reviewed_by,
            'note' => $note ?? $finisher->note,
        ])->save();

        $user = $finisher->user;

        if ($user) {
            $this->gamification->award(
                $user,
                'pioneer',
                $finisher->season.':complete',
                (int) config('pioneer.xp.complete', 0),
            );
        }
    }

    /**
     * Reject a finisher. Checkpoint XP already paid stays (it was paid for
     * real records); the completion bonus and the badge do not come.
     */
    public function reject(PioneerFinisher $finisher, string $by, ?string $note = null): void
    {
        $finisher->forceFill([
            'status' => PioneerFinisher::REJECTED,
            'reviewed_at' => Carbon::now('UTC'),
            'reviewed_by' => $by,
            'note' => $note ?? $finisher->note,
        ])->save();
    }

    /**
     * Whether this user holds an approved completion of any season — the
     * condition of the on-chain Pioneer badge.
     */
    public function isApprovedPioneer(User $user): bool
    {
        return Schema::hasTable('pioneer_finishers')
            && PioneerFinisher::query()
                ->where('user_id', $user->id)
                ->where('status', PioneerFinisher::APPROVED)
                ->exists();
    }

    /**
     * Everything the page shows about one profile.
     *
     * @return array<string, mixed>
     */
    public function progressFor(User $user): array
    {
        $have = $this->checkpointsOf($user);
        $finisher = PioneerFinisher::query()
            ->where('season', $this->seasonKey())
            ->where('user_id', $user->id)
            ->first();

        return [
            'excluded' => $this->isExcluded($user),
            'has_wallet' => $this->addressesOf($user) !== [],
            'has_evm_wallet' => (string) $user->wallet_address !== '',
            'checkpoints' => array_map(fn (PioneerCheckpoint $row) => [
                'qualified_at' => $row->qualified_at?->toIso8601String(),
                'reference' => $row->reference,
                'explorer_url' => $this->explorerUrl($row->reference),
            ], $have),
            'xp_earned' => (int) DB::table('xp_entries')
                ->where('user_id', $user->id)
                ->where('source', 'pioneer')
                ->where('reference', 'like', $this->seasonKey().':%')
                ->sum('amount'),
            'finisher' => $finisher ? [
                'status' => $finisher->status,
                'completed_at' => $finisher->completed_at?->toIso8601String(),
                'position' => $finisher->status === PioneerFinisher::REJECTED ? null : $this->positionOf($finisher),
            ] : null,
        ];
    }

    /**
     * Finishers in the order they finished. Rejected ones are left out;
     * pending ones are shown, marked, because "under review" is a true state
     * and hiding it would read as a lost completion.
     *
     * @return array<int, array<string, mixed>>
     */
    public function leaderboard(?int $limit = null): array
    {
        return PioneerFinisher::query()
            ->join('users', 'users.id', '=', 'pioneer_finishers.user_id')
            ->where('pioneer_finishers.season', $this->seasonKey())
            ->where('pioneer_finishers.status', '!=', PioneerFinisher::REJECTED)
            ->orderBy('pioneer_finishers.completed_at')
            ->orderBy('pioneer_finishers.id')
            ->limit($limit ?? (int) config('pioneer.leaderboard_size', 50))
            ->get([
                'users.id as user_id',
                'users.name',
                'users.onchain_nickname',
                'users.wallet_address',
                'pioneer_finishers.completed_at',
                'pioneer_finishers.status',
            ])
            ->values()
            ->map(fn ($row, int $index) => [
                'position' => $index + 1,
                'user_id' => (int) $row->user_id,
                'name' => $row->onchain_nickname ?: $row->name,
                'wallet_address' => $row->wallet_address,
                'profile_url' => ProfileHandle::url((int) $row->user_id, $row->onchain_nickname),
                'completed_at' => Carbon::parse($row->completed_at)->utc()->toIso8601String(),
                'status' => $row->status,
            ])
            ->all();
    }

    /**
     * Profiles per checkpoint, and finishers — the season's own metrics.
     *
     * @return array{checkpoints: array<int, int>, finishers: int, approved: int}
     */
    public function stats(): array
    {
        $counts = PioneerCheckpoint::query()
            ->where('season', $this->seasonKey())
            ->selectRaw('checkpoint, COUNT(*) as total')
            ->groupBy('checkpoint')
            ->pluck('total', 'checkpoint');

        $checkpoints = [];

        foreach (array_keys($this->definitions()) as $number) {
            $checkpoints[$number] = (int) ($counts[$number] ?? 0);
        }

        $finishers = PioneerFinisher::query()->where('season', $this->seasonKey());

        return [
            'checkpoints' => $checkpoints,
            'finishers' => (clone $finishers)->where('status', '!=', PioneerFinisher::REJECTED)->count(),
            'approved' => (clone $finishers)->where('status', PioneerFinisher::APPROVED)->count(),
        ];
    }

    /**
     * Lowercased addresses bound to this profile.
     *
     * @return array<int, string>
     */
    private function addressesOf(User $user): array
    {
        return array_values(array_filter([
            strtolower((string) $user->wallet_address),
            strtolower((string) $user->solana_wallet_address),
        ]));
    }

    /**
     * @return array<int, PioneerCheckpoint>
     */
    private function checkpointsOf(User $user): array
    {
        return PioneerCheckpoint::query()
            ->where('season', $this->seasonKey())
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('checkpoint')
            ->all();
    }

    /**
     * The wallet checkpoint has no event of its own: a wallet is either bound
     * or not, so it is passed the moment the verifier sees it during the
     * season. Wallet addresses are unique per user, so the reference is too.
     *
     * @return array<int, array{reference: string, at: Carbon}>
     */
    private function walletCandidates(User $user, Carbon $now): array
    {
        return array_map(
            fn (string $address) => ['reference' => 'wallet:'.$address, 'at' => $now->copy()],
            $this->addressesOf($user),
        );
    }

    /**
     * @return array<int, array{reference: string, at: Carbon}>
     */
    private function bridgeCandidates(User $user): array
    {
        $addresses = $this->addressesOf($user);

        return BridgeRequest::query()
            ->where('status', 'completed')
            ->whereNotNull('source_tx_hash')
            ->whereNotNull('destination_tx_hash')
            ->where(function ($query) use ($user, $addresses) {
                $query->where('user_id', $user->id);

                if ($addresses !== []) {
                    $query->orWhereIn(DB::raw('LOWER(sender_address)'), $addresses)
                        ->orWhereIn(DB::raw('LOWER(recipient_address)'), $addresses);
                }
            })
            ->whereRaw('COALESCE(completed_at, updated_at) BETWEEN ? AND ?', [
                $this->startsAt()->format('Y-m-d H:i:s'),
                $this->endsAt()->format('Y-m-d H:i:s'),
            ])
            ->get(['id', 'completed_at', 'updated_at'])
            ->map(fn (BridgeRequest $request) => [
                'reference' => 'bridge:'.$request->id,
                'at' => Carbon::parse($request->completed_at ?? $request->updated_at)->utc(),
            ])
            ->sortBy(fn (array $event) => $event['at']->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * Swaps at or above the minimum, without round trips.
     *
     * @return array<int, array{reference: string, at: Carbon}>
     */
    private function swapCandidates(User $user): array
    {
        $minutes = (int) config('pioneer.round_trip_minutes', 60);
        $minimum = (float) config('pioneer.min_swap_usd', 0);

        // Every swap near the window, small ones included: a dust reverse leg
        // still makes the large one a round trip.
        $swaps = $this->activity(
            $user,
            ['swap'],
            $this->startsAt()->subMinutes($minutes),
            $this->endsAt()->addMinutes($minutes),
        );

        $qualifying = array_filter($swaps, function (array $swap) use ($swaps, $minutes, $minimum) {
            if (! $this->inWindow($swap['at']) || $swap['usd'] === null || $swap['usd'] < $minimum) {
                return false;
            }

            if ($swap['sym_in'] !== '' && $swap['sym_in'] === $swap['sym_out']) {
                return false;
            }

            foreach ($swaps as $other) {
                if ($other['reference'] !== $swap['reference']
                    && $other['sym_in'] === $swap['sym_out']
                    && $other['sym_out'] === $swap['sym_in']
                    && abs($other['at']->getTimestamp() - $swap['at']->getTimestamp()) <= $minutes * 60) {
                    return false;
                }
            }

            return true;
        });

        return array_values(array_map(
            fn (array $swap) => ['reference' => $swap['reference'], 'at' => $swap['at']],
            $qualifying,
        ));
    }

    /**
     * @return array<int, array{reference: string, at: Carbon}>
     */
    private function liquidityCandidates(User $user): array
    {
        $minimum = (float) config('pioneer.min_liquidity_usd', 0);

        return array_values(array_map(
            fn (array $event) => ['reference' => $event['reference'], 'at' => $event['at']],
            array_filter(
                $this->activity($user, ['liq_add', 'stake'], $this->startsAt(), $this->endsAt()),
                fn (array $event) => $event['usd'] !== null && $event['usd'] >= $minimum,
            ),
        ));
    }

    /**
     * The earliest qualifying action on a later UTC date than the first one,
     * and at least `return_after_hours` after it.
     *
     * @param  array<int, array{reference: string, at: Carbon}>  $events
     * @return array<int, array{reference: string, at: Carbon}>
     */
    private function returnCandidates(array $events): array
    {
        if ($events === []) {
            return [];
        }

        usort($events, fn (array $a, array $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        $first = $events[0]['at'];
        $earliest = $first->copy()->addHours((int) config('pioneer.return_after_hours', 24));

        return array_values(array_filter(
            $events,
            fn (array $event) => $event['at']->toDateString() > $first->toDateString()
                && $event['at']->greaterThanOrEqualTo($earliest),
        ));
    }

    /**
     * Indexed on-chain actions of this profile's EVM address, oldest first.
     *
     * `activity_events` is written by the Telegram bot and stores UTC text
     * timestamps, so the bounds are compared as the same text.
     *
     * @param  array<int, string>  $kinds
     * @return array<int, array{reference: string, at: Carbon, usd: float|null, sym_in: string, sym_out: string}>
     */
    private function activity(User $user, array $kinds, Carbon $from, Carbon $to): array
    {
        $wallet = strtolower((string) $user->wallet_address);

        if ($wallet === '' || ! Schema::hasTable('activity_events')) {
            return [];
        }

        return DB::table('activity_events')
            ->whereIn('kind', $kinds)
            ->whereRaw('LOWER(user_addr) = ?', [$wallet])
            ->whereNotNull('tx_hash')
            ->whereBetween('created_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['kind', 'usd', 'sym_in', 'sym_out', 'tx_hash', 'created_at'])
            ->map(fn ($row) => [
                'reference' => ($row->kind === 'swap' ? 'swap:' : 'liquidity:').strtolower((string) $row->tx_hash),
                'at' => Carbon::parse((string) $row->created_at, 'UTC'),
                'usd' => $row->usd === null ? null : (float) $row->usd,
                'sym_in' => strtoupper(trim((string) $row->sym_in)),
                'sym_out' => strtoupper(trim((string) $row->sym_out)),
            ])
            ->all();
    }

    private function inWindow(Carbon $at): bool
    {
        return $at->between($this->startsAt(), $this->endsAt());
    }

    /**
     * Write one checkpoint and pay its XP. False when the reference already
     * credited somebody else (or this checkpoint was written concurrently).
     */
    private function record(User $user, int $checkpoint, string $reference, Carbon $at): bool
    {
        $now = Carbon::now('UTC');

        $inserted = PioneerCheckpoint::query()->insertOrIgnore([
            'season' => $this->seasonKey(),
            'user_id' => $user->id,
            'checkpoint' => $checkpoint,
            'reference' => $reference,
            'qualified_at' => $at,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return false;
        }

        $this->gamification->award(
            $user,
            'pioneer',
            $this->seasonKey().':'.sprintf('%02d', $checkpoint),
            (int) config("pioneer.xp.{$checkpoint}", 0),
        );

        return true;
    }

    /**
     * Make a finisher of a profile that has every required checkpoint.
     */
    private function finish(User $user): bool
    {
        $have = $this->checkpointsOf($user);
        $required = $this->requiredCheckpoints();

        if (array_diff($required, array_keys($have)) !== []) {
            return false;
        }

        $existing = PioneerFinisher::query()
            ->where('season', $this->seasonKey())
            ->where('user_id', $user->id)
            ->exists();

        if ($existing) {
            return true;
        }

        $completedAt = collect($required)
            ->map(fn (int $number) => $have[$number]->qualified_at)
            ->max();

        $before = PioneerFinisher::query()->where('season', $this->seasonKey())->count();
        $status = $before < (int) config('pioneer.review_first', 50)
            ? PioneerFinisher::PENDING
            : PioneerFinisher::APPROVED;

        $now = Carbon::now('UTC');

        $inserted = PioneerFinisher::query()->insertOrIgnore([
            'season' => $this->seasonKey(),
            'user_id' => $user->id,
            'completed_at' => $completedAt,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0 && $status === PioneerFinisher::APPROVED) {
            $finisher = PioneerFinisher::query()
                ->where('season', $this->seasonKey())
                ->where('user_id', $user->id)
                ->firstOrFail();

            $this->approve($finisher);
        }

        return true;
    }

    private function positionOf(PioneerFinisher $finisher): int
    {
        return PioneerFinisher::query()
            ->where('season', $finisher->season)
            ->where('status', '!=', PioneerFinisher::REJECTED)
            ->where(function ($query) use ($finisher) {
                $query->where('completed_at', '<', $finisher->completed_at)
                    ->orWhere(function ($tie) use ($finisher) {
                        $tie->where('completed_at', $finisher->completed_at)
                            ->where('id', '<', $finisher->id);
                    });
            })
            ->count() + 1;
    }

    private function explorerUrl(string $reference): ?string
    {
        [$kind, $value] = array_pad(explode(':', $reference, 2), 2, '');

        $explorer = rtrim((string) config('services.cyberia.explorer_url', 'https://explorer.cyberia.church'), '/');

        return match ($kind) {
            'swap', 'liquidity' => $explorer.'/tx/'.$value,
            'wallet' => str_starts_with($value, '0x') ? $explorer.'/address/'.$value : null,
            default => null,
        };
    }
}
