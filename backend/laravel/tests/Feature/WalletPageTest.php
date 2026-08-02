<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

const XMR_PAYOUT_ADDRESS = '44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A';

beforeEach(function () {
    $this->withoutVite();
});

it('requires an account', function () {
    $this->get('/wallet')->assertRedirect('/login');
});

it('hands the wallet page a public RPC endpoint and the saved payout address', function () {
    config(['services.staking.solana.public_rpc_url' => 'https://api.mainnet-beta.solana.com']);

    $user = User::factory()->create(['monero_wallet_address' => XMR_PAYOUT_ADDRESS]);

    $this->actingAs($user)
        ->get('/wallet')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Wallet')
            ->where('solanaRpcUrl', 'https://api.mainnet-beta.solana.com')
            ->where('moneroPayoutAddress', XMR_PAYOUT_ADDRESS)
        );
});

it('never sends key material to the browser', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/wallet')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Wallet')
            ->where('moneroPayoutAddress', null)
            // The wallet is derived in the browser: no seed, phrase or private
            // key may ever appear in the page props.
            ->missing('seed')
            ->missing('mnemonic')
            ->missing('privateKey')
        );
});
