<?php

namespace App\Services;

use App\Models\BridgeRequest;
use App\Models\BridgeReservation;
use App\Support\BridgeCapacity;
use App\Support\TokenAmount;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admission control for every inventory-capped corridor.
 *
 * The invariant, stated once:
 *
 *   > confirmed obligations + active reservations never exceed the destination
 *   > inventory that is actually deliverable. With unknown or insufficient
 *   > inventory, no irreversible source-side step may begin and no wrapper may
 *   > be burned.
 *
 * One more live check in the UI cannot hold that up — between reading a
 * balance and signing a transaction there is a wallet prompt, a user, and
 * possibly another user doing the same thing. So the balance is *claimed*, in
 * a row, under a lock over the destination pool, before the wallet opens.
 */
class BridgeAdmissionService
{
    public function __construct(
        private BridgeInventoryService $inventory,
        private BridgeFeeService $fee,
    ) {}

    /**
     * Capacity as the public sees it: what the relayer holds, minus everything
     * already promised. A reservation somebody else is holding is capacity you
     * do not have, and the screen must say so.
     */
    public function availableCapacity(string $direction, string $token): BridgeCapacity
    {
        $capacity = $this->inventory->capacity($direction, $token);

        if (! $capacity->isCapped()) {
            return $capacity;
        }

        $pool = $this->inventory->poolKey($direction, $token);

        return $pool === null ? $capacity : $capacity->minus($this->outstandingRaw($pool));
    }

