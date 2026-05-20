<?php

use App\Services\Slots\PumpfunDiscoveryService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.slots.pumpfun_api_base', 'https://frontend-api-v3.pump.fun');
});

function makeCoin(string $mint, float $mcap): array
{
    return [
        'mint' => $mint,
        'symbol' => strtoupper(substr($mint, 0, 4)),
        'name' => 'Coin '.substr($mint, 0, 6),
        'image_uri' => 'https://cf-ipfs.com/ipfs/Qm'.substr($mint, 0, 10),
        'market_cap' => $mcap,
        'usd_market_cap' => $mcap,
    ];
}

it('listTop drops entries below the mcap floor', function () {
    Http::fake([
        'https://frontend-api-v3.pump.fun/coins*' => Http::response([
            makeCoin('AAA', 50000),
            makeCoin('BBB', 30000),
            makeCoin('CCC', 20000),
            makeCoin('DDD', 5000), // below floor — stops here
            makeCoin('EEE', 15000), // skipped because list is descending
        ]),
    ]);

    $service = new class extends PumpfunDiscoveryService
    {
        protected function sleep(int $ms): void {}
    };

    $result = $service->listTop(limit: 100, minMcapUsd: 10000);

    expect(count($result))->toBe(3);
    expect($result[0]['mint'])->toBe('AAA');
    expect($result[2]['mint'])->toBe('CCC');
});

it('listTop honors the limit even when more pages exist', function () {
    Http::fake([
        'https://frontend-api-v3.pump.fun/coins*' => Http::response([
            makeCoin('A1', 50000),
            makeCoin('A2', 40000),
            makeCoin('A3', 30000),
        ]),
    ]);

    $service = new class extends PumpfunDiscoveryService
    {
        protected function sleep(int $ms): void {}
    };

    $result = $service->listTop(limit: 2, minMcapUsd: 0);

    expect(count($result))->toBe(2);
});

it('verifyMint returns the body for a known pump.fun mint', function () {
    Http::fake([
        'https://frontend-api-v3.pump.fun/coins/THEMINT*' => Http::response(makeCoin('THEMINT', 12345)),
    ]);

    $service = new class extends PumpfunDiscoveryService
    {
        protected function sleep(int $ms): void {}
    };

    $result = $service->verifyMint('THEMINT');

    expect($result)->not->toBeNull();
    expect($result['mint'])->toBe('THEMINT');
});

it('verifyMint returns null for 404', function () {
    Http::fake([
        'https://frontend-api-v3.pump.fun/coins/UNKNOWN*' => Http::response(['error' => 'not found'], 404),
    ]);

    $service = new class extends PumpfunDiscoveryService
    {
        protected function sleep(int $ms): void {}
    };

    expect($service->verifyMint('UNKNOWN'))->toBeNull();
});

it('retries on 5xx and eventually returns null on persistent failure', function () {
    Http::fake([
        'https://frontend-api-v3.pump.fun/coins/SHAKY*' => Http::sequence()
            ->push(['error' => 'oops'], 500)
            ->push(['error' => 'oops'], 502)
            ->push(['error' => 'oops'], 503),
    ]);

    $service = new class extends PumpfunDiscoveryService
    {
        protected function sleep(int $ms): void {}
    };

    expect($service->verifyMint('SHAKY'))->toBeNull();

    Http::assertSentCount(3);
});

it('does not throw when the network fails', function () {
    Http::fake(function () {
        throw new RuntimeException('dns blew up');
    });

    $service = new class extends PumpfunDiscoveryService
    {
        protected function sleep(int $ms): void {}
    };

    expect($service->listTop(10, 0))->toBe([]);
    expect($service->verifyMint('ANY'))->toBeNull();
});
