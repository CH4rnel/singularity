<?php

use App\Models\BridgeRequest;
use App\Models\BridgeReservation;
use App\Services\BridgeService;
use App\Services\CyberiaRpcService;
use App\Support\BridgeIntent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * B03: a deposit to the relayer is public, so proving it happened proves
 * nothing about who may name where it is paid. These tests pin the binding.
 */
beforeEach(function () {
    Process::fake();
    Http::preventStrayRequests();
    // Chain reads find nothing, except the Solana hot wallet's balance the
    // reservation gate reads.
    Http::fake(['*' => fn ($request) => Http::response([
        'result' => ($request->data()['method'] ?? '') === 'getBalance' ? ['value' => 10_000_000_000] : null,
    ])]);
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    // Stop at the ledger: what is recorded is the subject here, not the relay.
    config()->set('bridge.routes.evm_to_sol.auto_process', false);
    config()->set('bridge.routes.sol_to_evm.auto_process', false);
    $this->mock(CyberiaRpcService::class, fn ($mock) => $mock
        ->shouldReceive('nativeBalanceWei')->andReturn(null));
});

const B03_VICTIM_RECIPIENT = 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG';
const B03_THIEF_RECIPIENT = 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w';

/** @return array<string, mixed> a deposit by the key labelled "victim" */
function publicDeposit(array $overrides = []): array
{
    $key = bridgeTestEvmKey('victim');

    return array_replace([
        'direction' => 'evm_to_sol',
        'token' => 'USDC',
        'source_tx_hash' => '0x'.str_repeat('ab', 32),
        'source_nonce' => 0,
        'sender_address' => bridgeTestEvmAddress($key),
        'recipient_address' => B03_VICTIM_RECIPIENT,
        'amount' => '25',
    ], $overrides);
}

/** Sign the victim's intent for $payload exactly as the wallet does. */
function victimSigns(array $payload, ?string $reference = null): array
{
    $reference ??= $payload['reservation'] ?? strtolower((string) $payload['source_tx_hash']);
    $payload['intent_signature'] = bridgeTestEvmSign(
        BridgeIntent::message($payload['direction'], $payload['token'], $payload['sender_address'], $payload['recipient_address'], $reference),
        bridgeTestEvmKey('victim'),
    );

    return $payload;
}

test('the statement wording is pinned: the wallet signs exactly these bytes', function () {
    // tests/Frontend/BridgeIntentTest.mjs pins bridgeIntentMessage() to this same text.
    expect(BridgeIntent::message(
        'bnb_to_evm',
        'USDT',
        '0xAbCdEf0123456789aBcDeF0123456789AbCdEf01',
        ' 0x00000000000000000000000000000000000000AA ',
        'res-123',
    ))->toBe(implode("\n", [
        'Cyberia bridge: payout recipient',
        '',
        'I sent this deposit and I choose where it is paid out.',
        'This is not a login and it moves no funds.',
        '',
        'Direction: bnb_to_evm',
        'Token: USDT',
        'Sender: 0xabcdef0123456789abcdef0123456789abcdef01',
        'Recipient: 0x00000000000000000000000000000000000000aa',
        'Reference: res-123',
    ]))->and(BridgeIntent::memo('0x00000000000000000000000000000000000000AA'))
        ->toBe('cyberia-bridge:0x00000000000000000000000000000000000000aa');
});

test('an unsigned claim on a public deposit is recorded, held and never counted or paid', function () {
    $this->postJson('/bridge/submit', publicDeposit(['recipient_address' => B03_THIEF_RECIPIENT]))
        ->assertStatus(202)
        ->assertJsonPath('bridge_request.status', BridgeRequest::AWAITING_RECIPIENT);

    $request = BridgeRequest::sole();

    expect($request->recipient_proven_at)->toBeNull()
        // Not an obligation: nobody may exhaust the pool with unproven hashes.
        ->and(BridgeReservation::count())->toBe(0);

    // Even an operator re-run cannot carry it past the gate.
    $request->update(['status' => BridgeRequest::PENDING]);
    expect(app(BridgeService::class)->processDirectRelay($request))->toBeFalse()
        ->and($request->refresh()->status)->toBe(BridgeRequest::AWAITING_RECIPIENT);
    Process::assertNothingRan();
});

