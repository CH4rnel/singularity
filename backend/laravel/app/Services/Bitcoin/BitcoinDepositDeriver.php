<?php

namespace App\Services\Bitcoin;

/**
 * One Bitcoin or Litecoin deposit address per bridge request.
 *
 * The same trick Yenten uses, for the same reason: an address that belongs to
 * exactly one request — whose recipient was committed before the address was
 * handed out — cannot be hijacked by somebody else pointing at a public
 * deposit transaction. Only the seed is secret; the address and its spending
 * key are re-derivable from the request id, so the database stores an address
 * and the relay re-derives what it needs to spend.
 *
 * The namespaces are load-bearing and must never change: addresses issued
 * under them hold real coins, and a changed namespace derives a different key
 * for the same index — the coins are not lost, they are simply no longer
 * findable by this server. 'btc-user'/'ltc-user' (per-user profile deposit
 * addresses, see UserDepositAddressService) are separate domains under the
 * same seed for exactly that reason.
 */
final class BitcoinDepositDeriver extends BitcoinFamilyAddressDeriver
{
    /**
     * Version bytes decide which chain an address belongs to, and a wrong one
     * is an address on a chain nobody here can spend from.
     *
     * @var array<string, array{namespace: string, pubkey_hash_version: int, wif_version: int}>
     */
    private const CHAINS = [
        'bitcoin' => ['namespace' => 'btc-deposit', 'pubkey_hash_version' => 0x00, 'wif_version' => 0x80],
        'litecoin' => ['namespace' => 'ltc-deposit', 'pubkey_hash_version' => 0x30, 'wif_version' => 0xB0],
    ];

    public static function supports(string $chainKey): bool
    {
        return isset(self::CHAINS[$chainKey]);
    }

    /**
     * Null where the chain is not one of these two or its seed is unset —
     * which is the corridor being switched off, not an error: without a seed
     * this server can neither hand out an address nor spend what lands on it,
     * and BridgeConfigService hides both routes.
     */
    public static function forChain(string $chainKey): ?self
    {
        $params = self::CHAINS[$chainKey] ?? null;
        $seed = (string) config("bridge.chains.{$chainKey}.hd_seed", '');

        if ($params === null || $seed === '') {
            return null;
        }

        return new self(
            $seed,
            $params['namespace'],
            $params['pubkey_hash_version'],
            $params['wif_version'],
        );
    }
}
