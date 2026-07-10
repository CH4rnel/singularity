<?php

namespace App\Services;

use App\Models\BridgeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service-usage achievements, minted on-chain through CyberiaProfile.
 * Definitions live here (the contract stores dumb numeric ids); detection
 * reads what the platform already records: the Telegram bot's on-chain
 * indexer feed (activity_events: swap / liq_add / convert / lend_*) and the
 * bridge's own request table. check() awards anything newly earned via the
 * relayer, so badges are permanent and publicly verifiable.
 */
class AchievementService
{
    public const FIRST_SWAP = 1;

    public const FIRST_BRIDGE = 2;

    public const LIQUIDITY_FARMER = 3;

    public const CONVERTER = 4;

    public const LENDER = 5;

    public const NETRUNNER = 6;

    public function __construct(private readonly ProfileOnchainService $onchain) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            ['id' => self::FIRST_SWAP, 'key' => 'first_swap', 'title' => 'First Exchange', 'description' => 'Swap tokens on the Ritual DEX.', 'icon' => 'swap'],
            ['id' => self::FIRST_BRIDGE, 'key' => 'first_bridge', 'title' => 'Bridge Walker', 'description' => 'Complete a cross-chain bridge transfer.', 'icon' => 'bridge'],
            ['id' => self::LIQUIDITY_FARMER, 'key' => 'liquidity_farmer', 'title' => 'Liquidity Farmer', 'description' => 'Provide liquidity to a DEX pool.', 'icon' => 'liquidity'],
            ['id' => self::CONVERTER, 'key' => 'converter', 'title' => 'Converter', 'description' => 'Convert CYBER.sol into native CYBER.', 'icon' => 'convert'],
            ['id' => self::LENDER, 'key' => 'lender', 'title' => 'Lender', 'description' => 'Use the lending market.', 'icon' => 'lend'],
            ['id' => self::NETRUNNER, 'key' => 'netrunner', 'title' => 'Netrunner', 'description' => 'Claim an on-chain nickname.', 'icon' => 'nickname'],
        ];
    }

    /**
     * Definitions merged with the user's on-chain earned state, for the
     * profile page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forProfile(User $user): array
    {
        $earned = $user->wallet_address
            ? ($this->onchain->achievementsOf($user->wallet_address) ?? [])
            : [];

        return array_map(fn (array $definition) => [
            ...$definition,
            'earned' => in_array($definition['id'], $earned, true),
        ], $this->definitions());
    }

    /**
     * Detect newly-qualified achievements and award them on-chain.
     *
     * @return array<int, array<string, mixed>> the definitions just awarded
     */
    public function check(User $user): array
    {
        if (! $user->wallet_address || ! $this->onchain->enabled()) {
            return [];
        }

        $earned = $this->onchain->achievementsOf($user->wallet_address);

        if ($earned === null) {
            return [];
        }

        $newlyEarned = array_values(array_filter(
            $this->definitions(),
            fn (array $definition) => ! in_array($definition['id'], $earned, true)
                && $this->qualifies($user, (int) $definition['id']),
        ));

        if ($newlyEarned === []) {
            return [];
        }

        $txHash = $this->onchain->award(
            $user->wallet_address,
            array_map(fn (array $definition) => (int) $definition['id'], $newlyEarned),
        );

        return $txHash === null ? [] : $newlyEarned;
    }

    private function qualifies(User $user, int $id): bool
    {
        $wallet = strtolower((string) $user->wallet_address);

        return match ($id) {
            self::FIRST_SWAP => $this->hasActivityEvent($wallet, ['swap']),
            self::FIRST_BRIDGE => $this->hasCompletedBridge($user),
            self::LIQUIDITY_FARMER => $this->hasActivityEvent($wallet, ['liq_add']),
            self::CONVERTER => $this->hasActivityEvent($wallet, ['convert'])
                || $this->hasConvertBridge($user),
            self::LENDER => $this->hasActivityEvent($wallet, ['lend_%'], like: true),
            self::NETRUNNER => $this->onchain->nicknameOf((string) $user->wallet_address) !== null,
            default => false,
        };
    }

    /**
     * @param  array<int, string>  $kinds
     */
    private function hasActivityEvent(string $wallet, array $kinds, bool $like = false): bool
    {
        if ($wallet === '' || ! Schema::hasTable('activity_events')) {
            return false;
        }

        $query = DB::table('activity_events')
            ->whereRaw('LOWER(user_addr) = ?', [$wallet]);

        if ($like) {
            $query->where(function ($q) use ($kinds) {
                foreach ($kinds as $kind) {
                    $q->orWhere('kind', 'like', $kind);
                }
            });
        } else {
            $query->whereIn('kind', $kinds);
        }

        return $query->exists();
    }

    private function hasCompletedBridge(User $user): bool
    {
        $addresses = array_values(array_filter([
            strtolower((string) $user->wallet_address),
            strtolower((string) $user->solana_wallet_address),
        ]));

        if ($addresses === []) {
            return false;
        }

        return BridgeRequest::query()
            ->where('status', 'completed')
            ->where(function ($query) use ($addresses) {
                $query->whereIn(DB::raw('LOWER(sender_address)'), $addresses)
                    ->orWhereIn(DB::raw('LOWER(recipient_address)'), $addresses);
            })
            ->exists();
    }

    private function hasConvertBridge(User $user): bool
    {
        if (! $user->wallet_address) {
            return false;
        }

        return BridgeRequest::query()
            ->where('status', 'completed')
            ->where('convert_to_native', true)
            ->whereRaw('LOWER(recipient_address) = ?', [strtolower((string) $user->wallet_address)])
            ->exists();
    }
}
