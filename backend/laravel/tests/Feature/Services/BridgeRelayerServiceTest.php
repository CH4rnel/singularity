<?php

use App\Services\BridgeRelayerService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config()->set('services.bridge.relayer_address', null);
    config()->set('services.bridge.relayer_private_key', null);
});

test('returns null when no private key configured', function () {
    $svc = new BridgeRelayerService;
    expect($svc->privateKey())->toBeNull();
    expect($svc->evmAddress())->toBeNull();
});

test('derives the canonical EVM address from a known test private key', function () {
    config()->set(
        'services.bridge.relayer_private_key',
        '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318',
    );

    $svc = new BridgeRelayerService;
    expect($svc->evmAddress())->toBe('0x2c7536E3605D9C16a7a3D7b1898e529396a65c23');
});

test('accepts private key without 0x prefix', function () {
    config()->set(
        'services.bridge.relayer_private_key',
        '4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318',
    );

    expect((new BridgeRelayerService)->evmAddress())
        ->toBe('0x2c7536E3605D9C16a7a3D7b1898e529396a65c23');
});

test('rejects non-32-byte hex', function () {
    config()->set('services.bridge.relayer_private_key', '0xdeadbeef');

    expect(fn () => (new BridgeRelayerService)->evmAddress())
        ->toThrow(RuntimeException::class);
});

test('explicit BRIDGE_RELAYER_ADDRESS overrides derivation', function () {
    config()->set(
        'services.bridge.relayer_private_key',
        '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318',
    );
    config()->set('services.bridge.relayer_address', '0xfA41267C5E2390E941A12b0b8e566448539A5179');

    expect((new BridgeRelayerService)->evmAddress())
        ->toBe('0xfA41267C5E2390E941A12b0b8e566448539A5179');
});

test('caches the derived address', function () {
    config()->set(
        'services.bridge.relayer_private_key',
        '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318',
    );

    $svc = new BridgeRelayerService;
    $svc->evmAddress();

    $cacheKey = 'bridge:relayer:address:'.hash(
        'sha256',
        '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318',
    );

    expect(Cache::get($cacheKey))->toBe('0x2c7536E3605D9C16a7a3D7b1898e529396a65c23');
});
