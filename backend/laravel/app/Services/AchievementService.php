<?php

namespace App\Services;

use App\Models\BridgeRequest;
use App\Models\User;
use App\Services\Achievements\ChainIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Service-usage achievements, minted on-chain through CyberiaProfile.
 * Definitions live here (the contract stores dumb numeric ids).
 *
 * Detection reads two sources in that order, and the order is the fix for the
 * bug that made half these badges unearnable. The **fast path** is what the
 * platform already records — the Telegram bot's announcer feed
 * (`activity_events`) and the bridge's own request table. The **truth** is
 * `ChainIndex`, which reads the explorer's full history of the address.
 *
 * The feed alone was wrong because it is forward-only: every announcer
 * bootstraps its cursor to the head of the chain the first time it runs, so it
 * cannot see anything older than its own first tick. On prod that is
 * 2026-08-05, while the operator's 27 lending transactions start 2026-05-21 —
 * the person who had used the market most could never earn the badge for it.
 *
 * A badge is permanent and publicly verifiable, so it is granted only on a
 * definite yes: an unreadable explorer answers `null`, `qualifies()` treats
 * that as "not yet" rather than "no", and the next sweep asks again.
 */
class AchievementService
{
    public const FIRST_SWAP = 1;

    public const FIRST_BRIDGE = 2;

    public const LIQUIDITY_FARMER = 3;

    public const CONVERTER = 4;

    public const LENDER = 5;

    public const NETRUNNER = 6;

    public function __construct(
        private readonly ProfileOnchainService $onchain,
        private readonly ChainIndex $chain,
    ) {}

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
     * What this user has earned but does not yet hold on-chain.
     *
     * Null when the question could not be asked at all — no wallet, no
     * contract, or an RPC that would not say what they already hold. That is
     * kept apart from an empty array, which means "asked, nothing owing": a
     * sweep must be able to tell a quiet user from an unreachable chain.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function pending(User $user): ?array
    {
        if (! $user->wallet_address || ! $this->onchain->enabled()) {
            return null;
        }

        $earned = $this->onchain->achievementsOf($user->wallet_address);

        if ($earned === null) {
            return null;
        }

        return array_values(array_filter(
            $this->definitions(),
            fn (array $definition) => ! in_array($definition['id'], $earned, true)
                && $this->qualifies($user, (int) $definition['id']),
        ));
    }

    /**
     * Detect newly-qualified achievements and award them on-chain.
     *
     * @return array<int, array<string, mixed>> the definitions just awarded
     */
    public function check(User $user): array
    {
        return $this->award($user)['awarded'];
    }

    /**
     * Award what is owing, and say what happened.
     *
     * `check()` collapses every one of these outcomes into an empty array,
     * which is how a badge the operator had earned sat unminted for weeks with
     * nothing anywhere saying so. Callers that can report — the sweep, the
     * console — use this instead.
     *
     * @return array{awarded: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>, unreadable: bool}
     */
    public function award(User $user): array
    {
        $pending = $this->pending($user);

        if ($pending === null) {
            return ['awarded' => [], 'failed' => [], 'unreadable' => true];
        }

        if ($pending === []) {
            return ['awarded' => [], 'failed' => [], 'unreadable' => false];
        }

        $txHash = $this->onchain->award(
            (string) $user->wallet_address,
            array_map(fn (array $definition) => (int) $definition['id'], $pending),
        );

        if ($txHash === null) {
            Log::warning('Achievement award failed', [
                'user_id' => $user->id,
                'wallet' => $user->wallet_address,
                'ids' => array_column($pending, 'id'),
            ]);

            return ['awarded' => [], 'failed' => $pending, 'unreadable' => false];
        }

        return ['awarded' => $pending, 'failed' => [], 'unreadable' => false];
    }

    private function qualifies(User $user, int $id): bool
    {
        $wallet = strtolower((string) $user->wallet_address);

        return match ($id) {
            self::FIRST_SWAP => $this->hasActivityEvent($wallet, ['swap']),
            self::FIRST_BRIDGE => $this->hasCompletedBridge($user),
            self::LIQUIDITY_FARMER => $this->hasActivityEvent($wallet, ['liq_add'])
                || $this->chain->addedLiquidity($wallet) === true,
            self::CONVERTER => $this->hasActivityEvent($wallet, ['convert'])
                || $this->hasConvertBridge($user)
                || $this->chain->convertedCyberSol($wallet) === true,
            // No feed fast path: `lend_*` has never once been written, so
            // reading it first would only cost a query.
            self::LENDER => $this->chain->usedLending($wallet) === true,
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
