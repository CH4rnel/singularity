<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Two `eth_call`s and the arithmetic around them: what an address holds of an
 * ERC-20, and how much of that token exists.
 *
 * Shared by every gate that asks "does this address hold enough" — the $LAIN
 * holders' room (LainHolderAccessService) and the inference API
 * (Ai\AiHolderGate). Balances arrive as 256-bit hex and are kept as decimal
 * strings throughout: a token with 18 decimals overflows PHP's integers long
 * before any interesting balance, so nothing here is ever cast to int.
 */
class Erc20SupplyReader
{
    /** balanceOf(address) */
    private const BALANCE_OF = '0x70a08231';

    /** totalSupply() */
    private const TOTAL_SUPPLY = '0x18160ddd';

    /**
     * @return array{balance: string, total_supply: string}
     */
    public function holding(string $rpcUrl, string $token, string $address): array
    {
        return [
            'balance' => $this->balanceOf($rpcUrl, $token, $address),
            'total_supply' => $this->totalSupply($rpcUrl, $token),
        ];
    }

    public function balanceOf(string $rpcUrl, string $token, string $address): string
    {
        return $this->call(
            $rpcUrl,
            $token,
            self::BALANCE_OF.str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT),
        );
    }

    public function totalSupply(string $rpcUrl, string $token): string
    {
        return $this->call($rpcUrl, $token, self::TOTAL_SUPPLY);
    }

    /**
     * The share $balance is of $totalSupply, in basis points (10000 = 100%).
     */
    public function shareBps(string $balance, string $totalSupply): int
    {
        if (bccomp($totalSupply, '0') <= 0) {
            return 0;
        }

        return (int) bcdiv(bcmul($balance, '10000'), $totalSupply, 0);
    }

    /**
     * The smallest balance that still counts as $shareBps of $totalSupply.
     *
     * Rounded up, so the number shown to someone who is short is a number that
     * would actually let them in.
     */
    public function minimumBalance(string $totalSupply, int $shareBps): string
    {
        return bcdiv(bcadd(bcmul($totalSupply, (string) $shareBps), '9999'), '10000', 0);
    }

    private function call(string $rpcUrl, string $token, string $data): string
    {
        $response = Http::timeout(10)->post($rpcUrl, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'eth_call',
            'params' => [
                ['to' => $token, 'data' => $data],
                'latest',
            ],
        ]);

        $result = $response->json('result');

        if (! $response->successful() || ! is_string($result) || ! preg_match('/^0x[0-9a-fA-F]+$/', $result)) {
            throw new RuntimeException('Cyberia RPC did not return a valid ERC-20 read.');
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