test('the sender signing the recipient makes the claim payable', function () {
    $this->postJson('/bridge/submit', victimSigns(publicDeposit()))
        ->assertCreated()
        ->assertJsonPath('bridge_request.status', BridgeRequest::PENDING);

    $request = BridgeRequest::sole();

    expect($request->recipient_proof)->toBe(BridgeIntent::SIGNATURE)
        ->and($request->recipient_proven_at)->not->toBeNull()
        ->and(BridgeIntent::isProven($request))->toBeTrue()
        ->and(BridgeReservation::where('bridge_request_id', $request->id)->value('status'))
        ->toBe(BridgeReservation::COMMITTED);
});

test('a signature that does not name this sender, recipient and transfer proves nothing', function (Closure $tamper) {
    $payload = $tamper(victimSigns(publicDeposit()));

    // Recorded — a real deposit may stand behind it — but held, uncounted.
    $this->postJson('/bridge/submit', $payload)
        ->assertStatus(202)
        ->assertJsonPath('reason', 'intent_signature_invalid')
        ->assertJsonPath('bridge_request.status', BridgeRequest::AWAITING_RECIPIENT);

    expect(BridgeRequest::sole()->recipient_proven_at)->toBeNull()
        ->and(BridgeReservation::count())->toBe(0);
})->with([
    'recipient swapped after signing' => [fn (array $p) => [...$p, 'recipient_address' => B03_THIEF_RECIPIENT]],
    'signed by somebody else' => [fn (array $p) => [...$p, 'intent_signature' => bridgeTestEvmSign(
        BridgeIntent::message($p['direction'], $p['token'], $p['sender_address'], $p['recipient_address'], strtolower($p['source_tx_hash'])),
        bridgeTestEvmKey('thief'),
    )]],
    'another transfer\'s reference' => [fn (array $p) => victimSigns($p, '0x'.str_repeat('cd', 32))],
    'another corridor' => [fn (array $p) => [...$p, 'intent_signature' => bridgeTestEvmSign(
        BridgeIntent::message('evm_to_bnb', $p['token'], $p['sender_address'], $p['recipient_address'], strtolower($p['source_tx_hash'])),
        bridgeTestEvmKey('victim'),
    )]],
    'a login signature' => [fn (array $p) => [...$p, 'intent_signature' => bridgeTestEvmSign(
        'Sign this message to authenticate with your wallet. Nonce: abc',
        bridgeTestEvmKey('victim'),
    )]],
    'garbage' => [fn (array $p) => [...$p, 'intent_signature' => '0x1234']],
]);

test('the depositor wins the race against whoever registered their deposit first', function () {
    // The thief saw the transfer in the mempool and got there first.
    $this->postJson('/bridge/submit', publicDeposit(['recipient_address' => B03_THIEF_RECIPIENT]))
        ->assertStatus(202);

    $this->postJson('/bridge/submit', victimSigns(publicDeposit()))
        ->assertCreated()
        ->assertJsonPath('bridge_request.status', BridgeRequest::PENDING);

    $request = BridgeRequest::sole();

    expect($request->recipient_address)->toBe(B03_VICTIM_RECIPIENT)
        ->and($request->recipient_proof)->toBe(BridgeIntent::SIGNATURE)
        ->and(BridgeReservation::where('bridge_request_id', $request->id)->count())->toBe(1);

    // And once it is proven, nothing unsigned can move it again.
    $this->postJson('/bridge/submit', publicDeposit(['recipient_address' => B03_THIEF_RECIPIENT]))
        ->assertStatus(409);
    expect($request->refresh()->recipient_address)->toBe(B03_VICTIM_RECIPIENT);
});

test('a proven claim cannot be taken over by another valid signature for a different recipient', function () {
    $this->postJson('/bridge/submit', victimSigns(publicDeposit()))->assertCreated();

    $this->postJson('/bridge/submit', victimSigns(publicDeposit(['recipient_address' => B03_THIEF_RECIPIENT])))
        ->assertStatus(409);

    expect(BridgeRequest::sole()->recipient_address)->toBe(B03_VICTIM_RECIPIENT);
});

test('the same signed intent submitted twice is the same request', function () {
    $payload = victimSigns(publicDeposit());
    $id = $this->postJson('/bridge/submit', $payload)->assertCreated()->json('bridge_request.id');

    $this->postJson('/bridge/submit', $payload)
        ->assertOk()
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('bridge_request.id', $id);

    expect(BridgeRequest::count())->toBe(1);
});

