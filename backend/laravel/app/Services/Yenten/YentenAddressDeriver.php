<?php

namespace App\Services\Yenten;

use App\Services\Bitcoin\BitcoinFamilyAddressDeriver;

/**
 * Deterministically derives a unique Yenten (Bitcoin-like) deposit address per
 * bridge request from a single master seed. Only the seed is secret; addresses
 * and their spending keys are re-derivable from the request id (the index), so
 * we store just the address. Binding one deposit address to one request (whose
 * recipient is committed at derivation time) is what makes hijacking a public
 * deposit transaction impossible.
 *
 * The 'ytn-deposit' namespace is load-bearing: live one-time addresses were
 * issued under it, so it must never change. Per-user profile addresses use the
 * separate 'ytn-user' namespace (see UserDepositAddressService).
 */
class YentenAddressDeriver extends BitcoinFamilyAddressDeriver
{
    /** Yenten P2PKH version byte (pubKeyHash) — see crypto/yenten YENTEN_NETWORK. */
    public const PUBKEY_HASH_VERSION = 0x4E;

    /** Yenten WIF version byte. */
    public const WIF_VERSION = 0x7B;

    public function __construct(string $seedHex)
    {
        parent::__construct($seedHex, 'ytn-deposit', self::PUBKEY_HASH_VERSION, self::WIF_VERSION);
    }

    public static function fromConfig(): self
    {
        $seed = (string) config('bridge.chains.yenten.hd_seed', '');

        if ($seed === '') {
            throw new \RuntimeException('BRIDGE_YENTEN_HD_SEED is not configured');
        }

        return new self($seed);
    }
}