    /**
     * Raw units already claimed against a pool by unexpired holds and by
     * confirmed obligations.
     */
    public function outstandingRaw(string $pool): string
    {
        $total = '0';

        $rows = BridgeReservation::query()
            ->where('pool', $pool)
            ->outstanding()
            ->get(['net_raw']);

        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->net_raw, 0);
        }

        return $total;
    }

    /**
     * What a bridge of `$amount` will actually put on the destination chain:
     * the exact net figure after the fee, converted into raw integer units in
     * the DESTINATION entry's decimals. This is the only number the admission
     * path ever compares — a float would round a 6-decimal stablecoin at the
     * cent and an 18-decimal wrapper at the microether.
     *
     * @return array{fee: string, net: string, net_raw: string, decimals: int}|null
     */
    public function quote(string $direction, string $token, string $amount): ?array
    {
        $decimals = $this->inventory->destinationDecimals($direction, $token);

        if ($decimals === null) {
            return null;
        }

        $fee = (string) $this->fee->feeForBridge($token, $amount, $direction)['fee_amount'];
        $net = bcsub($amount, $fee, 18);

        if (bccomp($net, '0', 18) <= 0) {
            return null;
        }

        return [
            'fee' => $fee,
            'net' => $net,
            'net_raw' => TokenAmount::toRaw($net, $decimals),
            'decimals' => $decimals,
        ];
    }

    /**
     * Claim capacity for a transfer that has not been signed yet.
     *
     * Everything that decides the outcome happens inside one lock over the
     * pool: read the live balance, subtract what is already promised, compare
     * against this exact net amount, write the row. Two requests for 0.6 of a
     * 1.0 balance therefore cannot both succeed — the second one reads the
     * first one's row.
     *
     * @return array{ok: true, reservation: BridgeReservation}|array{ok: false, reason: string, message: string, capacity: BridgeCapacity}
     */
    public function reserve(
        string $direction,
        string $token,
        string $amount,
        ?string $senderAddress,
        string $recipientAddress,
    ): array {
        $quote = $this->quote($direction, $token, $amount);

        if ($quote === null) {
            return $this->refusal(
                'amount_too_small',
                'The amount does not cover the bridge fee.',
                BridgeCapacity::unavailable('net amount after fee is zero or negative'),
            );
        }

        $pool = $this->inventory->poolKey($direction, $token);

        if ($pool === null) {
            return $this->refusal(
                'unavailable',
                'This corridor is not configured.',
                BridgeCapacity::unavailable('route or token is not configured'),
            );
        }

        $lock = $this->poolLock($pool);

        if (! $lock->get()) {
            return $this->refusal(
                'busy',
                'The bridge is checking liquidity for another transfer — try again in a moment.',
                BridgeCapacity::unavailable('destination pool is locked'),
            );
        }

        try {
            $capacity = $this->inventory->capacity($direction, $token);

            if ($capacity->isUnavailable()) {
                return $this->refusal(
                    'unavailable',
                    'The bridge cannot read its balance on the destination chain right now, so it will not take this transfer. Try again shortly.',
                    $capacity,
                );
            }

            $remaining = $capacity->isCapped()
                ? $capacity->minus($this->outstandingRaw($pool))
                : $capacity;

            if (! $remaining->covers($quote['net_raw'])) {
                return $this->refusal(
                    'insufficient',
                    'The bridge cannot deliver that much on the destination chain right now.',
                    $remaining,
                );
            }

            $reservation = BridgeReservation::create([
                'reference' => (string) Str::uuid().'-'.Str::random(24),
                'pool' => $pool,
                'direction' => $direction,
                'token' => $token,
                'net_raw' => $quote['net_raw'],
                'decimals' => $quote['decimals'],
                'amount' => $amount,
                'sender_address' => $senderAddress,
                'recipient_address' => $recipientAddress,
                'status' => BridgeReservation::PENDING_SOURCE,
                'expires_at' => now()->addSeconds($this->ttlSeconds()),
            ]);

            return ['ok' => true, 'reservation' => $reservation];
        } finally {
            $lock->release();
        }
    }

    /**
     * Turn a claim into an obligation, or record one that was never claimed.
     *
     * `/bridge/submit` is reached only after the user's source transfer is
     * already signed, so it can refuse nothing: money cannot be un-sent. What
     * it can do is make sure every request that will need a payout is counted
     * against the pool — including a transfer somebody made straight to the
     * relayer's public address with no reservation at all. Those become
     * obligations here and are parked in `awaiting_liquidity` by the relayer
     * if the inventory is not there, instead of burning a wrapper for a payout
     * that cannot happen.
     */
    public function commit(BridgeRequest $request, ?string $reference): ?BridgeReservation
    {
        $pool = $this->inventory->poolKey($request->direction, $request->token);

        if ($pool === null) {
            return null;
        }

        $quote = $this->quote($request->direction, $request->token, (string) $request->amount);
        $netRaw = $quote['net_raw'] ?? '0';
        $decimals = $quote['decimals'] ?? ($this->inventory->destinationDecimals($request->direction, $request->token) ?? 18);

        return DB::transaction(function () use ($request, $reference, $pool, $netRaw, $decimals) {
            // Idempotent: this request may already carry an obligation, from
            // its own submit or from the relayer confirming its deposit. A
            // second call refreshes the figure, it never opens a second claim.
            $existing = BridgeReservation::where('bridge_request_id', $request->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status !== BridgeReservation::SETTLED) {
                    $existing->update([
                        'net_raw' => $netRaw,
                        'decimals' => $decimals,
                        'status' => BridgeReservation::COMMITTED,
                        'committed_at' => $existing->committed_at ?? now(),
                        'released_at' => null,
                        'release_reason' => null,
                    ]);
                }

                return $existing;
            }

            $reservation = $reference === null
                ? null
                : BridgeReservation::where('reference', $reference)
                    ->lockForUpdate()
                    ->first();

            if ($reservation !== null && ! $this->reservationMatches($reservation, $request)) {
                Log::warning('Bridge: reservation does not match the submitted transfer', [
                    'id' => $request->id,
                    'reservation' => $reservation->reference,
                ]);
                $reservation = null;
            }

            // Consumed once: a reference already spent on another request, or
            // already settled, cannot be handed in a second time.
            if ($reservation !== null
                && ($reservation->status !== BridgeReservation::PENDING_SOURCE
                    || $reservation->bridge_request_id !== null)) {
                Log::warning('Bridge: reservation already consumed', [
                    'id' => $request->id,
                    'reservation' => $reservation->reference,
                    'status' => $reservation->status,
                ]);
                $reservation = null;
            }

            if ($reservation === null) {
                $reservation = BridgeReservation::create([
                    'reference' => (string) Str::uuid().'-'.Str::random(24),
                    'pool' => $pool,
                    'direction' => $request->direction,
                    'token' => $request->token,
                    'net_raw' => $netRaw,
                    'decimals' => $decimals,
                    'amount' => (string) $request->amount,
                    'sender_address' => $request->sender_address,
                    'recipient_address' => $request->recipient_address,
                    'status' => BridgeReservation::PENDING_SOURCE,
                    'expires_at' => now()->addSeconds($this->ttlSeconds()),
                ]);
            }

            $reservation->update([
                'bridge_request_id' => $request->id,
                // The obligation is what will actually be paid, not what was
                // quoted minutes ago: a gas-driven fee can move between the
                // two, and the ledger must carry the real number.
                'net_raw' => $netRaw,
                'decimals' => $decimals,
                'status' => BridgeReservation::COMMITTED,
                'committed_at' => now(),
            ]);

            return $reservation;
        });
    }

    /**
     * The payout has been broadcast: the balance really moved, so the live
     * read now includes it and the claim must stop being counted on top.
     */
    public function settle(BridgeRequest $request): void
    {
        BridgeReservation::where('bridge_request_id', $request->id)
            ->whereIn('status', [BridgeReservation::PENDING_SOURCE, BridgeReservation::COMMITTED])
            ->update([
                'status' => BridgeReservation::SETTLED,
                'settled_at' => now(),
            ]);
    }

    /**
     * Give capacity back — but only for a request whose source transfer was
     * never confirmed. A verified deposit is an obligation and stays one
     * through every failure, retry and restart.
     */
    public function releaseFor(BridgeRequest $request, string $reason): void
    {
        if ($request->source_verified_at !== null) {
            return;
        }

        BridgeReservation::where('bridge_request_id', $request->id)
            ->whereIn('status', [BridgeReservation::PENDING_SOURCE, BridgeReservation::COMMITTED])
            ->update([
                'status' => BridgeReservation::RELEASED,
                'released_at' => now(),
                'release_reason' => $reason,
            ]);
    }

    /**
     * Sweep lapsed pre-signature holds. They already stop counting the moment
     * they expire (see the `outstanding` scope) — this only tidies the ledger.
     */
    public function releaseExpired(): int
    {
        return BridgeReservation::query()
            ->where('status', BridgeReservation::PENDING_SOURCE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('bridge_request_id')
            ->update([
                'status' => BridgeReservation::RELEASED,
                'released_at' => now(),
                'release_reason' => 'expired before a source transfer',
            ]);
    }

    /**
     * The lock every decision about one destination balance is made inside —
     * a reservation, and the relayer's own re-check before it pays.
     */
    public function poolLock(string $pool): Lock
    {
        return Cache::lock(
            'bridge:pool:'.sha1($pool),
            (int) config('bridge.inventory.pool_lock_seconds', 600),
        );
    }

    /**
     * Capacity as the relayer must see it just before paying: live inventory
     * minus every OTHER outstanding claim. This request's own obligation is
     * excluded — it is the thing being delivered, not competition for it.
     */
    public function capacityForPayout(BridgeRequest $request): BridgeCapacity
    {
        $capacity = $this->inventory->capacity($request->direction, $request->token);

        if (! $capacity->isCapped()) {
            return $capacity;
        }

        $pool = $this->inventory->poolKey($request->direction, $request->token);

        if ($pool === null) {
            return $capacity;
        }

        $others = '0';

        $rows = BridgeReservation::query()
            ->where('pool', $pool)
            ->where(function ($q) use ($request) {
                $q->whereNull('bridge_request_id')->orWhere('bridge_request_id', '!=', $request->id);
            })
            ->outstanding()
            ->get(['net_raw']);

        foreach ($rows as $row) {
            $others = bcadd($others, (string) $row->net_raw, 0);
        }

        return $capacity->minus($others);
    }

    private function reservationMatches(BridgeReservation $reservation, BridgeRequest $request): bool
    {
        return $reservation->direction === $request->direction
            && $reservation->token === $request->token
            && $this->addressMatches($reservation->recipient_address, $request->recipient_address)
            && $this->addressMatches($reservation->sender_address, $request->sender_address)
            && bccomp((string) $reservation->amount, (string) $request->amount, 18) === 0;
    }

    private function addressMatches(?string $a, ?string $b): bool
    {
        // EVM addresses differ only in checksum casing between the wallet and
        // the form; base58 addresses are case-sensitive but never differ.
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a === $b || (str_starts_with($a, '0x') && strcasecmp($a, $b) === 0);
    }

    /**
     * @return array{ok: false, reason: string, message: string, capacity: BridgeCapacity}
     */
    private function refusal(string $reason, string $message, BridgeCapacity $capacity): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message, 'capacity' => $capacity];
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('bridge.inventory.reservation_ttl_seconds', 900));
    }
}
