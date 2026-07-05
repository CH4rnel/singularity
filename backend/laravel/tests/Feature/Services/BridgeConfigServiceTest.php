<?php

use App\Services\BridgeConfigService;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.yenten.deposit_address', 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL');
});

test('exposes both Yenten routes after the light relayer is configured', function () {
    config()->set('bridge.chains.yenten.relayer_wif', 'configured-secret');
    config()->set('bridge.routes.yenten_to_evm.enabled', true);
    config()->set('bridge.routes.evm_to_yenten.enabled', true);

    $routes = app(BridgeConfigService::class)->availableRoutes();

    expect($routes)->toHaveKeys(['yenten_to_evm', 'evm_to_yenten']);
    expect(app(BridgeConfigService::class)->tokensForRoute('yenten_to_evm'))
        ->toHaveKey('YTN');
});

test('a disabled corridor is dropped while the Solana pair stays available', function () {
    config()->set('bridge.routes.bnb_to_evm.enabled', false);

    $routes = app(BridgeConfigService::class)->availableRoutes();

    expect($routes)->toHaveKeys(['sol_to_evm', 'evm_to_sol']);
    expect($routes)->not->toHaveKey('bnb_to_evm');
});

test('hides Yenten routes until the relayer WIF is configured', function () {
    config()->set('bridge.chains.yenten.relayer_wif', null);

    $routes = app(BridgeConfigService::class)->availableRoutes();

    expect($routes)->not->toHaveKeys(['yenten_to_evm', 'evm_to_yenten']);
});
