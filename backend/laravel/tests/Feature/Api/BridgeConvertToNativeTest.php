<?php

use App\Models\BridgeRequest;
use App\Services\CyberiaRpcService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    // The submit endpoint dispatches the relay job synchronously; fake all
    // outbound RPC so deposit verification fails fast without touching the
    // network (the request simply ends up 'failed', which these tests ignore).
    Http::fake(['*' => Http::response(['result' => null])]);

    $this->mock(CyberiaRpcService::class, function ($mock) {
        $mock->shouldReceive('nativeBalanceWei')->andReturn('1000000000000000000');
    });
});

$submit = fn (array $overrides = []) => array_merge([
    'direction' => 'sol_to_evm',
    'token' => 'CYBER.sol',
    'source_tx_hash' => 'soltx-convert',
    'source_nonce' => 1,
    'sender_address' => 'AbCd',
    'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
    'amount' => '5000',
    'convert_to_native' => true,
], $overrides);

test('convert_to_native persists for sol_to_evm CYBER.sol', function () use ($submit) {
    $this->postJson('/bridge/submit', $submit())
        ->assertStatus(201)
        ->assertJsonPath('bridge_request.convert_to_native', true);

    expect(BridgeRequest::latest('id')->first()->convert_to_native)->toBeTrue();
});

test('convert_to_native defaults to false when omitted', function () use ($submit) {
    $this->postJson('/bridge/submit', $submit(['convert_to_native' => null]))
        ->assertStatus(201)
        ->assertJsonPath('bridge_request.convert_to_native', false);

    expect(BridgeRequest::latest('id')->first()->convert_to_native)->toBeFalse();
});

test('convert_to_native is ignored for evm_to_sol', function () use ($submit) {
    $this->postJson('/bridge/submit', $submit([
        'direction' => 'evm_to_sol',
        'sender_address' => '0xsender',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
    ]))->assertStatus(201)
        ->assertJsonPath('bridge_request.convert_to_native', false);
});

test('convert_to_native is ignored for non-CYBER.sol tokens', function () use ($submit) {
    $this->postJson('/bridge/submit', $submit(['token' => 'USDC']))
        ->assertStatus(201)
        ->assertJsonPath('bridge_request.convert_to_native', false);
});

test('convert_to_native respects enabled=false config', function () use ($submit) {
    config()->set('bridge.convert.enabled', false);

    $this->postJson('/bridge/submit', $submit())
        ->assertStatus(201)
        ->assertJsonPath('bridge_request.convert_to_native', false);
});

test('status endpoint exposes conversion fields', function () use ($submit) {
    $this->postJson('/bridge/submit', $submit())->assertStatus(201);

    $request = BridgeRequest::latest('id')->first();
    $request->update(['converted' => true]);

    $this->getJson("/api/bridge/{$request->id}/status")
        ->assertOk()
        ->assertJsonPath('convert_to_native', true)
        ->assertJsonPath('converted', true);
});
