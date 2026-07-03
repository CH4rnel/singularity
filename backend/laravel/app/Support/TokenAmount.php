<?php

namespace App\Support;

/**
 * Decimal-safe token amount conversions (bcmath). All bridge decimal scaling
 * goes through here — 18/9/6-decimals mistakes are the bridge's biggest
 * failure mode, so keep the arithmetic in one place.
 */
final class TokenAmount
{
    /**
     * Human amount -> raw smallest-unit integer string.
     * Excess precision beyond $decimals is truncated (floor).
     * toRaw('1.5', 9) => '1500000000'
     */
    public static function toRaw(string $amount, int $decimals): string
    {
        $raw = bcmul($amount, bcpow('10', (string) $decimals), $decimals);

        return explode('.', $raw)[0] ?: '0';
    }

    /**
     * Raw smallest-unit integer string -> human amount.
     * fromRaw('1500000000', 9) => '1.500000000'
     */
    public static function fromRaw(string $raw, int $decimals): string
    {
        if ($decimals === 0) {
            return $raw;
        }

        return bcdiv($raw, bcpow('10', (string) $decimals), $decimals);
    }

    /**
     * Hex quantity ('0x...' or bare hex) -> decimal string.
     */
    public static function hexToDec(string $hex): string
    {
        $hex = strtolower(str_starts_with($hex, '0x') ? substr($hex, 2) : $hex);
        $hex = ltrim($hex, '0') ?: '0';
        $dec = '0';

        foreach (str_split($hex) as $char) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($char));
        }

        return $dec;
    }
}
