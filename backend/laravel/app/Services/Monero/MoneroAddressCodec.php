<?php

namespace App\Services\Monero;

use kornrunner\Keccak;

/**
 * Monero address codec: the Monero-flavoured base58 (8-byte blocks encoded to
 * fixed 11-char groups, Keccak-256 checksum) plus integrated-address
 * construction. Integrated addresses embed an 8-byte payment id in the
 * operator's standard address, so per-user deposits land directly in the main
 * wallet while remaining attributable — no per-address keys to sweep.
 *
 * Monero addresses are not hashes of a key like an EVM address: they carry the
 * spend and view public keys plus a checksum, so a typo is detectable here
 * rather than on-chain. Everything Monero-shaped in the app validates through
 * this class; nothing about XMR is derived from its EVM wrapper.
 */
class MoneroAddressCodec
{
    /** Mainnet network bytes. */
    public const NETBYTE_STANDARD = 0x12;

    public const NETBYTE_INTEGRATED = 0x13;

    public const NETBYTE_SUBADDRESS = 0x2A;

    /**
     * Mainnet address kinds, keyed by network byte: the payload length each
     * one must decode to (network byte + spend key + view key, plus the
     * 8-byte payment id for integrated addresses). Testnet and stagenet bytes
     * are deliberately absent — the bridge is mainnet-only.
     */
    private const KINDS = [
        self::NETBYTE_STANDARD => ['standard', 65],
        self::NETBYTE_INTEGRATED => ['integrated', 73],
        self::NETBYTE_SUBADDRESS => ['subaddress', 65],
    ];

    private const B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /** Encoded size of a partial trailing block, indexed by its byte length. */
    private const ENCODED_BLOCK_SIZES = [0, 2, 3, 5, 6, 7, 9, 10, 11];

    private const FULL_BLOCK_SIZE = 8;

    private const FULL_ENCODED_BLOCK_SIZE = 11;

    /**
     * Which kind of mainnet address this is — 'standard', 'integrated' or
     * 'subaddress' — or null when the string is not a valid Monero address
     * (bad charset, wrong length, failed checksum, foreign network byte).
     */
    public static function kind(string $address): ?string
    {
        $payload = self::decodeChecked($address);

        if ($payload === null) {
            return null;
        }

        [$kind, $length] = self::KINDS[ord($payload[0])] ?? [null, 0];

        return $kind !== null && strlen($payload) === $length ? $kind : null;
    }

    public static function isValid(string $address): bool
    {
        return self::kind($address) !== null;
    }

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

    /**
     * A block is at most 8 bytes, i.e. a 64-bit number that does not fit a
     * signed PHP int, so the arithmetic runs on the big-endian byte array
     * itself (long division / multiply-add). That keeps the codec free of
     * ext-gmp and ext-bcmath.
     */
    private static function encodeBlock(string $block): string
    {
        $size = self::ENCODED_BLOCK_SIZES[strlen($block)];
        $bytes = array_values(unpack('C*', $block));
        $encoded = '';

        while (array_sum($bytes) > 0) {
            $remainder = 0;

            foreach ($bytes as $i => $byte) {
                $value = ($remainder << 8) | $byte;
                $bytes[$i] = intdiv($value, 58);
                $remainder = $value % 58;
            }

            $encoded = self::B58_ALPHABET[$remainder].$encoded;
        }

        return str_pad($encoded, $size, '1', STR_PAD_LEFT);
    }

    private static function decodeBlock(string $block): string
    {
        $size = array_search(strlen($block), self::ENCODED_BLOCK_SIZES, true);

        if ($size === false || $size === 0) {
            throw new \InvalidArgumentException('Invalid Monero base58 block length');
        }

        $bytes = array_fill(0, $size, 0);

        foreach (str_split($block) as $char) {
            $carry = strpos(self::B58_ALPHABET, $char);

            if ($carry === false) {
                throw new \InvalidArgumentException('Invalid Monero base58 character');
            }

            for ($i = $size - 1; $i >= 0; $i--) {
                $value = $bytes[$i] * 58 + $carry;
                $bytes[$i] = $value & 0xFF;
                $carry = $value >> 8;
            }

            if ($carry !== 0) {
                throw new \InvalidArgumentException('Monero base58 block overflow');
            }
        }

        return pack('C*', ...$bytes);
    }
}
