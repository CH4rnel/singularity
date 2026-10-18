<?php

namespace App\Services\Arena;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Http;

class ArenaMarketFeed
{
    /** @return array<string, mixed> */
    public function specification(): array
    {
        return [
            'chain_id' => 8453,
            'factory' => '0x33128a8fc17869897dce68ed026d694621f6fdfd',
            'pool' => '0xd0b53d9277642d899df5c87a3966a349a798f224',
            'token0' => '0x4200000000000000000000000000000000000006',
            'token1' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
            'decimals0' => 18, 'decimals1' => 6, 'pool_fee' => 500,
            'label' => 'Base Uniswap V3 WETH/USDC 0.05% spot; modeled paper execution',
        ];
    }

    /** @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    public function quote(array $spec, int $maxAge): array
    {
        $expected = $this->specification();
        ksort($spec);
        ksort($expected);
        if ($spec !== $expected) {
            throw new DomainException('market_specification_changed');
        }
        $head = $this->batch([['eth_chainId', []], ['eth_getBlockByNumber', ['latest', false]]]);
        $block = $head[1];
        if ($this->decimal($head[0]) !== '8453' || ! is_array($block)
            || ! preg_match('/^0x[a-fA-F0-9]{64}$/D', $block['hash'] ?? '')) {
            throw new DomainException('invalid_market_head');
        }
        $number = $this->decimal($block['number'] ?? '');
        $timestamp = $this->decimal($block['timestamp'] ?? '');
        if (strlen($timestamp) > 11 || $number === '0') {
            throw new DomainException('invalid_market_head');
        }
        $blockAt = CarbonImmutable::createFromTimestampUTC((int) $timestamp);
        $now = CarbonImmutable::now('UTC');
        if ($blockAt->lessThan($now->subSeconds($maxAge)) || $blockAt->greaterThan($now->addSeconds(5))) {
            throw new DomainException('stale_market_head');
        }
        $poolData = '0x1698ee82'.str_pad(substr($spec['token0'], 2), 64, '0', STR_PAD_LEFT)
            .str_pad(substr($spec['token1'], 2), 64, '0', STR_PAD_LEFT).str_pad(dechex($spec['pool_fee']), 64, '0', STR_PAD_LEFT);
        $calls = [[$spec['factory'], $poolData], [$spec['pool'], '0x0dfe1681'], [$spec['pool'], '0xd21220a7'],
            [$spec['pool'], '0xddca3f43'], [$spec['pool'], '0x3850c7bd'], [$spec['pool'], '0x1a686502'],
            [$spec['token0'], '0x313ce567'], [$spec['token1'], '0x313ce567']];
        $values = $this->batch(array_map(fn ($call) => ['eth_call', [['to' => $call[0], 'data' => $call[1]], $block['number']]], $calls));
        foreach ([0 => $spec['pool'], 1 => $spec['token0'], 2 => $spec['token1']] as $index => $address) {
            if (! is_string($values[$index]) || strtolower($values[$index]) !== '0x'.str_repeat('0', 24).substr($address, 2)) {
                throw new DomainException('wrong_market_contract');
            }
        }
        if ($this->decimal($values[3]) !== '500' || $this->decimal($values[6]) !== '18'
            || $this->decimal($values[7]) !== '6' || ! is_string($values[4]) || ! preg_match('/^0x[a-fA-F0-9]{448}$/D', $values[4])
            || $this->decimal('0x'.substr($values[4], -64)) !== '1' || $this->decimal($values[5]) === '0') {
            throw new DomainException('invalid_market_state');
        }
        $check = $this->batch([['eth_getBlockByNumber', [$block['number'], false]]])[0];
        if (! is_array($check) || ($check['hash'] ?? null) !== $block['hash']) {
            throw new DomainException('market_block_changed');
        }
        if ($blockAt->lessThan(CarbonImmutable::now('UTC')->subSeconds($maxAge))) {
            throw new DomainException('stale_market_head');
        }
        $sqrtPrice = $this->decimal('0x'.substr($values[4], 2, 64));
        $price = bcdiv(bcmul(bcmul($sqrtPrice, $sqrtPrice, 0), '1000000000000000000', 0), bcpow('2', '192', 0), 0);
        if (bccomp($price, '1', 0) < 0 || bccomp($price, '100000000000000', 0) > 0) {
            throw new DomainException('invalid_market_price');
        }

        return [
            'price_micros' => $price, 'observed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'block_at' => $blockAt->toIso8601String(),
            'source' => ['chain_id' => 8453, 'block_number' => $number, 'block_hash' => $block['hash'], 'label' => $spec['label']],
            'sqrt_price_x96' => $sqrtPrice, 'liquidity' => $this->decimal($values[5]),
        ];
    }

    /** @param array<int, array{0: string, 1: array<mixed>}> $calls
     * @return array<mixed>
     */
    private function batch(array $calls): array
    {
        $url = (string) config('arena.automation.rpc_url');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new DomainException('market_rpc_requires_https');
        }
        $payload = [];
        foreach ($calls as $id => [$method, $params]) {
            $payload[] = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
        }
        $response = Http::acceptJson()->connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false])->post($url, $payload);
        $body = $response->json();
        if (! $response->successful() || strlen($response->body()) > 262144 || ! is_array($body) || count($body) !== count($calls)) {
            throw new DomainException('market_rpc_failed');
        }
        $results = [];
        foreach ($body as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || ! array_key_exists($row['id'], $calls)
                || array_key_exists($row['id'], $results) || isset($row['error']) || ! isset($row['result'])) {
                throw new DomainException('invalid_market_rpc_response');
            }
            $results[$row['id']] = $row['result'];
        }
        ksort($results);

        return array_values($results);
    }

    private function decimal(mixed $hex): string
    {
        if (! is_string($hex) || ! preg_match('/^0x[a-fA-F0-9]{1,64}$/D', $hex)) {
            throw new DomainException('invalid_market_integer');
        }
        $result = '0';
        foreach (str_split(substr($hex, 2)) as $digit) {
            $result = bcadd(bcmul($result, '16', 0), (string) hexdec($digit), 0);
        }

        return $result;
    }
}
