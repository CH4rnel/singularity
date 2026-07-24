<?php

namespace App\Services;

use App\Support\TokenAmount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * How much of a token the relayer can actually pay out to a destination chain
 * right now — the live withdrawal capacity shown in the bridge UI.
 *
 * With unified wrappers (one USDC across Solana + Base, one USDT across Solana
 * + BNB, …) a token's Cyberia supply is NOT tied to any single chain's reserve,
 * so the per-chain limit is enforced here from the relayer's live inventory on
 * the destination chain instead of by minting a separate wrapper per chain.
 */
class BridgeInventoryService
{
    public function __construct(
        private BridgeRelayerService $relayer,
        private BridgeFeeService $fee,
    ) {}

    /**
     * Live max the user can withdraw of $token via $direction right now, in
     * human token units (string). null = effectively unlimited (the relayer
     * mints on the home chain) or capacity can't be determined (leave the UI
     * uncapped rather than block on a flaky read).
     */
    public function destinationCapacity(string $direction, string $token): ?string
    {
        $route = config('bridge.routes', [])[$direction] ?? null;

        if (! is_array($route)) {
            return null;
        }

        $chainKey = (string) ($route['destination_chain'] ?? '');
        $chain = config('bridge.chains', [])[$chainKey] ?? null;
        $tokenConfig = config('bridge.tokens', [])[$token] ?? null;

        if (! is_array($chain) || ! is_array($tokenConfig)) {
            return null;
        }

        $entry = $tokenConfig['chains'][$chainKey] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        // Into the home chain with a minting token there is no inventory cap:
        // 'mint' — the relayer mints the wrapper on demand; 'native' — the
        // CyberBridge contract mints on release (releaseCyberSol), so the
        // relayer's own wallet balance (mostly accrued fees) is irrelevant.
        // Exception: a native-entry payout (bridged CYBER coming home) spends
        // the relayer's own coin balance, so it IS inventory-capped below.
        if ($chainKey === config('bridge.home_chain', 'cyberia')
            && in_array($tokenConfig['model'] ?? 'direct', ['mint', 'native'], true)
            && ! ($entry['native'] ?? false)) {
            return null;
        }

        return match ($chain['type'] ?? '') {
            'evm' => $this->evmCapacity($direction, $token, $chain, $entry),
            'solana' => $this->solanaCapacity($chain, $entry),
            'ton' => $this->tonCapacity($direction, $token, $chain, $entry),
            default => null, // Yenten: not computed (manual reserves)
        };
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $entry
     */
    private function evmCapacity(string $direction, string $token, array $chain, array $entry): ?string
    {
        $relayer = $this->relayer->evmAddress();
        $rpc = (string) ($chain['rpc_url'] ?? '');
        $decimals = (int) ($entry['decimals'] ?? 18);

        if ($relayer === null || $rpc === '') {
            return null;
        }

        // Native-coin payout (e.g. ETH on Base, BNB on BSC): balance minus the
        // gas the payout retains, so the shown max is actually deliverable.
        if ($entry['native'] ?? false) {
            $balanceRaw = $this->evmCall($rpc, 'eth_getBalance', [$relayer, 'latest']);

            if ($balanceRaw === null) {
                return null;
            }

            $reserveRaw = TokenAmount::toRaw($this->fee->nativePayoutFee($direction, $token), $decimals);
            $available = bccomp($balanceRaw, $reserveRaw, 0) > 0
                ? bcsub($balanceRaw, $reserveRaw, 0)
                : '0';

            return TokenAmount::fromRaw($available, $decimals);
        }

        // ERC20 payout from relayer inventory: balanceOf(relayer).
        $address = (string) ($entry['address'] ?? '');

        if ($address === '') {
            return null;
        }

        $data = '0x70a08231'.str_pad(substr($relayer, 2), 64, '0', STR_PAD_LEFT);
        $balanceRaw = $this->evmCall($rpc, 'eth_call', [['to' => $address, 'data' => $data], 'latest']);

        return $balanceRaw === null ? null : TokenAmount::fromRaw($balanceRaw, $decimals);
    }

    /**
     * Single JSON-RPC read returning a decimal string, or null on any failure.
     *
     * @param  array<int, mixed>  $params
     */
    private function evmCall(string $rpc, string $method, array $params): ?string
    {
        try {
            $response = Http::timeout(8)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);

            $hex = $response->json('result');

            if (! $response->successful() || ! is_string($hex) || ! str_starts_with($hex, '0x')) {
                return null;
            }

            return TokenAmount::hexToDec($hex);
        } catch (\Throwable $e) {
            Log::warning('Bridge inventory: evm read failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * TON hot-wallet inventory via tonapi: native Toncoin balance for TON
     * payouts (minus the retained fee reserve), jetton balance for jetton
     * payouts.
     *
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $entry
     */
    private function tonCapacity(string $direction, string $token, array $chain, array $entry): ?string
    {
        $hotWallet = (string) ($chain['deposit_address'] ?? '');
        $apiUrl = rtrim((string) ($chain['api_url'] ?? 'https://tonapi.io'), '/');
        $decimals = (int) ($entry['decimals'] ?? 9);

        if ($hotWallet === '') {
            return null;
        }

        $path = ($entry['native'] ?? false)
            ? '/v2/accounts/'.rawurlencode($hotWallet)
            : '/v2/accounts/'.rawurlencode($hotWallet).'/jettons/'.rawurlencode((string) ($entry['master'] ?? ''));

        try {
            $request = Http::timeout(8)->acceptJson();

            if (! empty($chain['api_key'])) {
                $request = $request->withToken((string) $chain['api_key']);
            }

            $response = $request->get($apiUrl.$path);

            if (! $response->successful()) {
                return null;
            }

            $balanceRaw = (string) $response->json('balance');

            if (! ctype_digit($balanceRaw)) {
                return null;
            }

            if ($entry['native'] ?? false) {
                // Keep back the flat payout fee reserve so the shown max is
                // actually deliverable.
                $reserveRaw = TokenAmount::toRaw($this->fee->nativePayoutFee($direction, $token), $decimals);
                $balanceRaw = bccomp($balanceRaw, $reserveRaw, 0) > 0
                    ? bcsub($balanceRaw, $reserveRaw, 0)
                    : '0';
            }

            return TokenAmount::fromRaw($balanceRaw, $decimals);
        } catch (\Throwable $e) {
            Log::warning('Bridge inventory: ton read failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $entry
     */
    private function solanaCapacity(array $chain, array $entry): ?string
    {
        $hotWallet = (string) ($chain['deposit_address'] ?? '');
        $mint = (string) ($entry['mint'] ?? '');
        $rpc = (string) ($chain['rpc_url'] ?? '');

        if ($hotWallet === '' || $rpc === '') {
            return null;
        }

        // Native SOL payout: hot-wallet lamports minus a reserve that keeps
        // the account rent-exempt and covers transaction fees.
        if ($entry['native'] ?? false) {
            return $this->solanaNativeCapacity($hotWallet, $rpc, (int) ($entry['decimals'] ?? 9));
        }

        if ($mint === '') {
            return null;
        }

        try {
            $response = Http::timeout(8)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTokenAccountsByOwner',
                'params' => [
                    $hotWallet,
                    ['mint' => $mint],
                    ['encoding' => 'jsonParsed'],
                ],
            ]);

            $accounts = $response->json('result.value');

            if (! is_array($accounts)) {
                return null;
            }

            $total = '0';

            foreach ($accounts as $account) {
                $raw = $account['account']['data']['parsed']['info']['tokenAmount']['amount'] ?? null;
                $dec = $account['account']['data']['parsed']['info']['tokenAmount']['decimals'] ?? null;

                if (is_string($raw) && is_int($dec)) {
                    $total = bcadd($total, TokenAmount::fromRaw($raw, $dec), 18);
                }
            }

            return $total;
        } catch (\Throwable $e) {
            Log::warning('Bridge inventory: solana read failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function solanaNativeCapacity(string $hotWallet, string $rpc, int $decimals): ?string
    {
        // 0.01 SOL held back: rent-exempt minimum (~0.00089 SOL) plus
        // headroom for SPL payout fees from the same hot wallet.
        $reserveRaw = TokenAmount::toRaw('0.01', $decimals);

        try {
            $response = Http::timeout(8)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getBalance',
                'params' => [$hotWallet],
            ]);

            $lamports = $response->json('result.value');

            if (! is_int($lamports) && ! is_string($lamports)) {
                return null;
            }

            $balanceRaw = (string) $lamports;
            $available = bccomp($balanceRaw, $reserveRaw, 0) > 0
                ? bcsub($balanceRaw, $reserveRaw, 0)
                : '0';

            return TokenAmount::fromRaw($available, $decimals);
        } catch (\Throwable $e) {
            Log::warning('Bridge inventory: solana native read failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
