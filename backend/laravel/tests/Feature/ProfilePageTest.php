<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to login', function () {
    $this->get('/profile')->assertRedirect('/login');
});

it('never exposes shared bridge wallets — only personal addresses appear', function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.solana.deposit_address', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w');

    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->has('depositChains', count(config('bridge.chains')))
            ->where('depositChains.0.key', 'cyberia')
            ->where('depositChains.0.address', null)
            ->where('depositChains.0.personal', false)
            ->where('depositChains.1.key', 'solana')
            ->where('depositChains.1.address', null)
            ->where('depositChains', fn ($chains) => collect($chains)
                ->every(fn ($chain) => $chain['address'] === null || $chain['personal'] === true)));
});

it('drops disabled chains from the deposit list', function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');

    foreach (['yenten', 'bitcoin', 'litecoin', 'monero'] as $key) {
        config()->set("bridge.chains.{$key}.enabled", false);
    }

    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->has('depositChains', count(config('bridge.chains')) - 4)
            ->where('depositChains', fn ($chains) => collect($chains)
                ->pluck('key')
                ->intersect(['yenten', 'bitcoin', 'litecoin', 'monero'])
                ->isEmpty()));
});

it('exposes no static Yenten address — deposits use one-time per-request addresses', function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.yenten.deposit_address', 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL');
    config()->set('bridge.chains.yenten.hd_seed', null);

    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('depositChains', function ($chains) {
                $yenten = collect($chains)->firstWhere('key', 'yenten');

                return $yenten !== null
                    && $yenten['address'] === null
                    && $yenten['oneTime'] === true;
            }));
});

it('shows personal CEX-style addresses once the per-user seeds are configured', function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.bitcoin.hd_seed', str_repeat('ab', 32));
    config()->set('bridge.chains.litecoin.hd_seed', str_repeat('ab', 32));
    config()->set('bridge.chains.yenten.hd_seed', str_repeat('ab', 32));
    config()->set('bridge.chains.monero.hd_seed', str_repeat('ab', 32));
    config()->set(
        'bridge.chains.monero.deposit_address',
        '44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A',
    );

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $chainsFor = function (User $user) {
        $chains = null;

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$chains) {
                $chains = collect($page->toArray()['props']['depositChains']);

                return $page->component('Profile');
            });

        return $chains;
    };

    $a = $chainsFor($userA);
    $b = $chainsFor($userB);

    foreach (['bitcoin' => '1', 'litecoin' => 'L', 'yenten' => 'Y', 'monero' => '4'] as $key => $prefix) {
        $chainA = $a->firstWhere('key', $key);
        $chainB = $b->firstWhere('key', $key);

        expect($chainA['personal'])->toBeTrue();
        expect($chainA['address'])->toStartWith($prefix);
        // CEX model: every user gets their own address.
        expect($chainA['address'])->not->toBe($chainB['address']);
    }

    // EVM chains have no personal address yet — the shared relayer EOA is
    // hidden rather than shown as a deposit target.
    $bnb = $a->firstWhere('key', 'bnb');
    expect($bnb['personal'])->toBeFalse();
    expect($bnb['address'])->toBeNull();
});
