<?php

namespace App\Services\Slots;

use App\Models\SlotPool;

/**
 * Pure outcome computation. No I/O — takes a reels matrix, the jackpot probe,
 * pool config, and the snapshot of pool tokens; returns the outcome record
 * (loss / win / jackpot) and the prize basket.
 *
 * Math:
 *   - Win line = middle row only in MVP.
 *   - P(three of mint M) = w_M^3 (independent cells).
 *   - Gross prize = bet * (1 - house_edge_bps / 10_000) / P  →  uniform RTP.
 *   - Capped at SLOT_MAX_SINGLE_WIN_BPS of current pool balance for M.
 *   - Jackpot fires only when the middle row is already 3-of-a-kind AND the
 *     jackpot probe came up under threshold — guarantees jackpot animations
 *     always match a visible 3-of-a-kind.
 */
class SlotOutcomeResolver
{
    /**
     * @param  array<int, array<int, string>>  $reels
     * @param  list<array{mint:string,symbol:?string,decimals:int,logo_url:?string,weight:float,balance:string,min_bet:string,max_bet:?string,token_program:string}>  $snapshot
     * @return array{outcome:string, prize:list<array{mint:string,amount:string,decimals:int,symbol:?string}>, burn_amount:string}
     */
    public function resolve(
        SlotPool $pool,
        string $betMint,
        string $betAmountRaw,
        array $reels,
        int $jackpotRoll,
        array $snapshot,
    ): array {
        $burn = $this->burnAmount($pool, $betAmountRaw);

        $middle = $reels[1] ?? [];
        $isMatch = count($middle) === 3 && $middle[0] === $middle[1] && $middle[1] === $middle[2];

        if (! $isMatch) {
            return ['outcome' => 'loss', 'prize' => [], 'burn_amount' => $burn];
        }

        $jackpotHit = $jackpotRoll < $pool->jackpot_threshold_bps;
        $matchMint = $middle[0];

        if ($jackpotHit) {
            return [
                'outcome' => 'jackpot',
                'prize' => $this->jackpotBasket($pool, $snapshot, $matchMint),
                'burn_amount' => $burn,
            ];
        }

        $entry = $this->findSnapshotEntry($snapshot, $matchMint);

        if (! $entry) {
            // The reel-cell weight came from this snapshot — entry should
            // always be present. If not, settle as a loss rather than crash.
            return ['outcome' => 'loss', 'prize' => [], 'burn_amount' => $burn];
        }

        $prizeRaw = $this->singleTokenPrize($pool, $entry, $betMint, $betAmountRaw, $snapshot);

        if (bccomp($prizeRaw, '0', 0) <= 0) {
            return ['outcome' => 'loss', 'prize' => [], 'burn_amount' => $burn];
        }

        return [
            'outcome' => 'win',
            'prize' => [[
                'mint' => $entry['mint'],
                'amount' => $prizeRaw,
                'decimals' => $entry['decimals'],
                'symbol' => $entry['symbol'],
            ]],
            'burn_amount' => $burn,
        ];
    }

    public function burnAmount(SlotPool $pool, string $betAmountRaw): string
    {
        return bcdiv(bcmul($betAmountRaw, (string) $pool->burn_bps, 0), '10000', 0);
    }

    /**
     * Single-token prize. We pay the *same UI-unit prize* regardless of which
     * mint matched, scaled so the overall RTP across all 3-of-a-kind outcomes
     * equals (1 - house_edge_bps / 10_000).
     *
     *   Sum over M of P(3 of M) * prize == bet * (1 - edge)
     *   With prize identical in UI-units: prize_ui = bet_ui * (1 - edge) / Σ w_M^3.
     *
     * Bet UI-units and prize UI-units are not value-equivalent across mints
     * (no price oracle in MVP) — they're just comparable token-count units.
     * Refine with a price feed later.
     */
    private function singleTokenPrize(SlotPool $pool, array $entry, string $betMint, string $betAmountRaw, array $snapshot): string
    {
        $totalMatch = 0.0;
        $betDecimals = (int) $entry['decimals'];

        foreach ($snapshot as $row) {
            $w = (float) $row['weight'];
            $totalMatch += $w ** 3;
            if ((string) $row['mint'] === $betMint) {
                $betDecimals = (int) $row['decimals'];
            }
        }

        if ($totalMatch <= 0) {
            return '0';
        }

        $edge = (10_000 - $pool->house_edge_bps) / 10_000.0;

        $betUi = $this->toFloatUi($betAmountRaw, $betDecimals);
        $grossUi = ($betUi * $edge) / $totalMatch;
        $grossRaw = $this->fromFloatUi($grossUi, (int) $entry['decimals']);

        $cap = bcdiv(bcmul((string) $entry['balance'], (string) $pool->max_single_win_bps, 0), '10000', 0);

        return bccomp($grossRaw, $cap, 0) > 0 ? $cap : $grossRaw;
    }

    /**
     * @param  list<array<string,mixed>>  $snapshot
     * @return list<array{mint:string,amount:string,decimals:int,symbol:?string}>
     */
    private function jackpotBasket(SlotPool $pool, array $snapshot, string $primaryMint): array
    {
        usort($snapshot, fn ($a, $b) => bccomp((string) $b['balance'], (string) $a['balance'], 0));

        $picked = array_slice($snapshot, 0, max(1, $pool->jackpot_basket_size));
        $basket = [];

        foreach ($picked as $entry) {
            $amount = bcdiv(
                bcmul((string) $entry['balance'], (string) $pool->jackpot_basket_bps, 0),
                '10000',
                0
            );

            if (bccomp($amount, '0', 0) <= 0) {
                continue;
            }

            $basket[] = [
                'mint' => (string) $entry['mint'],
                'amount' => $amount,
                'decimals' => (int) $entry['decimals'],
                'symbol' => $entry['symbol'] ?? null,
            ];
        }

        return $basket;
    }

    private function findSnapshotEntry(array $snapshot, string $mint): ?array
    {
        foreach ($snapshot as $entry) {
            if ($entry['mint'] === $mint) {
                return $entry;
            }
        }

        return null;
    }

    private function toFloatUi(string $raw, int $decimals): float
    {
        return (float) bcdiv($raw, bcpow('10', (string) $decimals, 0), 18);
    }

    private function fromFloatUi(float $ui, int $decimals): string
    {
        if ($ui <= 0 || ! is_finite($ui)) {
            return '0';
        }

        $multiplied = number_format($ui, $decimals, '.', '');
        $parts = explode('.', $multiplied);

        return $parts[0] === '0' && ($parts[1] ?? '') === ''
            ? '0'
            : bcmul($multiplied, bcpow('10', (string) $decimals, 0), 0);
    }
}
