<?php

use App\Services\BridgeConfigService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.avalanche_fuji.enabled', true);
    config()->set('bridge.chains.cyberia_fuji.enabled', true);
    config()->set('bridge.routes.cyberia_fuji_to_evm.enabled', true);
    config()->set('bridge.routes.cyberia_fuji_to_evm.allowed_tokens', ['AVAX.FUJI', 'CYBER']);
    config()->set('bridge.routes.cyberia_fuji_to_evm.native_destination_reserve_floor', ['CYBER' => '1000']);

    $tokens = config('bridge.tokens');
    $tokens['AVAX.FUJI']['chains']['cyberia']['address'] = '0x00000000000000000000000000000000000000a1';
    $tokens['AVAX.FUJI']['chains']['cyberia_fuji']['address'] = '0x00000000000000000000000000000000000000a2';
    config()->set('bridge.tokens', $tokens);
});

test('publishes distinct Fuji networks without exposing an internal RPC', function () {
    config()->set('bridge.chains.cyberia_fuji.rpc_url', 'http://internal-node:9650/private');
    config()->set('bridge.chains.cyberia_fuji.public_rpc_url', 'https://cyberia.church/api/fuji/rpc');

    $chains = collect(app(BridgeConfigService::class)->publicChains())->keyBy('key');

    expect($chains['cyberia']['evmChainId'])->toBe(49406)
        ->and($chains['avalanche_fuji']['evmChainId'])->toBe(43113)
        ->and($chains['avalanche_fuji']['label'])->toContain('testnet')
        ->and($chains['avalanche_fuji']['nativeCurrency']['symbol'])->toBe('AVAX')
        ->and($chains['cyberia_fuji']['evmChainId'])->toBe(49407)
        ->and($chains['cyberia_fuji']['label'])->toContain('testnet')
        ->and($chains['cyberia_fuji']['nativeCurrency']['symbol'])->toBe('CYBER')
        ->and($chains['cyberia_fuji']['rpcUrl'])->toBe('https://cyberia.church/api/fuji/rpc');
});

test('offers Fuji AVAX between all three networks and never treats it as CYBER', function () {
    $service = app(BridgeConfigService::class);
    $directions = [
        'avalanche_fuji_to_evm',
        'evm_to_avalanche_fuji',
        'avalanche_fuji_to_cyberia_fuji',
        'cyberia_fuji_to_avalanche_fuji',
        'evm_to_cyberia_fuji',
        'cyberia_fuji_to_evm',
    ];

    expect($service->availableRoutes())->toHaveKeys($directions);

    foreach ($directions as $direction) {
        expect($service->tokensForRoute($direction))->toHaveKey('AVAX.FUJI');

        if (str_contains($direction, 'avalanche_fuji')) {
            expect(array_keys($service->tokensForRoute($direction)))->toBe(['AVAX.FUJI']);
        }
    }

    expect($service->tokenOnChain('CYBER', 'avalanche_fuji'))->toBeNull()
        ->and($service->tokenOnChain('AVAX', 'avalanche_fuji'))->toBeNull()
        ->and($service->tokenOnChain('AVAX.FUJI', 'avalanche_fuji')['native'])->toBeTrue();
});

test('keeps native CYBER conversion reciprocal and scoped to the old and new networks', function () {
    $service = app(BridgeConfigService::class);

    expect(config('bridge.routes.evm_to_cyberia_fuji.conversion_rates'))->toBe([
        'CYBER' => ['numerator' => '1000', 'denominator' => '1'],
    ])->and(config('bridge.routes.cyberia_fuji_to_evm.conversion_rates'))->toBe([
        'CYBER' => ['numerator' => '1', 'denominator' => '1000'],
    ]);

    foreach (['cyberia', 'cyberia_fuji'] as $chain) {
        expect($service->tokenOnChain('CYBER', $chain)['native'])->toBeTrue();
    }

    expect($service->tokensForRoute('evm_to_cyberia_fuji'))->toHaveKey('CYBER')
        ->and($service->tokensForRoute('cyberia_fuji_to_evm'))->toHaveKey('CYBER');
});

