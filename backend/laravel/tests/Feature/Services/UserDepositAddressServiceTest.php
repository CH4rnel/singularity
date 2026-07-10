<?php

use App\Models\User;
use App\Models\UserDepositAddress;
use App\Services\UserDepositAddressService;
use App\Services\Yenten\YentenAddressDeriver;

// Throwaway test-only seed — never a real one.
const DEPOSIT_TEST_SEED = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

// Public Monero project donation address (standard, netbyte 0x12) as a fixture.
const XMR_MAIN = '44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A';

// Public Monero CCS donation SUBADDRESS (netbyte 0x2a) — cannot carry a payment id.
const XMR_SUB = '888tNkZrPN6JsEgekjMnABU4TBzc2Dt29EPAvkRxbANsAnjyPbb3iQ1YBRk1UXcdRsiKc9dhwMVgN5S9cQUiyoogDavup3H';

beforeEach(function () {
    config()->set('bridge.chains.bitcoin.hd_seed', DEPOSIT_TEST_SEED);
    config()->set('bridge.chains.litecoin.hd_seed', DEPOSIT_TEST_SEED);
    config()->set('bridge.chains.yenten.hd_seed', DEPOSIT_TEST_SEED);
    config()->set('bridge.chains.monero.hd_seed', DEPOSIT_TEST_SEED);
    config()->set('bridge.chains.monero.deposit_address', XMR_MAIN);
});

test('derives version-correct personal addresses for every chain', function () {
    $service = app(UserDepositAddressService::class);

    // Version bytes surface as the base58 leading character.
    expect($service->derive('bitcoin', 1))->toMatch('/^1[1-9A-HJ-NP-Za-km-z]{25,34}$/')
        ->and($service->derive('litecoin', 1))->toMatch('/^L[1-9A-HJ-NP-Za-km-z]{25,34}$/')
        ->and($service->derive('yenten', 1))->toMatch('/^Y[1-9A-HJ-NP-Za-km-z]{25,34}$/')
        ->and($service->derive('monero', 1))->toMatch('/^4[1-9A-HJ-NP-Za-km-z]{105}$/');
});

test('derivation is deterministic per user and distinct across users and chains', function () {
    $service = app(UserDepositAddressService::class);

    $all = [];

    foreach (['bitcoin', 'litecoin', 'yenten', 'monero'] as $chain) {
        foreach ([1, 2, 3] as $userId) {
            expect($service->derive($chain, $userId))->toBe($service->derive($chain, $userId));
            $all[] = $service->derive($chain, $userId);
        }
    }

    expect(array_unique($all))->toHaveCount(count($all));
});

test('per-user Yenten addresses never collide with per-request one-time addresses', function () {
    // Same seed, same index — the 'ytn-user' vs 'ytn-deposit' namespaces
    // must isolate the profile scheme from the request scheme.
    $requestAddress = (new YentenAddressDeriver(DEPOSIT_TEST_SEED))->depositAddress(5);

    expect(app(UserDepositAddressService::class)->derive('yenten', 5))
        ->not->toBe($requestAddress);
});

test('addressesFor issues and persists one address per chain', function () {
    $user = User::factory()->create();

    $addresses = app(UserDepositAddressService::class)->addressesFor($user);

    expect($addresses)->toHaveKeys(['bitcoin', 'litecoin', 'yenten', 'monero']);
    expect(UserDepositAddress::query()->where('user_id', $user->id)->count())->toBe(4);

    // Second call returns the same set without duplicating rows.
    expect(app(UserDepositAddressService::class)->addressesFor($user))->toBe($addresses);
    expect(UserDepositAddress::query()->where('user_id', $user->id)->count())->toBe(4);
});

test('an issued address is honored over fresh derivation after a seed rotation', function () {
    $user = User::factory()->create();
    $issued = app(UserDepositAddressService::class)->addressesFor($user);

    config()->set('bridge.chains.bitcoin.hd_seed', str_repeat('ef', 32));

    expect(app(UserDepositAddressService::class)->addressesFor($user)['bitcoin'])
        ->toBe($issued['bitcoin']);
});

test('chains without operator setup are skipped', function () {
    config()->set('bridge.chains.bitcoin.hd_seed', null);
    config()->set('bridge.chains.monero.deposit_address', null);

    $addresses = app(UserDepositAddressService::class)->addressesFor(User::factory()->create());

    expect($addresses)->toHaveKeys(['litecoin', 'yenten'])
        ->and($addresses)->not->toHaveKeys(['bitcoin', 'monero']);
});

test('a Monero subaddress cannot serve as the integrated-address base', function () {
    config()->set('bridge.chains.monero.deposit_address', XMR_SUB);

    expect(app(UserDepositAddressService::class)->derive('monero', 1))->toBeNull();
});

test('WIFs exist for bitcoin-family chains only', function () {
    $service = app(UserDepositAddressService::class);

    expect($service->wif('bitcoin', 1))->toMatch('/^[KL][1-9A-HJ-NP-Za-km-z]{50,52}$/')
        ->and($service->wif('monero', 1))->toBeNull();
});
