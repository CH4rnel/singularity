<?php

namespace App\Services\Contributions;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Splits one pool over points. Pure, integer, and never pays out more than
 * the pool: every share is rounded down, so the dust stays in the contract.
 *
 * The per-contributor cap is filled like water. A share above the cap is cut
 * to it and what that frees is split again among everybody still under it —
 * otherwise the cap would quietly shrink the pool every time one prolific
 * contributor hit it, and the money the cap exists to spread would sit unpaid.
 */
class EpochAllocator
{
    /**
     * @param  array<string, int>  $points  address => points (hundredths)
     * @return array<string, string>  address => raw amount, only non-zero shares
     */
    public function allocate(string $poolRaw, array $points, int $maxShareBps, int $minPoints): array
    {
        $pool = BigInteger::of($poolRaw);
        $active = array_filter($points, fn (int $p): bool => $p > 0 && $p >= $minPoints);

        if ($pool->isZero() || $active === []) {
            return [];
        }

        $cap = $maxShareBps > 0 && $maxShareBps < 10_000
            ? $pool->multipliedBy($maxShareBps)->dividedBy(10_000, RoundingMode::DOWN)
            : null;

        $shares = [];
        $remaining = $pool;

        while ($active !== []) {
            $total = BigInteger::of(array_sum($active));
            $round = [];
            $capped = [];

            foreach ($active as $address => $p) {
                $share = $remaining->multipliedBy($p)->dividedBy($total, RoundingMode::DOWN);

                if ($cap !== null && $share->isGreaterThan($cap)) {
                    $capped[] = $address;
                } else {
                    $round[$address] = $share;
                }
            }

            if ($capped === []) {
                $shares += $round;
                break;
            }

            foreach ($capped as $address) {
                $shares[$address] = $cap;
                $remaining = $remaining->minus($cap);
                unset($active[$address]);
            }
        }

        $out = [];

        foreach ($shares as $address => $share) {
            if ($share->isPositive()) {
                $out[$address] = (string) $share;
            }
        }

        ksort($out);

        return $out;
    }
}
