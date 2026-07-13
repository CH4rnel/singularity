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

test('native SOL is offered on both Solana routes', function () {
    $service = app(BridgeConfigService::class);

    expect($service->tokensForRoute('sol_to_evm'))->toHaveKey('SOL');
    expect($service->tokensForRoute('evm_to_sol'))->toHaveKey('SOL');
});

test('hides Yenten routes until the relayer WIF is configured', function () {
    config()->set('bridge.chains.yenten.relayer_wif', null);

    $routes = app(BridgeConfigService::class)->availableRoutes();

    expect($routes)->not->toHaveKeys(['yenten_to_evm', 'evm_to_yenten']);
});

test('external BTC LTC XMR routes require a configured wallet before submit', function () {
    $service = app(BridgeConfigService::class);

    expect($service->availableRoutes())->not->toHaveKeys([
        'btc_to_evm',
        'evm_to_btc',
        'ltc_to_evm',
        'evm_to_ltc',
        'xmr_to_evm',
        'evm_to_xmr',
    ]);

    config()->set('bridge.chains.bitcoin.deposit_address', '12ZEw5Hcv1hTb6YUQJ69y1V7uhcoDz92PH');
    config()->set('bridge.routes.btc_to_evm.enabled', true);
    config()->set('bridge.routes.evm_to_btc.enabled', true);

    $routes = $service->availableRoutes();

    expect($routes)->toHaveKeys(['btc_to_evm', 'evm_to_btc'])
        ->and($routes['btc_to_evm']['auto_process'])->toBeFalse()
        ->and($service->tokensForRoute('btc_to_evm'))->toHaveKey('BTC')
        ->and($service->tokensForRoute('evm_to_btc'))->toHaveKey('BTC');
});

test('external routes are visible before operator setup but marked unavailable', function () {
    $service = app(BridgeConfigService::class);
    $routes = collect($service->publicRoutes())->keyBy('direction');

    expect($routes)->toHaveKeys([
        'btc_to_evm',
        'evm_to_btc',
        'ltc_to_evm',
        'evm_to_ltc',
        'xmr_to_evm',
        'evm_to_xmr',
    ]);

    expect($routes['btc_to_evm']['operational'])->toBeFalse()
        ->and($routes['btc_to_evm']['tokens'])->toBe(['BTC'])
        ->and($routes['ltc_to_evm']['tokens'])->toBe(['LTC'])
        ->and($routes['xmr_to_evm']['tokens'])->toBe(['XMR']);
});

test('a disabled chain vanishes from public config and all its routes', function () {
    // Fully configured — only the chain-level switch turns it off.
    config()->set('bridge.chains.bitcoin.deposit_address', '12ZEw5Hcv1hTb6YUQJ69y1V7uhcoDz92PH');
    config()->set('bridge.chains.bitcoin.enabled', false);

    $service = app(BridgeConfigService::class);

    expect(collect($service->publicChains())->pluck('key'))->not->toContain('bitcoin');
    expect($service->availableRoutes())->not->toHaveKeys(['btc_to_evm', 'evm_to_btc']);
    expect(collect($service->publicRoutes())->pluck('direction'))
        ->not->toContain('btc_to_evm')
        ->not->toContain('evm_to_btc');
    // Backend processing of in-flight requests still reads the chain config.
    expect($service->chain('bitcoin'))->not->toBeNull();
    expect($service->chainEnabled('bitcoin'))->toBeFalse();
});

test('public chain config exposes Yenten explorer fallback', function () {
    $chains = collect(app(BridgeConfigService::class)->publicChains())->keyBy('key');

    expect($chains['yenten']['explorerTx'])->toBe('https://explorer.yentencoin.info/tx/{hash}')
        ->and($chains['yenten']['explorerTxFallbacks'])->toContain('https://explorer2.yentencoin.info/tx/{hash}');
});
