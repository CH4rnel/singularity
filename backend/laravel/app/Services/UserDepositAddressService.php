<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDepositAddress;
use App\Services\Bitcoin\BitcoinFamilyAddressDeriver;
use App\Services\Monero\MoneroAddressCodec;
use App\Services\Yenten\YentenAddressDeriver;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Personal CEX-style deposit addresses, one per user per external chain.
 * Bitcoin-family chains (BTC/LTC/YTN) derive a P2PKH address from the chain's
 * HD seed at index = user id — the WIF spending key is re-derivable for
 * sweeping, only the seed is secret. Monero issues an integrated address
 * (main wallet + per-user payment id), so deposits land directly in the hot
 * wallet with no key to sweep. EVM chains keep the shared relayer EOA and
 * Solana/TON keep their shared hot wallets — those flows are unchanged.
 *
 * An address, once issued, is honored forever: existing rows always win over
 * fresh derivation, so a seed rotation can never silently repoint users.
 */
class UserDepositAddressService
{
    /**
     * Per-user HMAC namespaces are distinct from per-request ones (Yenten
     * one-time addresses use 'ytn-deposit'), so the two schemes can never
     * collide under a shared seed.
     */
    private const BITCOIN_FAMILY = [
        'bitcoin' => ['namespace' => 'btc-user', 'pubkey_hash_version' => 0x00, 'wif_version' => 0x80],
        'litecoin' => ['namespace' => 'ltc-user', 'pubkey_hash_version' => 0x30, 'wif_version' => 0xB0],
        'yenten' => ['namespace' => 'ytn-user', 'pubkey_hash_version' => YentenAddressDeriver::PUBKEY_HASH_VERSION, 'wif_version' => YentenAddressDeriver::WIF_VERSION],
    ];

    public const CHAINS = ['bitcoin', 'litecoin', 'yenten', 'monero'];

    /**
     * The user's personal deposit addresses, keyed by chain. Chains whose
     * operator setup (seed / main wallet) is missing are simply absent.
     *
     * @return array<string, string>
     */
    public function addressesFor(User $user): array
    {
        $existing = UserDepositAddress::query()
            ->where('user_id', $user->id)
            ->pluck('address', 'chain')
            ->all();

        $addresses = [];

        foreach (self::CHAINS as $chain) {
            $address = $existing[$chain] ?? $this->issue($chain, $user);

            if ($address !== null) {
                $addresses[$chain] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Deposit addresses from accounts merged into $user, keyed by chain,
     * most recently issued first. Rows are never mutated or reassigned on
     * merge — this only reads user_deposit_addresses rows still owned by
     * the absorbed account(s), so the WIF-by-user-id derivation invariant
     * (see class docblock) is never broken.
     *
     * @return array<string, array<int, string>>
     */
    public function mergedHistoryFor(User $user): array
    {
        $absorbedIds = $user->mergedAccounts()->pluck('id');

        if ($absorbedIds->isEmpty()) {
            return [];
        }

        return UserDepositAddress::query()
            ->whereIn('user_id', $absorbedIds)
            ->orderByDesc('created_at')
            ->get(['chain', 'address'])
            ->groupBy('chain')
            ->map(fn ($rows) => $rows->pluck('address')->all())
            ->all();
    }

    /**
     * Derive the user's address on a chain from current config (no storage).
     */
    public function derive(string $chain, int $userId): ?string
    {
        if ($chain === 'monero') {
            return $this->deriveMonero($userId);
        }

        return $this->deriver($chain)?->depositAddress($userId);
    }

    /**
     * WIF spending key for the user's deposit address (bitcoin-family only —
     * Monero deposits land directly in the main wallet). Handle with care:
     * never log it and never write it anywhere persistent.
     */
    public function wif(string $chain, int $userId): ?string
    {
        return $this->deriver($chain)?->childWif($userId);
    }

    private function issue(string $chain, User $user): ?string
    {
        $address = $this->derive($chain, $user->id);

        if ($address === null) {
            return null;
        }

        try {
            UserDepositAddress::create([
                'user_id' => $user->id,
                'chain' => $chain,
                'address' => $address,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent first visit: the stored row wins.
            return UserDepositAddress::query()
                ->where('user_id', $user->id)
                ->where('chain', $chain)
                ->value('address');
        }

        return $address;
    }

    private function deriver(string $chain): ?BitcoinFamilyAddressDeriver
    {
        $params = self::BITCOIN_FAMILY[$chain] ?? null;
        $seed = (string) config("bridge.chains.{$chain}.hd_seed", '');

        if ($params === null || $seed === '') {
            return null;
        }

        return new BitcoinFamilyAddressDeriver(
            $seed,
            $params['namespace'],
            $params['pubkey_hash_version'],
            $params['wif_version'],
        );
    }

    private function deriveMonero(int $userId): ?string
    {
        $mainAddress = (string) config('bridge.chains.monero.deposit_address', '');
        $seedHex = ltrim((string) config('bridge.chains.monero.hd_seed', ''), '0x');

        if ($mainAddress === '' || ! ctype_xdigit($seedHex) || strlen($seedHex) < 32) {
            return null;
        }

        $seed = hex2bin(strlen($seedHex) % 2 === 0 ? $seedHex : '0'.$seedHex);
        $paymentId = substr(hash_hmac('sha256', "xmr-user:{$userId}:0", $seed, true), 0, 8);

        return MoneroAddressCodec::integratedAddress($mainAddress, $paymentId);
    }
}
