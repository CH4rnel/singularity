<?php

namespace App\Services\Monero;

use kornrunner\Keccak;

/**
 * Monero address codec: the Monero-flavoured base58 (8-byte blocks encoded to
 * fixed 11-char groups, Keccak-256 checksum) plus integrated-address
 * construction. Integrated addresses embed an 8-byte payment id in the
 * operator's standard address, so per-user deposits land directly in the main
 * wallet while remaining attributable — no per-address keys to sweep.
 */
class MoneroAddressCodec
{
    /** Mainnet network bytes. */
    public const NETBYTE_STANDARD = 0x12;

    public const NETBYTE_INTEGRATED = 0x13;

    private const B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /** Encoded size of a partial trailing block, indexed by its byte length. */
    private const ENCODED_BLOCK_SIZES = [0, 2, 3, 5, 6, 7, 9, 10, 11];

    private const FULL_BLOCK_SIZE = 8;

    private const FULL_ENCODED_BLOCK_SIZE = 11;

    /**
     * Integrated address for the given mainnet standard address and 8-byte
     * payment id. Returns null when the base address is not a standard
     * mainnet address (payment ids do not combine with subaddresses).
     */
    public static function integratedAddress(string $standardAddress, string $paymentIdBytes): ?string
    {
        if (strlen($paymentIdBytes) !== 8) {
            throw new \InvalidArgumentException('Monero payment id must be exactly 8 bytes');
        }

        $decoded = self::decodeChecked($standardAddress);

        // 1 network byte + 32 spend pub + 32 view pub.
        if ($decoded === null || strlen($decoded) !== 65 || ord($decoded[0]) !== self::NETBYTE_STANDARD) {
            return null;
        }

        $payload = chr(self::NETBYTE_INTEGRATED).substr($decoded, 1).$paymentIdBytes;
        $checksum = substr(Keccak::hash($payload, 256, true), 0, 4);

        return self::encode($payload.$checksum);
    }

    /**
     * Decode an address and verify its 4-byte Keccak checksum. Returns the
     * payload (network byte + keys, checksum stripped) or null when malformed.
     */
    public static function decodeChecked(string $address): ?string
    {
        try {
            $raw = self::decode($address);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (strlen($raw) < 5) {
            return null;
        }

        $payload = substr($raw, 0, -4);
        $checksum = substr($raw, -4);

        if (! hash_equals(substr(Keccak::hash($payload, 256, true), 0, 4), $checksum)) {
            return null;
        }

        return $payload;
    }

    public static function encode(string $bytes): string
    {
        $encoded = '';

        foreach (str_split($bytes, self::FULL_BLOCK_SIZE) as $block) {
            $encoded .= self::encodeBlock($block);
        }

        return $encoded;
    }

    public static function decode(string $address): string
    {
        $bytes = '';

        foreach (str_split($address, self::FULL_ENCODED_BLOCK_SIZE) as $block) {
            $bytes .= self::decodeBlock($block);
        }

        return $bytes;
    }

    private static function encodeBlock(string $block): string
    {
        $size = self::ENCODED_BLOCK_SIZES[strlen($block)];
        $num = gmp_import($block, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $encoded = '';

        while (gmp_cmp($num, 0) > 0) {
            [$num, $remainder] = gmp_div_qr($num, 58);
            $encoded = self::B58_ALPHABET[gmp_intval($remainder)].$encoded;
        }

        return str_pad($encoded, $size, '1', STR_PAD_LEFT);
    }

    private static function decodeBlock(string $block): string
    {
        $size = array_search(strlen($block), self::ENCODED_BLOCK_SIZES, true);

        if ($size === false) {
            throw new \InvalidArgumentException('Invalid Monero base58 block length');
        }

        $num = gmp_init(0);

        foreach (str_split($block) as $char) {
            $digit = strpos(self::B58_ALPHABET, $char);

            if ($digit === false) {
                throw new \InvalidArgumentException('Invalid Monero base58 character');
            }

            $num = gmp_add(gmp_mul($num, 58), $digit);
        }

        $bytes = gmp_export($num, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        if (strlen($bytes) > $size) {
            throw new \InvalidArgumentException('Monero base58 block overflow');
        }

        return str_pad($bytes, $size, "\x00", STR_PAD_LEFT);
    }
}
