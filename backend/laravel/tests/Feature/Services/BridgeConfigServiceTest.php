<?php

use App\Services\BridgeConfigService;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.yenten.deposit_address', 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL');
});

test('exposes both Yenten routes after the light relayer is configured', function () {
    config()->set('bridge.chains.yenten.relayer_wif', 'configured-secret');

    $routes = app(BridgeConfigService::class)->availableRoutes();

    expect($routes)->toHaveKeys(['yenten_to_evm', 'evm_to_yenten']);
    expect(app(BridgeConfigService::class)->tokensForRoute('yenten_to_evm'))
        ->toHaveKey('YTN');
});

test('hides Yenten routes until the relayer WIF is configured', function () {
    config()->set('bridge.chains.yenten.relayer_wif', null);

    $routes = app(BridgeConfigService::class)->availableRoutes();

    expect($routes)->not->toHaveKeys(['yenten_to_evm', 'evm_to_yenten']);
});
