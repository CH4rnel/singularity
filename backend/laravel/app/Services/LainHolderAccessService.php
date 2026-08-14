<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Gate for the "Talk to Lain" chat: the signed-in user's EVM wallet must hold
 * a minimum share of the live $LAIN ERC-20 supply (10% by default). Balances
 * are read from the Cyberia RPC and cached briefly so a conversation does not
 * hammer the node on every message.
 */
class LainHolderAccessService
{
    private const CACHE_SECONDS = 30;

    public function __construct(private Erc20SupplyReader $erc20) {}

    /**
     * @return array{wallet: string, balance: string, total_supply: string, minimum_balance: string, share_bps: int, qualifies: bool}
     */
    public function status(string $wallet): array
    {
        $wallet = strtolower($wallet);

        return Cache::remember(
            "lain-holder:{$wallet}",
            self::CACHE_SECONDS,
            fn () => $this->liveStatus($wallet),
        );
    }

    /**
     * @return array{wallet: string, balance: string, total_supply: string, minimum_balance: string, share_bps: int, qualifies: bool}
     */
    private function liveStatus(string $wallet): array
    {
        $rpc = (string) (config('services.ethereum.rpc_url') ?: 'https://rpc.cyberia.church');
        $token = (string) config('services.lain.token_address');

        ['balance' => $balance, 'total_supply' => $totalSupply] = $this->erc20->holding($rpc, $token, $wallet);
        $minimumShareBps = (int) config('services.lain.minimum_share_bps', 1000);

        if (bccomp($totalSupply, '0') <= 0) {
            throw new RuntimeException('LAIN total supply is unavailable.');
        }

        $minimumBalance = $this->erc20->minimumBalance($totalSupply, $minimumShareBps);
        $shareBps = $this->erc20->shareBps($balance, $totalSupply);

        return [
            'wallet' => $wallet,
            'balance' => $balance,
            'total_supply' => $totalSupply,
            'minimum_balance' => $minimumBalance,
            'share_bps' => $shareBps,
            'qualifies' => bccomp(
                bcmul($balance, '10000'),
                bcmul($totalSupply, (string) $minimumShareBps),
            ) >= 0,
        ];
    }
}
