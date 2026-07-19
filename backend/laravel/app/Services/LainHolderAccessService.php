<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        $balance = $this->call('0x70a08231'.str_pad(substr($wallet, 2), 64, '0', STR_PAD_LEFT));
        $totalSupply = $this->call('0x18160ddd');
        $minimumShareBps = (int) config('services.lain.minimum_share_bps', 1000);

        if (bccomp($totalSupply, '0') <= 0) {
            throw new RuntimeException('LAIN total supply is unavailable.');
        }

        $minimumBalance = bcdiv(
            bcadd(bcmul($totalSupply, (string) $minimumShareBps), '9999'),
            '10000',
            0,
        );
        $shareBps = (int) bcdiv(bcmul($balance, '10000'), $totalSupply, 0);

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

    private function call(string $data): string
    {
        $response = Http::timeout(10)->post(
            (string) (config('services.ethereum.rpc_url') ?: 'https://rpc.cyberia.church'),
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_call',
                'params' => [
                    [
                        'to' => (string) config('services.lain.token_address'),
                        'data' => $data,
                    ],
                    'latest',
                ],
            ],
        );

        $result = $response->json('result');

        if (! $response->successful() || ! is_string($result) || ! preg_match('/^0x[0-9a-fA-F]+$/', $result)) {
            throw new RuntimeException('Cyberia RPC did not return a valid LAIN balance.');
        }

        return $this->hexToDecimal(substr($result, 2));
    }

    private function hexToDecimal(string $hex): string
    {
        $decimal = '0';

        foreach (str_split(strtolower($hex)) as $digit) {
            $decimal = bcadd(bcmul($decimal, '16'), (string) hexdec($digit));
        }

        return $decimal;
    }
}
