<?php

use App\Models\BridgeRequest;
use App\Services\CyberiaRpcService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('bridge.fee.flat_usd', '0.10');
    config()->set('bridge.fee.rate_bps', 0);
});

test('submit persists USD fee for USDC bridge', function () {
    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_sol',
        'token' => 'USDC',
        'source_tx_hash' => '0xabc',
        'source_nonce' => 1,
        'sender_address' => '0xsender',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '50',
    ])->assertStatus(201)
        ->assertJsonPath('bridge_request.fee_usd', '0.10000000');

    $request = BridgeRequest::latest('id')->first();
    expect((float) $request->fee_usd)->toEqual(0.1);
    expect((float) $request->fee_amount)->toEqual(0.1);
});

test('gas drop is planned for sol_to_evm into an empty recipient', function () {
    $this->mock(CyberiaRpcService::class, function ($mock) {
        $mock->shouldReceive('nativeBalanceWei')
            ->andReturn('0');
    });

    $this->postJson('/bridge/submit', [
        'direction' => 'sol_to_evm',
        'token' => 'USDC',
        'source_tx_hash' => 'soltx',
        'source_nonce' => 1,
        'sender_address' => 'AbCd',
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'amount' => '5',
    ])->assertStatus(201)
        ->assertJsonPath('bridge_request.gas_drop_planned', true);

    expect(BridgeRequest::latest('id')->first()->gas_drop_planned)->toBeTrue();
});

test('gas drop is skipped when recipient has balance', function () {
    $this->mock(CyberiaRpcService::class, function ($mock) {
        $mock->shouldReceive('nativeBalanceWei')
            ->andReturn('1000000000000000000');
    });

    $this->postJson('/bridge/submit', [
        'direction' => 'sol_to_evm',
        'token' => 'USDC',
        'source_tx_hash' => 'soltx2',
        'source_nonce' => 2,
        'sender_address' => 'AbCd',
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'amount' => '5',
    ])->assertStatus(201)
        ->assertJsonPath('bridge_request.gas_drop_planned', false);
});

test('gas drop is never planned for evm_to_sol direction', function () {
    $this->mock(CyberiaRpcService::class, function ($mock) {
        $mock->shouldNotReceive('nativeBalanceWei');
    });

    $this->postJson('/bridge/submit', [
        'direction' => 'evm_to_sol',
        'token' => 'USDC',
        'source_tx_hash' => '0xevmtx',
        'source_nonce' => 3,
        'sender_address' => '0xsender',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '5',
    ])->assertStatus(201)
        ->assertJsonPath('bridge_request.gas_drop_planned', false);
});

test('gas drop respects enabled=false config', function () {
    config()->set('bridge.gas_drop.enabled', false);

    $this->mock(CyberiaRpcService::class, function ($mock) {
        $mock->shouldNotReceive('nativeBalanceWei');
    });

    $this->postJson('/bridge/submit', [
        'direction' => 'sol_to_evm',
        'token' => 'USDC',
        'source_tx_hash' => 'soltx3',
        'source_nonce' => 4,
        'sender_address' => 'AbCd',
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'amount' => '5',
    ])->assertStatus(201)
        ->assertJsonPath('bridge_request.gas_drop_planned', false);
});
