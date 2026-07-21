<?php

use App\Models\Activity;
use App\Models\ProposalVote;
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

/**
 * Solana wallets are generated fresh per test (no fixed vector) since
 * signing needs the secret key, which a real address never exposes.
 *
 * @return array{address: string, secret: string}
 */
function solanaWallet(): array
{
    $keypair = sodium_crypto_sign_keypair();

    return [
        'address' => base58Encode(sodium_crypto_sign_publickey($keypair)),
        'secret' => sodium_crypto_sign_secretkey($keypair),
    ];
}

function solanaAttachSignature(string $secretKey, string $nonce): string
{
    $message = "Sign this message to link your wallet. Nonce: {$nonce}";

    return base64_encode(sodium_crypto_sign_detached($message, $secretKey));
}

function base58Encode(string $bytes): string
{
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $num = gmp_init(bin2hex($bytes), 16);
    $encoded = '';

    while (gmp_cmp($num, 0) > 0) {
        $remainder = gmp_intval(gmp_mod($num, 58));
        $num = gmp_div_q($num, 58);
        $encoded = $alphabet[$remainder].$encoded;
    }

    for ($i = 0; $i < strlen($bytes) && $bytes[$i] === "\x00"; $i++) {
        $encoded = '1'.$encoded;
    }

    return $encoded;
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

it('merges the existing wallet owner into the authenticated user on attach', function () {
    $absorbed = User::factory()->create(['wallet_address' => strtolower(ATTACH_ADDRESS)]);
    seedAttachNonce();

    $survivor = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($survivor)
        ->postJson('/wallets/evm/attach', [
            'wallet_address' => ATTACH_ADDRESS,
            'signature' => ATTACH_SIGNATURE,
        ])
        ->assertOk()
        ->assertJson(['merged' => true]);

    expect($survivor->fresh()->wallet_address)->toBe(strtolower(ATTACH_ADDRESS));
    expect($absorbed->fresh()->merged_into_id)->toBe($survivor->id);
    expect($absorbed->fresh()->wallet_address)->toBeNull();
});

it('reassigns the absorbed account\'s activity and votes to the survivor, deduping collisions', function () {
    $absorbed = User::factory()->create(['wallet_address' => strtolower(ATTACH_ADDRESS)]);
    seedAttachNonce();

    $survivor = User::factory()->create(['wallet_address' => null]);

    $activity = Activity::factory()->create(['user_id' => $absorbed->id]);

    // Both accounts voted on the same proposal — the survivor's vote wins,
    // the absorbed duplicate must be dropped rather than error out on the
    // proposal_votes UNIQUE(proposal_id, user_id) constraint.
    $survivorVote = ProposalVote::factory()->create(['user_id' => $survivor->id]);
    $absorbedDuplicateVote = ProposalVote::factory()->create([
        'user_id' => $absorbed->id,
        'proposal_id' => $survivorVote->proposal_id,
    ]);
    $absorbedUniqueVote = ProposalVote::factory()->create(['user_id' => $absorbed->id]);

    $this->actingAs($survivor)
        ->postJson('/wallets/evm/attach', [
            'wallet_address' => ATTACH_ADDRESS,
            'signature' => ATTACH_SIGNATURE,
        ])
        ->assertOk();

    expect($activity->fresh()->user_id)->toBe($survivor->id);
    expect($survivorVote->fresh()->user_id)->toBe($survivor->id);
    expect(ProposalVote::find($absorbedDuplicateVote->id))->toBeNull();
    expect($absorbedUniqueVote->fresh()->user_id)->toBe($survivor->id);
});

it('refuses to merge when both accounts hold conflicting identity fields', function () {
    $absorbed = User::factory()->create([
        'wallet_address' => strtolower(ATTACH_ADDRESS),
        'solana_wallet_address' => (solanaWallet())['address'],
    ]);
    seedAttachNonce();

    $survivor = User::factory()->create([
        'wallet_address' => null,
        'solana_wallet_address' => (solanaWallet())['address'],
    ]);

    $this->actingAs($survivor)
        ->postJson('/wallets/evm/attach', [
            'wallet_address' => ATTACH_ADDRESS,
            'signature' => ATTACH_SIGNATURE,
        ])
        ->assertStatus(409);

    // Whole merge rolled back — nothing changed on either side.
    expect($survivor->fresh()->wallet_address)->toBeNull();
    expect($absorbed->fresh()->merged_into_id)->toBeNull();
    expect($absorbed->fresh()->wallet_address)->toBe(strtolower(ATTACH_ADDRESS));
});

it('never merges accounts when the attach signature is invalid, even if the wallet is already taken', function () {
    $absorbed = User::factory()->create(['wallet_address' => strtolower(ATTACH_ADDRESS)]);
    seedAttachNonce('a-different-nonce');

    $survivor = User::factory()->create(['wallet_address' => null]);

    $this->actingAs($survivor)
        ->postJson('/wallets/evm/attach', [
            'wallet_address' => ATTACH_ADDRESS,
            'signature' => ATTACH_SIGNATURE,
        ])
        ->assertStatus(401);

    expect($absorbed->fresh()->merged_into_id)->toBeNull();
    expect($survivor->fresh()->wallet_address)->toBeNull();
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

it('attaches a Solana wallet when the signature is valid', function () {
    $wallet = solanaWallet();
    WalletNonce::create([
        'wallet_address' => $wallet['address'],
        'nonce' => 'sol-nonce',
        'expires_at' => now()->addMinutes(5),
    ]);

    $user = User::factory()->create(['solana_wallet_address' => null]);

    $this->actingAs($user)
        ->postJson('/wallets/solana/attach', [
            'wallet_address' => $wallet['address'],
            'signature' => solanaAttachSignature($wallet['secret'], 'sol-nonce'),
        ])
        ->assertOk()
        ->assertJson(['merged' => false]);

    expect($user->fresh()->solana_wallet_address)->toBe($wallet['address']);
});

it('merges the existing Solana wallet owner into the authenticated user on attach', function () {
    $wallet = solanaWallet();
    $absorbed = User::factory()->create(['solana_wallet_address' => $wallet['address']]);
    WalletNonce::create([
        'wallet_address' => $wallet['address'],
        'nonce' => 'sol-nonce',
        'expires_at' => now()->addMinutes(5),
    ]);

    $survivor = User::factory()->create(['solana_wallet_address' => null]);

    $this->actingAs($survivor)
        ->postJson('/wallets/solana/attach', [
            'wallet_address' => $wallet['address'],
            'signature' => solanaAttachSignature($wallet['secret'], 'sol-nonce'),
        ])
        ->assertOk()
        ->assertJson(['merged' => true]);

    expect($survivor->fresh()->solana_wallet_address)->toBe($wallet['address']);
    expect($absorbed->fresh()->merged_into_id)->toBe($survivor->id);
    expect($absorbed->fresh()->solana_wallet_address)->toBeNull();
});

it('never merges Solana accounts when the attach signature is invalid, even if the wallet is already taken', function () {
    $wallet = solanaWallet();
    $absorbed = User::factory()->create(['solana_wallet_address' => $wallet['address']]);
    WalletNonce::create([
        'wallet_address' => $wallet['address'],
        'nonce' => 'sol-nonce',
        'expires_at' => now()->addMinutes(5),
    ]);

    $survivor = User::factory()->create(['solana_wallet_address' => null]);

    // Signature is over the wrong nonce, so it won't verify against the
    // stored one.
    $badSignature = solanaAttachSignature($wallet['secret'], 'a-different-nonce');

    $this->actingAs($survivor)
        ->postJson('/wallets/solana/attach', [
            'wallet_address' => $wallet['address'],
            'signature' => $badSignature,
        ])
        ->assertStatus(401);

    expect($absorbed->fresh()->merged_into_id)->toBeNull();
    expect($survivor->fresh()->solana_wallet_address)->toBeNull();
});