test('a signature over the reservation, made before sending, is accepted once', function () {
    config()->set('bridge.chains.solana.rpc_url', 'https://sol-rpc.test');
    config()->set('bridge.chains.solana.deposit_address', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w');

    $deposit = publicDeposit(['token' => 'SOL', 'amount' => '0.5']);
    $reference = $this->postJson('/bridge/reserve', [
        'direction' => $deposit['direction'],
        'token' => 'SOL',
        'sender_address' => $deposit['sender_address'],
        'recipient_address' => $deposit['recipient_address'],
        'amount' => '0.5',
    ])->assertCreated()->json('reservation.reference');

    $this->postJson('/bridge/submit', victimSigns([...$deposit, 'reservation' => $reference]))
        ->assertCreated();

    expect(BridgeReservation::where('reference', $reference)->value('bridge_request_id'))
        ->toBe(BridgeRequest::sole()->id);

    // The same reservation signature cannot authorize a second deposit.
    $this->postJson('/bridge/submit', victimSigns([
        ...$deposit,
        'source_tx_hash' => '0x'.str_repeat('ef', 32),
        'reservation' => $reference,
    ], $reference))->assertStatus(202)->assertJsonPath('reason', 'intent_signature_invalid');
});

test('a Solana sender signs with ed25519', function () {
    $key = bridgeTestSolanaKey('solana-victim');
    $payload = [
        'direction' => 'sol_to_evm',
        'token' => 'USDC',
        'source_tx_hash' => '5'.str_repeat('h', 86),
        'source_nonce' => 0,
        'sender_address' => $key['address'],
        'recipient_address' => '0x00000000000000000000000000000000000000aa',
        'amount' => '3',
    ];
    $message = BridgeIntent::message('sol_to_evm', 'USDC', $key['address'], $payload['recipient_address'], $payload['source_tx_hash']);

    $this->postJson('/bridge/submit', [...$payload, 'intent_signature' => base64_encode(sodium_crypto_sign_detached($message, $key['secret']))])
        ->assertCreated();

    expect(BridgeRequest::sole()->recipient_proof)->toBe(BridgeIntent::SIGNATURE);
});

test('a redemption through CyberBridge carries its recipient on chain and needs no signature', function () {
    $this->postJson('/bridge/submit', publicDeposit(['token' => 'CYBER.sol', 'source_nonce' => 7]))
        ->assertCreated()
        ->assertJsonPath('bridge_request.status', BridgeRequest::PENDING);

    expect(BridgeRequest::sole()->recipient_proof)->toBe(BridgeIntent::ONCHAIN);
});

test('a held transfer is released only by an operator who writes down why', function () {
    $this->postJson('/bridge/submit', publicDeposit())->assertStatus(202);
    $request = BridgeRequest::sole();

    $this->artisan('bridge:attest-recipient', ['id' => $request->id])->assertFailed();
    expect($request->refresh()->status)->toBe(BridgeRequest::AWAITING_RECIPIENT);

    $this->artisan('bridge:attest-recipient', [
        'id' => $request->id,
        '--recipient' => B03_THIEF_RECIPIENT,
        '--note' => 'support ticket 42: sender signed on a call',
    ])->assertSuccessful();

    $request->refresh();
    expect($request->status)->toBe(BridgeRequest::PENDING)
        ->and($request->recipient_proof)->toBe(BridgeIntent::OPERATOR)
        ->and($request->recipient_address)->toBe(B03_THIEF_RECIPIENT)
        ->and(BridgeIntent::isProven($request))->toBeTrue();
});

test('a row written before the proof existed is held by the relay, not paid on a guess', function () {
    $request = BridgeRequest::create([
        'direction' => 'evm_to_sol',
        'token' => 'USDC',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0x'.str_repeat('77', 32),
        'source_nonce' => 0,
        'sender_address' => '0x'.str_repeat('5', 40),
        'recipient_address' => B03_VICTIM_RECIPIENT,
        'amount' => '25',
        'status' => BridgeRequest::PENDING,
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeFalse()
        ->and($request->refresh()->status)->toBe(BridgeRequest::AWAITING_RECIPIENT);
    Process::assertNothingRan();
});
