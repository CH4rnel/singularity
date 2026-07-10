<?php

namespace App\Services\Bitcoin;

use Elliptic\EC;

/**
 * Deterministically derives Bitcoin-family (BTC/LTC/YTN) P2PKH deposit
 * addresses from a single master seed. Only the seed is secret; addresses and
 * their WIF spending keys are re-derivable from an index, so only addresses
 * need storing. The HMAC namespace isolates derivation domains — e.g. Yenten
 * per-request one-time addresses ('ytn-deposit') can never collide with
 * per-user profile addresses ('ytn-user') under the same seed.
 */
class BitcoinFamilyAddressDeriver
{
    /** secp256k1 curve order n. */
    private const CURVE_N_HEX = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';

    private const B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public function __construct(
        private readonly string $seedHex,
        private readonly string $namespace,
        private readonly int $pubKeyHashVersion,
        private readonly int $wifVersion,
    ) {}

    /**
     * 32-byte child private key (hex) for an index. Uses HMAC-SHA256 as a
     * KDF; on the astronomically unlikely event the output is not a valid
     * secp256k1 scalar, bump a counter and re-derive.
     */
    public function childPrivateKeyHex(int $index): string
    {
        $seed = $this->seedBytes();
        $counter = 0;

        do {
            $priv = hash_hmac('sha256', "{$this->namespace}:{$index}:{$counter}", $seed, true);
            $counter++;
        } while (! $this->isValidScalar($priv));

        return bin2hex($priv);
    }

    /** Unique P2PKH deposit address (base58check) for an index. */
    public function depositAddress(int $index): string
    {
        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($this->childPrivateKeyHex($index), 'hex');
        $pubKey = hex2bin($key->getPublic(true, 'hex')); // compressed (33 bytes)

        $hash160 = hash('ripemd160', hash('sha256', $pubKey, true), true);

        return $this->base58check(chr($this->pubKeyHashVersion).$hash160);
    }

    /** WIF (compressed) for spending an index's deposit address (sweeping). */
    public function childWif(int $index): string
    {
        $priv = hex2bin($this->childPrivateKeyHex($index));

        // 0x01 suffix marks a compressed-pubkey key, matching bitcoinjs ECPair.
        return $this->base58check(chr($this->wifVersion).$priv."\x01");
    }

    private function seedBytes(): string
    {
        $hex = ltrim($this->seedHex, '0x');

        if (! ctype_xdigit($hex) || strlen($hex) < 32) {
            throw new \RuntimeException('HD seed must be at least 16 bytes of hex');
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
