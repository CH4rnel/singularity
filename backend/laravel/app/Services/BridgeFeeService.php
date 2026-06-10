<?php

namespace App\Services;

class BridgeFeeService
{
    public function __construct(
        private CyberPriceService $cyberPrice,
    ) {}

    /**
     * Compute the bridge fee for a given token + amount, denominated in USD
     * and expressed in token units. Returns [feeAmountToken, feeUsd, tokenPriceUsd].
     *
     * CYBER.sol bridges are fee-free by policy — only USD-pegged stablecoins
     * (USDC/USDT) carry the bridge fee.
     */
    public function feeForBridge(string $token, string $amount): array
    {
        $price = $this->priceUsd($token);

        if (! $this->isFeeBearing($token)) {
            return [
                'fee_amount' => '0',
                'fee_usd' => '0',
                'token_price_usd' => $price,
            ];
        }

        $amountUsd = bcmul($amount, $price, 8);

        $flatUsd = (string) config('bridge.fee.flat_usd', '0.10');
        $rateBps = (int) config('bridge.fee.rate_bps', 0);
        $rateUsd = bcdiv(bcmul($amountUsd, (string) $rateBps, 8), '10000', 8);

        // max(flat, rate*amount)
        $feeUsd = bccomp($flatUsd, $rateUsd, 8) >= 0 ? $flatUsd : $rateUsd;

        $feeAmount = bccomp($price, '0', 8) > 0
            ? bcdiv($feeUsd, $price, 18)
            : '0';

        return [
            'fee_amount' => $feeAmount,
            'fee_usd' => $feeUsd,
            'token_price_usd' => $price,
        ];
    }

    /**
     * Only USD-pegged stablecoins pay the bridge fee.
     */
    public function isFeeBearing(string $token): bool
    {
        return in_array($token, ['USDC', 'USDT'], true);
    }

    /**
     * USD price for a supported token. Stablecoins return '1', CYBER.sol uses
     * the DexScreener price feed. Falls back to '0' on lookup failure.
     */
    public function priceUsd(string $token): string
    {
        if ($token === 'USDC' || $token === 'USDT') {
            return '1';
        }

        if ($token === 'CYBER.sol') {
            $data = $this->cyberPrice->get();
            $price = $data['priceUsd'] ?? null;

            return $price !== null ? (string) $price : '0';
        }

        return '0';
    }
}
