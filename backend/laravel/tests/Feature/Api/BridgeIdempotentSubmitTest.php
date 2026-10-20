<?php

use App\Jobs\ProcessBridgeRequest;
use App\Models\BridgeEvent;
use App\Models\BridgeRequest;
use App\Models\BridgeReservation;
use App\Services\BridgeAdmissionService;
use App\Services\CyberiaRpcService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Process::fake();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['result' => null])]);
    config()->set('bridge.routes.evm_to_sol.auto_process', false);
    config()->set('bridge.routes.sol_to_evm.auto_process', false);
    $this->mock(CyberiaRpcService::class, fn ($mock) => $mock
        ->shouldReceive('nativeBalanceWei')->andReturn(null));
});

function idempotentBridgePayload(array $overrides = []): array
{
    return array_replace([
        'direction' => 'evm_to_sol',
        'token' => 'USDC',
        'source_tx_hash' => '0x'.str_repeat('ab', 32),
        'source_nonce' => 0,
        'sender_address' => '0x'.str_repeat('5', 40),
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '25.000001',
    ], $overrides);
}

test('a lost submit response can be recovered without another obligation, job or event', function () {
    $payload = idempotentBridgePayload(['session_id' => '0679b41c-7a5d-4ae6-a7d6-01c991dc74d5']);
    $id = $this->postJson('/bridge/submit', $payload)->assertCreated()->json('bridge_request.id');
    $events = BridgeEvent::count();
    $reservations = BridgeReservation::count();

    $this->postJson('/bridge/submit', $payload)
        ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('bridge_request.id', $id);

    expect(BridgeRequest::count())->toBe(1)
        ->and(BridgeReservation::count())->toBe($reservations)
        ->and(BridgeEvent::count())->toBe($events);
    Queue::assertNotPushed(ProcessBridgeRequest::class);
    Process::assertNothingRan();
});

test('replay returns current destination settlement without changing the authoritative ledger', function () {
    $payload = idempotentBridgePayload();
    $this->postJson('/bridge/submit', $payload)->assertCreated();
    $request = BridgeRequest::first();
    $reservation = BridgeReservation::where('bridge_request_id', $request->id)->first();
    $reservation->update(['net_raw' => '23456789', 'status' => BridgeReservation::SETTLED]);
    $request->update(['amount' => '24', 'fee_amount' => '0']);
    $request->markCompleted('destination-hash');
    $before = $reservation->fresh()->getAttributes();

    $this->postJson('/bridge/submit', $payload)
        ->assertOk()->assertJsonPath('bridge_request.status', 'completed')
        ->assertJsonPath('bridge_request.destination_tx_hash', 'destination-hash')
        ->assertJsonPath('bridge_request.amount', '24.000000000000000000');
    expect($reservation->fresh()->getAttributes())->toBe($before);
});

test('recovering an existing request works after its route has been closed', function () {
    $payload = idempotentBridgePayload();
    $id = $this->postJson('/bridge/submit', $payload)->assertCreated()->json('bridge_request.id');
    config()->set('bridge.routes.evm_to_sol.enabled', false);
    Http::fake(fn () => throw new RuntimeException('Replay must not query any chain'));

    $this->postJson('/bridge/submit', $payload)->assertOk()->assertJsonPath('bridge_request.id', $id);
});

test('decimal formatting and EVM letter case do not create another transfer', function () {
    $this->postJson('/bridge/submit', idempotentBridgePayload())->assertCreated();
    $this->postJson('/bridge/submit', idempotentBridgePayload([
        'source_tx_hash' => '0x'.str_repeat('AB', 32),
        'amount' => '025.0000010',
        'reservation' => 'a-different-expired-reference',
    ]))->assertOk();
    expect(BridgeRequest::count())->toBe(1);
});

