<?php

namespace App\Services\Yenten;

use Elliptic\EC;

/**
 * Deterministically derives a unique Yenten (Bitcoin-like) deposit address per
 * bridge request from a single master seed. Only the seed is secret; addresses
 * and their spending keys are re-derivable from the request id (the index), so
 * we store just the address. Binding one deposit address to one request (whose
 * recipient is committed at derivation time) is what makes hijacking a public
 * deposit transaction impossible.
 */
class YentenAddressDeriver
{
    /** Yenten P2PKH version byte (pubKeyHash) — see crypto/yenten YENTEN_NETWORK. */
    private const PUBKEY_HASH_VERSION = 0x4e;

    /** Yenten WIF version byte. */
    private const WIF_VERSION = 0x7b;

    /** secp256k1 curve order n. */
    private const CURVE_N_HEX = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    private const B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public function __construct(private readonly string $seedHex) {}

    public static function fromConfig(): self
    {
        $seed = (string) config('bridge.chains.yenten.hd_seed', '');

        if ($seed === '') {
            throw new \RuntimeException('BRIDGE_YENTEN_HD_SEED is not configured');
        }

        return new self($seed);
    }

    /**
     * 32-byte child private key (hex) for a request index. Uses HMAC-SHA256 as
     * a KDF; on the astronomically unlikely event the output is not a valid
     * secp256k1 scalar, bump a counter and re-derive.
     */
    public function childPrivateKeyHex(int $index): string
    {
        $seed = $this->seedBytes();
        $counter = 0;

        do {
            $priv = hash_hmac('sha256', "ytn-deposit:{$index}:{$counter}", $seed, true);
            $counter++;
        } while (! $this->isValidScalar($priv));

        return bin2hex($priv);
    }

    /** Unique P2PKH deposit address (base58check, version 0x4e) for a request. */
    public function depositAddress(int $index): string
    {
        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($this->childPrivateKeyHex($index), 'hex');
        $pubKey = hex2bin($key->getPublic(true, 'hex')); // compressed (33 bytes)

        $hash160 = hash('ripemd160', hash('sha256', $pubKey, true), true);

        return $this->base58check(chr(self::PUBKEY_HASH_VERSION).$hash160);
    }

    /** WIF (compressed) for spending the request's deposit address (sweeping). */
    public function childWif(int $index): string
    {
        $priv = hex2bin($this->childPrivateKeyHex($index));

        // 0x01 suffix marks a compressed-pubkey key, matching bitcoinjs ECPair.
        return $this->base58check(chr(self::WIF_VERSION).$priv."\x01");
    }

    private function seedBytes(): string
    {
        $hex = ltrim($this->seedHex, '0x');

        if (! ctype_xdigit($hex) || strlen($hex) < 32) {
            throw new \RuntimeException('BRIDGE_YENTEN_HD_SEED must be at least 16 bytes of hex');
        }

        return hex2bin(strlen($hex) % 2 === 0 ? $hex : '0'.$hex);
    }

    private function isValidScalar(string $priv): bool
    {
        $n = gmp_init(self::CURVE_N_HEX, 16);
        $k = gmp_init(bin2hex($priv), 16);

        return gmp_cmp($k, 0) > 0 && gmp_cmp($k, $n) < 0;
    }

    private function base58check(string $payload): string
    {
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return $this->base58encode($payload.$checksum);
    }

    private function base58encode(string $data): string
    {
        $num = gmp_import($data, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
        $encoded = '';

        while (gmp_cmp($num, 0) > 0) {
            [$num, $remainder] = gmp_div_qr($num, 58);
            $encoded = self::B58_ALPHABET[gmp_intval($remainder)].$encoded;
        }

        // Each leading zero byte becomes a '1'.
        for ($i = 0; $i < strlen($data) && $data[$i] === "\x00"; $i++) {
            $encoded = '1'.$encoded;
        }

        return $encoded;
    }
}