test('hides AVAX corridors until each required wrapper is configured', function () {
    $tokens = config('bridge.tokens');
    $tokens['AVAX.FUJI']['chains']['cyberia']['address'] = '';
    $tokens['AVAX.FUJI']['chains']['cyberia_fuji']['address'] = '';
    config()->set('bridge.tokens', $tokens);

    $service = app(BridgeConfigService::class);

    expect($service->availableRoutes())->not->toHaveKeys([
        'avalanche_fuji_to_evm',
        'evm_to_avalanche_fuji',
        'avalanche_fuji_to_cyberia_fuji',
        'cyberia_fuji_to_avalanche_fuji',
    ])->and(array_keys($service->tokensForRoute('evm_to_cyberia_fuji')))->toBe(['CYBER'])
        ->and(array_keys($service->tokensForRoute('cyberia_fuji_to_evm')))->toBe(['CYBER']);
});

test('a disabled Fuji network accepts no new routes while the existing bridge stays open', function (string $chain) {
    config()->set('bridge.chains.'.$chain.'.enabled', false);

    $service = app(BridgeConfigService::class);
    $routes = $service->availableRoutes();

    expect($routes)->toHaveKeys(['sol_to_evm', 'evm_to_sol'])
        ->and(collect($service->publicChains())->pluck('key')->all())->not->toContain($chain);

    foreach ($routes as $route) {
        expect($route['source_chain'])->not->toBe($chain)
            ->and($route['destination_chain'])->not->toBe($chain);
    }
})->with(['avalanche_fuji', 'cyberia_fuji']);

test('AVAX return stays open while native CYBER migration is disabled', function () {
    config()->set('bridge.routes.cyberia_fuji_to_evm.allowed_tokens', ['AVAX.FUJI']);
    config()->set('bridge.routes.cyberia_fuji_to_evm.native_destination_reserve_floor', ['CYBER' => null]);

    $service = app(BridgeConfigService::class);
    $public = collect($service->publicRoutes())->keyBy('direction');

    expect($service->availableRoutes())->toHaveKey('cyberia_fuji_to_evm')
        ->and(array_keys($service->tokensForRoute('cyberia_fuji_to_evm')))->toBe(['AVAX.FUJI'])
        ->and($public['cyberia_fuji_to_evm']['operational'])->toBeTrue()
        ->and($public['cyberia_fuji_to_evm']['tokens'])->toBe(['AVAX.FUJI']);

    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();
    Process::fake();

    $payload = [
        'direction' => 'cyberia_fuji_to_evm',
        'token' => 'CYBER',
        'sender_address' => '0x'.str_repeat('b', 40),
        'recipient_address' => '0x'.str_repeat('c', 40),
        'amount' => '1000',
    ];

    $this->getJson(route('bridge.capacity', ['direction' => 'cyberia_fuji_to_evm', 'token' => 'CYBER']))
        ->assertSuccessful()->assertJsonPath('state', 'unavailable');
    $this->postJson(route('bridge.reserve'), $payload)->assertUnprocessable();
    $this->postJson(route('bridge.submit'), $payload + [
        'source_tx_hash' => '0x'.str_repeat('ab', 32), 'source_nonce' => 0,
    ])->assertUnprocessable();

    $this->postJson(route('bridge.reserve'), array_replace($payload, ['token' => 'AVAX.FUJI', 'amount' => '1']))
        ->assertCreated();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

test('an empty or malformed route token allowlist fails closed', function (mixed $allowedTokens) {
    config()->set('bridge.routes.cyberia_fuji_to_evm.allowed_tokens', $allowedTokens);

    $service = app(BridgeConfigService::class);

    expect($service->tokensForRoute('cyberia_fuji_to_evm'))->toBe([])
        ->and($service->availableRoutes())->not->toHaveKey('cyberia_fuji_to_evm')
        ->and($service->tokensForRoute('sol_to_evm'))->toHaveKey('SOL');
})->with([
    'empty' => [[]],
    'string' => ['AVAX.FUJI'],
    'null' => [null],
]);
