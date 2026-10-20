<?php

use App\Models\BridgeRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * B07: nobody has to be watching for an unfinished transfer to finish, and
 * finishing one never means paying it again.
 */
beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('bridge.chains.cyberia.rpc_url', 'https://cyberia-rpc.test');
    Http::preventStrayRequests();
    Process::fake();
});

function reconcileRequest(array $overrides = []): BridgeRequest
{
    return BridgeRequest::create(array_merge([
        'recipient_proof' => 'signature',
        'recipient_proven_at' => now(),
        'direction' => 'sol_to_evm',
        'token' => 'USDC',
        'source_chain' => 'solana',
        'source_tx_hash' => 'soltx-'.uniqid(),
        'source_nonce' => random_int(1, PHP_INT_MAX),
        'sender_address' => 'SenderSolanaAddrXyz12345678901234567890',
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'amount' => '1',
        'fee_amount' => '0.1',
        'status' => BridgeRequest::PAYING_OUT,
        'destination_tx_hash' => '0x'.str_repeat('ab', 32),
        'payout_broadcast_at' => now()->subMinutes(20),
        'source_verified_at' => now()->subMinutes(20),
    ], $overrides));
}

function cyberiaReceipt(?string $status): void
{
    Http::fake(['cyberia-rpc.test*' => Http::response([
        'result' => $status === null ? null : ['status' => $status],
    ])]);
}

test('a broadcast payout is confirmed from its receipt and never sent again', function () {
    cyberiaReceipt('0x1');
    $request = reconcileRequest();

    $this->artisan('bridge:reconcile')->assertSuccessful();

    expect($request->refresh()->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->destination_tx_hash)->toBe('0x'.str_repeat('ab', 32));
    Process::assertNothingRan();
});

test('a payout the chain cannot see yet keeps waiting with its hash', function () {
    cyberiaReceipt(null);
    $request = reconcileRequest();

    $this->artisan('bridge:reconcile')->assertSuccessful();

    expect($request->refresh()->status)->toBe(BridgeRequest::PAYING_OUT)
        ->and($request->destination_tx_hash)->toBe('0x'.str_repeat('ab', 32));
    Process::assertNothingRan();
});

test('a worker that died mid-payout is handed back and reconciled, not re-paid', function () {
    cyberiaReceipt('0x1');
    $request = reconcileRequest(['status' => BridgeRequest::PROCESSING]);
    BridgeRequest::whereKey($request->id)->update(['updated_at' => now()->subHour()]);

    $this->artisan('bridge:reconcile')->assertSuccessful();

    expect($request->refresh()->status)->toBe(BridgeRequest::COMPLETED);
    Process::assertNothingRan();
});

test('a transfer still running is left alone', function () {
    $request = reconcileRequest(['status' => BridgeRequest::PROCESSING]);

    $this->artisan('bridge:reconcile')->assertSuccessful();

    expect($request->refresh()->status)->toBe(BridgeRequest::PROCESSING);
    Http::assertNothingSent();
});

test('a held transfer is not reconciled into a payout', function () {
    $request = reconcileRequest([
        'status' => BridgeRequest::AWAITING_RECIPIENT,
        'destination_tx_hash' => null,
        'payout_broadcast_at' => null,
        'recipient_proof' => null,
        'recipient_proven_at' => null,
    ]);

    $this->artisan('bridge:reconcile')->assertSuccessful();

    expect($request->refresh()->status)->toBe(BridgeRequest::AWAITING_RECIPIENT);
    Process::assertNothingRan();
});

test('a native release is completed by its receipt, not by having a hash', function (?string $receipt, string $status) {
    cyberiaReceipt($receipt);
    $request = reconcileRequest(['token' => 'CYBER.sol']);

    $this->artisan('bridge:reconcile')->assertSuccessful();

    expect($request->refresh()->status)->toBe($status)
        ->and($request->destination_tx_hash)->toBe('0x'.str_repeat('ab', 32));
    Process::assertNothingRan();
})->with([
    'confirmed' => ['0x1', BridgeRequest::COMPLETED],
    'not visible yet' => [null, BridgeRequest::PAYING_OUT],
    'reverted' => ['0x0', BridgeRequest::FAILED],
]);

test('bridge:relay on a broadcast payout checks the chain instead of refusing', function () {
    cyberiaReceipt('0x1');
    $request = reconcileRequest();

    $this->artisan("bridge:relay {$request->id}")
        ->expectsOutputToContain('checking the recorded payout on chain')
        ->assertSuccessful();

    expect($request->refresh()->status)->toBe(BridgeRequest::COMPLETED);
    Process::assertNothingRan();
});
