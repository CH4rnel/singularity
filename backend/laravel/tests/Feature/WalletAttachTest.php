<?php

use App\Models\User;
use App\Models\WalletNonce;

// Valid EVM vector: a throwaway wallet signed
// "Sign this message to link your wallet. Nonce: test-nonce-123".
// Only the public address + signature are pinned here.
const ATTACH_ADDRESS = '0x433De5f1d2138e4700eF89C4cA50AF9eC638f8b8';
const ATTACH_NONCE = 'test-nonce-123';
const ATTACH_SIGNATURE = '0x61d6267bc7c09ef922cafdc522d9a8b596dfbf85719c65d4d47778b0b3bc8f4a451520a3fc0471d1d6d48a8827fc938a43598614b7cd4ace5194fe590b7d46751c';

function seedAttachNonce(string $nonce = ATTACH_NONCE): void
{
    WalletNonce::create([
        'wallet_address' => strtolower(ATTACH_ADDRESS),
        'nonce' => $nonce,
        'expires_at' => now()->addMinutes(5),
    ]);
}

it('attaches an EVM wallet when the signature is valid', function () {
    seedAttachNonce();
    $user = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($user)
        ->postJson('/wallets/evm/attach', [
            'wallet_address' => ATTACH_ADDRESS,
            'signature' => ATTACH_SIGNATURE,
        ])
        ->assertOk();

    expect($user->fresh()->wallet_address)->toBe(strtolower(ATTACH_ADDRESS));
    expect(WalletNonce::count())->toBe(0);
});

it('rejects an EVM attach without a nonce for the address', function () {
    $user = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($user)
        ->postJson('/wallets/evm/attach', [
            'wallet_address' => ATTACH_ADDRESS,
            'signature' => ATTACH_SIGNATURE,
        ])
        ->assertStatus(422);

    expect($user->fresh()->wallet_address)->toBeNull();
});

it('rejects an EVM attach whose signature recovers to a different address', function () {
    // Nonce exists, but the signature was produced over a different nonce, so
    // it recovers to a mismatching signer — attach must be refused (401).
    seedAttachNonce('a-different-nonce');
    $user = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($user)
        ->postJson('/wallets/evm/attach', [
            'wallet_address' => ATTACH_ADDRESS,
            'signature' => ATTACH_SIGNATURE,
        ])
        ->assertStatus(401);

    expect($user->fresh()->wallet_address)->toBeNull();
    expect(WalletNonce::count())->toBe(0); // spent, forcing a fresh signature
});

it('refuses to attach a wallet already linked to another account', function () {
    User::factory()->create(['wallet_address' => strtolower(ATTACH_ADDRESS)]);
    seedAttachNonce();

    $user = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($user)
        ->postJson('/wallets/evm/attach', [
            'wallet_address' => ATTACH_ADDRESS,
            'signature' => ATTACH_SIGNATURE,
        ])
        ->assertStatus(409);
});

it('attaches a wallet whose signature has v=27 (recovery id 0)', function () {
    // The other parity: recovery must use v from the signature, not guess 0.
    $address = '0xCEaB16332b6200dE4Bf998b277b58239C1f3B019';
    $signature = '0x37ff1b0606f31b938e66599803e30249a59b49c8bf6bc36c75173a84c2fff782258ad3148ddf56b0760f95757c3ecceea1bf6c943b0bdbd60ecf593a4e34bda71b';

    WalletNonce::create([
        'wallet_address' => strtolower($address),
        'nonce' => 'v27-nonce',
        'expires_at' => now()->addMinutes(5),
    ]);

    $user = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($user)
        ->postJson('/wallets/evm/attach', [
            'wallet_address' => $address,
            'signature' => $signature,
        ])
        ->assertOk();

    expect($user->fresh()->wallet_address)->toBe(strtolower($address));
});

it('requires authentication to attach', function () {
    $this->postJson('/wallets/evm/attach', [
        'wallet_address' => ATTACH_ADDRESS,
        'signature' => ATTACH_SIGNATURE,
    ])->assertUnauthorized();
});
