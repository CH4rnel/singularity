<?php

namespace App\Actions\Wallet;

use Illuminate\Support\Facades\Http;

/**
 * Reads an owner's CYBER.sol (SPL token-2022) balance from Solana mainnet.
 *
 * CYBER.sol is the bridge-native token (mint mirrors config/bridge.php). We
 * sum the amount across every token account the owner holds for the mint —
 * normally just the associated token account, but summing is robust if a
 * wallet happens to hold several. No signature is required to read a balance,
 * so the same call powers both the one-time verify and the periodic re-check.
 */
class ReadCyberSolBalance
{
    /**
     * @return array{raw: string, amount: string, decimals: int}
     *   raw    — balance in base units (string; arbitrary precision)
     *   amount — human balance with `decimals` places (string)
     */
    public function handle(string $solanaAddress): array
    {
        $rpc = config('services.cyber_sol.rpc_url');
        $mint = config('services.cyber_sol.mint');
        $decimals = (int) config('services.cyber_sol.decimals', 6);

        $res = Http::timeout(15)->acceptJson()->post($rpc, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getTokenAccountsByOwner',
            'params' => [
                $solanaAddress,
                ['mint' => $mint],
                ['encoding' => 'jsonParsed', 'commitment' => 'confirmed'],
            ],
        ]);

        if (! $res->successful()) {
            throw new \RuntimeException('Solana RPC HTTP '.$res->status());
        }

        $body = $res->json();
        if (isset($body['error'])) {
            throw new \RuntimeException('Solana RPC error: '.json_encode($body['error']));
        }

        $raw = '0';
        foreach (($body['result']['value'] ?? []) as $account) {
            $amount = $account['account']['data']['parsed']['info']['tokenAmount']['amount'] ?? '0';
            $raw = bcadd($raw, (string) $amount, 0);
        }

        return [
            'raw' => $raw,
            'amount' => bcdiv($raw, bcpow('10', (string) $decimals), $decimals),
            'decimals' => $decimals,
        ];
    }

    /** True when `raw` base units meet or exceed `wholeTokens` of CYBER.sol. */
    public function meetsThreshold(string $raw, int|string $wholeTokens, int $decimals = 6): bool
    {
        $thresholdRaw = bcmul((string) $wholeTokens, bcpow('10', (string) $decimals), 0);

        return bccomp($raw, $thresholdRaw, 0) >= 0;
    }
}
