<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

const XMR_PAYOUT_ADDRESS = '44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A';

beforeEach(function () {
    $this->withoutVite();
});

/**
 * The wallet is the home screen of the desktop and mobile shells, so it has to
 * open for someone who has never had a Cyberia account: the keys are generated
 * and stored in the browser, and gating them behind a server that never sees
 * them would be theatre. Signing in adds exactly one thing — the XMR payout
 * binding.
 */
it('opens without an account, because the keys were never ours to gate', function () {
    $this->get('/wallet')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Wallet')
            ->where('moneroPayoutAddress', null)
        );
});

/**
 * The endpoint handed over is this app's own relay, not Solana's public
 * cluster: the cluster answers `403 Access forbidden` to any request carrying
 * a browser `Origin`, so the address that used to be here read the chain from
 * curl and from nowhere else.
 */
it('hands the wallet page the Solana relay and the saved payout address', function () {
    $user = User::factory()->create(['monero_wallet_address' => XMR_PAYOUT_ADDRESS]);

    $this->actingAs($user)
        ->get('/wallet')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Wallet')
            ->where('solanaRpcUrl', url('/api/solana/rpc'))
            ->where('moneroPayoutAddress', XMR_PAYOUT_ADDRESS)
        );
});

it('falls back to the configured endpoint when the relay is switched off', function () {
    config([
        'solana.rpc.enabled' => false,
        'services.staking.public_rpc_url' => 'https://api.mainnet-beta.solana.com',
    ]);

    $this->get('/wallet')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('solanaRpcUrl', 'https://api.mainnet-beta.solana.com')
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
