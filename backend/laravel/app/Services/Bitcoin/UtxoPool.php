<?php

namespace App\Services\Bitcoin;

use App\Models\BridgeRequest;

/**
 * Which addresses the bridge can actually spend from on a UTXO chain.
 *
 * Liquidity here is not one wallet balance. It is the central address the
 * operator funds, plus every one-time deposit address whose transfer has
 * already been minted on Cyberia — those coins were bought and paid for by a
 * wrapper that now exists, so they belong to the pool rather than to the
 * person who sent them.
 *
 * The status filter is the safety property, and it is the same one Yenten's
 * payout has always applied: **only `completed` requests**. An address still
 * awaiting its deposit may be holding coins nobody has claimed yet, and
 * spending those would make the claim that follows come up empty — the user
 * would have sent real money and received nothing, with the bridge's own
 * ledger agreeing that nothing arrived. An expired or never-funded row is
 * excluded for the cheaper reason that asking a public index about a hundred
 * empty addresses is how a payout runs into a rate limit.
 *
 * Addresses and keys are separated on purpose: the capacity reader needs only
 * the first, and a balance should never be computed by a path that had to
 * touch a spending key.
 */
final class UtxoPool
{
    /** Deposit addresses considered, newest first. Bounded so a payout's reads are too. */
    private const MAX_DEPOSIT_ADDRESSES = 50;

    /**
     * Addresses to read a balance from — central wallet first.
     *
     * @return array<int, string>
     */
    public function addresses(string $chainKey): array
    {
        return $this->collect($chainKey, 'deposit_address', (string) config("bridge.chains.{$chainKey}.deposit_address", ''));
    }

    /**
     * Keys to sign a payout with, in the same order.
     *
     * @return array<int, string>
     */
    public function wifs(string $chainKey): array
    {
        return $this->collect($chainKey, 'deposit_wif', (string) config("bridge.chains.{$chainKey}.relayer_wif", ''));
    }

    /**
     * @return array<int, string>
     */
    private function collect(string $chainKey, string $column, string $central): array
    {
        $values = [];

        if (trim($central) !== '') {
            $values[] = trim($central);
        }

        $deposits = BridgeRequest::query()
            ->where('source_chain', $chainKey)
            ->where('status', BridgeRequest::COMPLETED)
            ->whereNotNull($column)
            ->where('swept', false)
            ->latest('id')
            ->limit(self::MAX_DEPOSIT_ADDRESSES)
            ->pluck($column);

        foreach ($deposits as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            }
        }

        return array_values(array_unique($values));
    }
}
