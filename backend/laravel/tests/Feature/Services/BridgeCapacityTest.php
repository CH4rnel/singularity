<?php

use App\Services\BridgeInventoryService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.base.rpc_url', 'https://base-rpc.test');
    config()->set('bridge.chains.bnb.rpc_url', 'https://bsc-rpc.test');
    config()->set('bridge.fee.native_transfer_gas_limit', 21000);
    config()->set('bridge.fee.native_gas_price_floor_gwei', '3');
    config()->set('bridge.fee.native_gas_multiplier_bps', 20000);
});

test('evm_to_base ETH capacity is the relayer native balance minus gas reserve', function () {
    Http::fake(function ($request) {
        return match ($request->data()['method'] ?? null) {
            'eth_getBalance' => Http::response(['result' => '0x1bc16d674ec80000']), // 2 ETH
            'eth_gasPrice' => Http::response(['result' => '0x0']), // floor (3 gwei) wins
            default => Http::response(['result' => '0x0']),
        };
    });

    $available = app(BridgeInventoryService::class)->destinationCapacity('evm_to_base', 'ETH');

    // 2 ETH − (3 gwei × 21000 × 2× multiplier) = 2 − 0.000126.
    expect($available)->toBe('1.999874000000000000');
});

test('evm_to_bnb USDT capacity is the relayer ERC20 balance (18-dec on BSC)', function () {
    Http::fake([
        '*bsc-rpc.test*' => Http::response(['result' => '0x8ac7230489e80000']), // 10 USDT
    ]);

    $available = app(BridgeInventoryService::class)->destinationCapacity('evm_to_bnb', 'USDT');

    expect($available)->toBe('10.000000000000000000');
});

test('minting into the home chain is uncapped (null)', function () {
    $available = app(BridgeInventoryService::class)->destinationCapacity('sol_to_evm', 'USDC');

    expect($available)->toBeNull();
});

test('capacity endpoint returns live inventory for an available route', function () {
    config()->set('bridge.routes.evm_to_base.enabled', true);

    Http::fake([
        '*base-rpc.test*' => Http::response(['result' => '0x4c4b40']), // 5 USDC (6-dec)
    ]);

    $this->getJson('/bridge/capacity?direction=evm_to_base&token=USDC')
        ->assertOk()
        ->assertJson(['available' => '5.000000']);
});

test('capacity endpoint returns null for a disabled/unavailable route', function () {
    // evm_to_base is disabled by default → not offered → null (uncapped/unknown).
    $this->getJson('/bridge/capacity?direction=evm_to_base&token=ETH')
        ->assertOk()
        ->assertJson(['available' => null]);
});
