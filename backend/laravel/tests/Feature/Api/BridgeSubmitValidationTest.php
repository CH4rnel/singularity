<?php

use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('rejects EVM address when bridging to Solana', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_sol',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 1,
        'sender_address' => '0xsender',
        'recipient_address' => '0x1234567890123456789012345678901234567890',
        'amount' => '1.0',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['recipient_address']);
});

test('rejects Solana address when bridging to EVM', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'sol_to_evm',
        'source_tx_hash' => 'solana-tx',
        'source_nonce' => 1,
        'sender_address' => 'AbCd',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '1.0',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['recipient_address']);
});

test('rejects garbage destination', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_sol',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 1,
        'sender_address' => '0xsender',
        'recipient_address' => 'not-an-address',
        'amount' => '1.0',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['recipient_address']);
});

test('accepts valid Solana destination for evm_to_sol', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_sol',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 1,
        'sender_address' => '0xsender',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '1.0',
    ])->assertStatus(201);
});

test('accepts valid EVM destination for sol_to_evm', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'sol_to_evm',
        'source_tx_hash' => 'solana-tx',
        'source_nonce' => 1,
        'sender_address' => 'AbCd',
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'amount' => '1.0',
    ])->assertStatus(201);
});

test('accepts a valid Yenten destination for evm_to_yenten', function () {
    config()->set('bridge.chains.yenten.deposit_address', 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL');
    config()->set('bridge.chains.yenten.relayer_wif', 'configured-in-production');
    config()->set('bridge.routes.evm_to_yenten.enabled', true);
    config()->set('bridge.routes.evm_to_yenten.coming_soon', false);

    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_yenten',
        'token' => 'YTN',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 1,
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL',
        'amount' => '1.0',
    ])->assertStatus(201);
});

test('rejects a Yenten destination with an invalid checksum', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_yenten',
        'token' => 'YTN',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 1,
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDM',
        'amount' => '1.0',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['recipient_address']);
});

test('rejects evm_to_yenten while the Yenten relayer key is not configured', function () {
    config()->set('bridge.chains.yenten.deposit_address', 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL');
    config()->set('bridge.chains.yenten.relayer_wif', null);

    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_yenten',
        'token' => 'YTN',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 1,
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL',
        'amount' => '1.0',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Yenten bridge relayer is not configured.');
});