test('changed transfer details conflict without overwriting or relaying the first request', function ($overrides) {
    $this->postJson('/bridge/submit', idempotentBridgePayload())->assertCreated();
    $before = BridgeRequest::first()->getAttributes();

    $this->postJson('/bridge/submit', idempotentBridgePayload($overrides))
        ->assertStatus(409)->assertJsonPath('reason', 'source_transaction_conflict');

    expect(BridgeRequest::first()->getAttributes())->toBe($before)
        ->and(BridgeRequest::count())->toBe(1);
    Process::assertNothingRan();
})->with([
    'recipient' => [['recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w']],
    'sender' => [['sender_address' => '0x'.str_repeat('6', 40)]],
    'amount' => [['amount' => '25.000002']],
    'token' => [['token' => 'USDT']],
    'nonce' => [['source_nonce' => 1]],
    'direction' => [[
        'direction' => 'sol_to_evm',
        'recipient_address' => '0x'.str_repeat('6', 40),
    ]],
]);

test('conversion remains part of the original intent even after server configuration changes', function () {
    $payload = idempotentBridgePayload([
        'direction' => 'sol_to_evm', 'token' => 'CYBER.sol',
        'source_tx_hash' => 'solana-signature', 'sender_address' => 'SolanaSender',
        'recipient_address' => '0x'.str_repeat('6', 40), 'convert_to_native' => true,
    ]);
    $this->postJson('/bridge/submit', $payload)->assertCreated();
    config()->set('bridge.convert.enabled', false);
    $this->postJson('/bridge/submit', $payload)->assertOk();
    $this->postJson('/bridge/submit', array_replace($payload, ['convert_to_native' => false]))->assertStatus(409);
});

test('original TON message hashes remain recoverable after verification replaces the source event id', function () {
    config()->set('bridge.chains.ton.deposit_address', '0:'.str_repeat('a', 64));
    config()->set('bridge.routes.ton_to_evm.auto_process', false);
    $payload = idempotentBridgePayload([
        'direction' => 'ton_to_evm', 'token' => 'TON',
        'source_tx_hash' => base64_encode(str_repeat("\xab", 32)),
        'sender_address' => '0:'.str_repeat('b', 64),
        'recipient_address' => '0x'.str_repeat('6', 40),
    ]);
    $id = $this->postJson('/bridge/submit', $payload)->assertCreated()->json('bridge_request.id');
    BridgeRequest::find($id)->update(['source_tx_hash' => str_repeat('c', 64)]);

    $this->postJson('/bridge/submit', array_replace($payload, ['source_tx_hash' => str_repeat('ab', 32)]))
        ->assertOk()->assertJsonPath('bridge_request.id', $id);
    expect(BridgeRequest::count())->toBe(1);
});

test('pre-migration requests can be looked up without changing their transfer details', function () {
    $payload = idempotentBridgePayload();
    $this->postJson('/bridge/submit', $payload)->assertCreated();
    BridgeRequest::first()->update(['submitted_intent_hash' => null, 'submitted_source_tx_hash' => null]);
    $this->postJson('/bridge/submit', $payload)->assertOk();
});

test('a failed obligation write cannot leave a request recorded without its ledger', function () {
    $this->withoutExceptionHandling();
    $this->mock(BridgeAdmissionService::class, fn ($mock) => $mock
        ->shouldReceive('commit')->once()->andThrow(new RuntimeException('Ledger unavailable')));

    expect(fn () => $this->postJson('/bridge/submit', idempotentBridgePayload()))
        ->toThrow(RuntimeException::class, 'Ledger unavailable');
    expect(BridgeRequest::count())->toBe(0)->and(BridgeReservation::count())->toBe(0);
    Process::assertNothingRan();
});

test('scientific notation and excess precision are refused before decimal bridge arithmetic', function ($amount) {
    $this->postJson('/bridge/submit', idempotentBridgePayload(['amount' => $amount]))
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect(BridgeRequest::count())->toBe(0);
})->with(['1e3', '0.0000000000000000001']);
