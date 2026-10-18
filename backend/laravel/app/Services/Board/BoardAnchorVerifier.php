<?php

namespace App\Services\Board;

use App\Models\BoardPost;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class BoardAnchorVerifier
{
    public function verify(BoardPost $post, string $hash): int
    {
        $chain = $this->rpc('eth_chainId');
        $tx = $this->rpc('eth_getTransactionByHash', [$hash]);
        $receipt = $this->rpc('eth_getTransactionReceipt', [$hash]);
        if ($chain !== '0xc0fe' || ! is_array($tx) || ! is_array($receipt)) {
            $this->refuse('Transaction is pending or could not be verified on Cyberia. Keep the hash and check again.');
        }
        $address = $post->author_address;
        if (! $address || strtolower($tx['hash'] ?? '') !== $hash || strtolower($receipt['transactionHash'] ?? '') !== $hash
            || strtolower($tx['from'] ?? '') !== $address || strtolower($tx['to'] ?? '') !== $address
            || strtolower($receipt['from'] ?? '') !== $address || strtolower($receipt['to'] ?? '') !== $address
            || ($tx['input'] ?? '') !== $post->anchorData() || ! preg_match('/^0x0+$/', $tx['value'] ?? '')
            || ! preg_match('/^0x[0-9a-f]{64}$/i', $receipt['blockHash'] ?? '')
            || ($receipt['status'] ?? '') !== '0x1' || ($tx['blockHash'] ?? null) !== ($receipt['blockHash'] ?? null)
            || ($tx['blockNumber'] ?? null) !== ($receipt['blockNumber'] ?? null)) {
            $this->refuse('Transaction does not prove this publication. Check its author, contents, recipient and successful receipt.');
        }
        $block = $this->number($receipt['blockNumber'] ?? null);
        $head = $this->number($this->rpc('eth_blockNumber'));
        $canonical = $this->rpc('eth_getBlockByNumber', [$receipt['blockNumber'], false]);
        if (! is_array($canonical) || ($canonical['hash'] ?? null) !== ($receipt['blockHash'] ?? null)
            || $head - $block + 1 < max(2, (int) config('board.confirmations'))) {
            $this->refuse('Waiting for a canonical Cyberia block and two confirmations. Keep the hash and check again.');
        }

        return $block;
    }

    private function rpc(string $method, array $params = []): mixed
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->post(config('board.rpc_url'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
            if ($response->successful() && ! $response->json('error')) {
                return $response->json('result');
            }
        } catch (ConnectionException) {
            $this->refuse('Cyberia RPC is unavailable. No proof was recorded; keep the transaction hash and retry.');
        }
        $this->refuse('Cyberia RPC could not verify the proof. Keep the transaction hash and retry.');
    }

    private function number(mixed $hex): int
    {
        if (! is_string($hex) || ! preg_match('/^0x[0-9a-f]{1,15}$/i', $hex)) {
            $this->refuse('The RPC returned an invalid block number.');
        }

        return (int) hexdec(substr($hex, 2));
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['tx_hash' => $message]);
    }
}
