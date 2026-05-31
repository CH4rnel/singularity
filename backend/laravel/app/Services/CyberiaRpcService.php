<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CyberiaRpcService
{
    public function nativeBalanceWei(string $address): ?string
    {
        $rpc = config('services.bridge.evm_rpc_url')
            ?: config('services.ethereum.rpc_url', 'https://rpc.cyberia.church');

        try {
            $response = Http::timeout(10)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_getBalance',
                'params' => [$address, 'latest'],
            ]);

            if (! $response->successful()) {
                return null;
            }

            $hex = $response->json('result');

            if (! is_string($hex) || ! str_starts_with($hex, '0x')) {
                return null;
            }

            // Convert hex wei to decimal string.
            $hex = strtolower(substr($hex, 2));
            $dec = '0';
            $len = strlen($hex);

            for ($i = 0; $i < $len; $i++) {
                $dec = bcadd(bcmul($dec, '16'), (string) hexdec($hex[$i]));
            }

            return $dec;
        } catch (\Throwable $e) {
            Log::warning('CyberiaRpc: balance lookup failed', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
