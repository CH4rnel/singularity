<?php

namespace App\Services;

use App\Support\TokenAmount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads from Cyberia Fuji (49407) over its private upstream.
 *
 * One small client for the faucet and the chain panel, so both ask the same
 * node the same way. Every read fails soft: a value that could not be read is
 * null, never zero, and the screens say so.
 */
class FujiRpc
{
    /** The result of one call, or null when the node did not answer. */
    public function request(string $method, array $params = []): mixed
    {
        try {
            $response = Http::connectTimeout(3)->timeout(10)->post($this->url(), [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params,
            ]);

            return $response->json('result');
        } catch (\Throwable $e) {
            Log::warning('Fuji RPC read failed', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** A 0x-prefixed result (a quantity or call data), or null. */
    public function hex(string $method, array $params = []): ?string
    {
        $result = $this->request($method, $params);

        return is_string($result) && str_starts_with($result, '0x') ? $result : null;
    }

    /** A quantity result as a decimal string, or null. */
    public function quantity(string $method, array $params = []): ?string
    {
        $result = $this->hex($method, $params);

        return $result !== null ? TokenAmount::hexToDec($result) : null;
    }

    /** Native CYBER balance in wei, or null. */
    public function balance(string $address): ?string
    {
        return $this->quantity('eth_getBalance', [$address, 'latest']);
    }

    /** eth_call at the latest block, or null. */
    public function call(string $to, string $data): ?string
    {
        return $this->hex('eth_call', [['to' => $to, 'data' => $data], 'latest']);
    }

    public function url(): string
    {
        return (string) config('bridge.chains.cyberia_fuji.rpc_url', 'https://cyberia.church/api/fuji/rpc');
    }

    public function chainId(): int
    {
        return (int) config('bridge.chains.cyberia_fuji.evm_chain_id', 49407);
    }
}
