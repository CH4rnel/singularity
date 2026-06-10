<?php

use App\Services\BridgeFeeService;
use App\Services\CyberPriceService;

beforeEach(function () {
    config()->set('bridge.fee.flat_usd', '0.10');
    config()->set('bridge.fee.rate_bps', 0);
});

test('USDC fee is denominated in USD (price = 1)', function () {
    $service = new BridgeFeeService(new CyberPriceService);
    $result = $service->feeForBridge('USDC', '100');

    expect($result['fee_usd'])->toBe('0.10');
    expect($result['fee_amount'])->toContain('0.1');
    expect($result['token_price_usd'])->toBe('1');
});

test('USDT fee is denominated in USD', function () {
    $service = new BridgeFeeService(new CyberPriceService);
    $result = $service->feeForBridge('USDT', '500');

    expect($result['fee_usd'])->toBe('0.10');
    expect($result['fee_amount'])->toContain('0.1');
});

test('flat USD fee combined with rate takes the maximum', function () {
    config()->set('bridge.fee.flat_usd', '0.10');
    config()->set('bridge.fee.rate_bps', 100); // 1%

    $service = new BridgeFeeService(new CyberPriceService);

    // For amount 5 USDC, 1% = $0.05, but flat is $0.10 — flat wins
    $small = $service->feeForBridge('USDC', '5');
    expect($small['fee_usd'])->toBe('0.10');

    // For amount 1000 USDC, 1% = $10 — rate wins
    $large = $service->feeForBridge('USDC', '1000');
    expect((float) $large['fee_usd'])->toEqual(10.0);
});

test('CYBER.sol bridges are fee-free regardless of amount', function () {
    $service = new BridgeFeeService(new CyberPriceService);

    $small = $service->feeForBridge('CYBER.sol', '1');
    expect($small['fee_usd'])->toBe('0');
    expect($small['fee_amount'])->toBe('0');

    $large = $service->feeForBridge('CYBER.sol', '1000000');
    expect($large['fee_usd'])->toBe('0');
    expect($large['fee_amount'])->toBe('0');
});

test('isFeeBearing reports correctly per token', function () {
    $service = new BridgeFeeService(new CyberPriceService);

    expect($service->isFeeBearing('USDC'))->toBeTrue();
    expect($service->isFeeBearing('USDT'))->toBeTrue();
    expect($service->isFeeBearing('CYBER.sol'))->toBeFalse();
});
