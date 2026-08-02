<?php

use App\Models\User;
use App\Services\Monero\MoneroAddressCodec;

// Public Monero project donation address (standard) and CCS subaddress.
const XMR_WALLET_STANDARD = '44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A';
const XMR_WALLET_SUBADDRESS = '888tNkZrPN6JsEgekjMnABU4TBzc2Dt29EPAvkRxbANsAnjyPbb3iQ1YBRk1UXcdRsiKc9dhwMVgN5S9cQUiyoogDavup3H';

it('saves a native Monero address of any mainnet kind', function (string $address, string $kind) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/wallets/monero/attach', ['wallet_address' => $address])
        ->assertOk()
        ->assertJson(['monero_wallet_address' => $address, 'kind' => $kind]);

    expect($user->fresh()->monero_wallet_address)->toBe($address);
})->with([
    'standard' => [XMR_WALLET_STANDARD, 'standard'],
    'subaddress' => [XMR_WALLET_SUBADDRESS, 'subaddress'],
    'integrated' => [
        fn () => MoneroAddressCodec::integratedAddress(XMR_WALLET_STANDARD, hex2bin('0011223344556677')),
        'integrated',
    ],
]);

it('rejects a mistyped address instead of storing it', function () {
    $user = User::factory()->create(['monero_wallet_address' => XMR_WALLET_STANDARD]);
    $typo = substr_replace(XMR_WALLET_STANDARD, XMR_WALLET_STANDARD[40] === 'A' ? 'B' : 'A', 40, 1);

    $response = $this->actingAs($user)
        ->postJson('/wallets/monero/attach', ['wallet_address' => $typo])
        ->assertStatus(422);

    expect($response->json('errors.wallet_address.0'))->toContain('checksum')
        ->and($user->fresh()->monero_wallet_address)->toBe(XMR_WALLET_STANDARD);
});

it('refuses an EVM address, since XMR on Cyberia is a different asset', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/wallets/monero/attach', ['wallet_address' => '0x2170Ed0880ac9A755fd29B2688956BD959F933F8'])
        ->assertStatus(422);

    expect($response->json('errors.wallet_address.0'))->toContain('native Monero address')
        ->and($user->fresh()->monero_wallet_address)->toBeNull();
});

it('removes the saved address on detach', function () {
    $user = User::factory()->create(['monero_wallet_address' => XMR_WALLET_STANDARD]);

    $this->actingAs($user)
        ->deleteJson('/wallets/monero/detach')
        ->assertOk();

    expect($user->fresh()->monero_wallet_address)->toBeNull();
});

it('requires authentication', function () {
    $this->postJson('/wallets/monero/attach', ['wallet_address' => XMR_WALLET_STANDARD])
        ->assertUnauthorized();

    $this->deleteJson('/wallets/monero/detach')->assertUnauthorized();
});

it('leaves the EVM and Solana wallets untouched', function () {
    $user = User::factory()->create([
        'wallet_address' => '0x433de5f1d2138e4700ef89c4ca50af9ec638f8b8',
        'solana_wallet_address' => '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
    ]);

    $this->actingAs($user)
        ->postJson('/wallets/monero/attach', ['wallet_address' => XMR_WALLET_STANDARD])
        ->assertOk();

    $this->actingAs($user)->deleteJson('/wallets/monero/detach')->assertOk();

    expect($user->fresh()->wallet_address)->toBe('0x433de5f1d2138e4700ef89c4ca50af9ec638f8b8')
        ->and($user->fresh()->solana_wallet_address)->toBe('9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM');
});

it('is a payout address, not an identity: two accounts may keep the same one', function () {
    // Nobody proves ownership of a Monero address (no in-browser signing), so
    // it must never behave like the EVM/Solana columns: no uniqueness, no
    // account merge, no login.
    $first = User::factory()->create();
    $second = User::factory()->create();

    foreach ([$first, $second] as $user) {
        $this->actingAs($user)
            ->postJson('/wallets/monero/attach', ['wallet_address' => XMR_WALLET_STANDARD])
            ->assertOk();
    }

    expect($first->fresh()->monero_wallet_address)->toBe(XMR_WALLET_STANDARD)
        ->and($second->fresh()->monero_wallet_address)->toBe(XMR_WALLET_STANDARD)
        ->and($first->fresh()->exists)->toBeTrue();
});
