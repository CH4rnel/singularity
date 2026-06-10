<?php

use App\Models\BridgeRequest;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('defaults to CYBER.sol when token is omitted', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_sol',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 1,
        'sender_address' => '0xsender',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '1.0',
    ])->assertStatus(201);

    expect(BridgeRequest::latest('id')->first()->token)->toBe('CYBER.sol');
});

test('persists USDC token', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_sol',
        'token' => 'USDC',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 2,
        'sender_address' => '0xsender',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '12.5',
    ])->assertStatus(201)
        ->assertJsonPath('bridge_request.token', 'USDC');

    expect(BridgeRequest::latest('id')->first()->token)->toBe('USDC');
});

test('persists USDT token', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'sol_to_evm',
        'token' => 'USDT',
        'source_tx_hash' => 'solana-tx',
        'source_nonce' => 3,
        'sender_address' => 'AbCd',
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'amount' => '10.0',
    ])->assertStatus(201);

    expect(BridgeRequest::latest('id')->first()->token)->toBe('USDT');
});

test('rejects unknown token', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_sol',
        'token' => 'DOGE',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 4,
        'sender_address' => '0xsender',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '1.0',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['token']);
});

test('direct-transfer tokens persist with expected token symbol', function () {
    Queue::fake();

    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_sol',
        'token' => 'USDC',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 99,
        'sender_address' => '0xsender',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '1.0',
    ])->assertStatus(201);

    $request = BridgeRequest::latest('id')->first();
    expect($request->token)->toBe('USDC');
    // Queue::fake() prevents the relay job from running here. The actual
    // direct-relay execution path is exercised in BridgeDirectRelayTest.
});

test('CYBER.sol token name with a dot resolves to the right config', function () {
    // Regression: config('bridge.tokens.CYBER.sol') treats the dot as a nested
    // key separator. Direct array access avoids it.
    $tokenConfig = config('bridge.tokens', [])['CYBER.sol'] ?? null;

    expect($tokenConfig)->toBeArray();
    expect($tokenConfig['model'])->toBe('native');
    expect($tokenConfig['solana_mint'])
        ->toBe('E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump');
});
