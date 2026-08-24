<?php

use App\Jobs\ProcessBridgeRequest;
use App\Models\BridgeRequest;
use App\Models\BridgeReservation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * The two doors into the relayer, seen from the HTTP layer.
 *
 * `/bridge/reserve` is the gate: it can and does refuse, because nothing has
 * been signed yet. `/bridge/submit` is not a gate and must never pretend to be
 * one — it is reached only after the user's transfer is on chain, so refusing
 * there would strand money with no record of it. What submit does instead is
 * make sure the obligation is on the books before the relayer decides whether
 * it can be met.
 */
beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.solana.rpc_url', 'https://sol-rpc.test');
    config()->set('bridge.chains.solana.deposit_address', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w');
    // These tests are about what submit RECORDS, not about the relay it hands
    // off to. Leave the corridor on manual relay so the request stops at the
    // ledger; the relay's own behaviour is pinned in BridgePayoutOrderingTest.
    config()->set('bridge.routes.evm_to_sol.auto_process', false);
});

function fakeSolanaBalance(string $lamports): void
{
    Http::fake([
        '*sol-rpc.test*' => fn ($request) => Http::response(
            ($request->data()['method'] ?? '') === 'getBalance'
                ? ['result' => ['value' => (int) $lamports]]
                : ['result' => ['value' => []]],
        ),
    ]);
}

/** @return array<string, mixed> */
function solSubmitPayload(array $overrides = []): array
{
    return array_merge([
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'source_tx_hash' => '0x'.str_repeat('ab', 32),
        'source_nonce' => 1,
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '0.5',
    ], $overrides);
}

test('an over-capacity transfer is refused at the gate: no request, no job, no payout', function () {
    Queue::fake();
    Process::fake();
    fakeSolanaBalance('110000000'); // 0.1 SOL deliverable

    $this->postJson('/bridge/reserve', [
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '5',
    ])->assertStatus(422)->assertJson(['reason' => 'insufficient']);

    // Nothing was created and nothing was set in motion — the refusal happened
    // before the user was ever asked to sign.
    expect(BridgeReservation::count())->toBe(0)
        ->and(BridgeRequest::count())->toBe(0);

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

test('submit consumes the reservation it was given', function () {
    Process::fake();
    fakeSolanaBalance('1010000000');

    $reference = $this->postJson('/bridge/reserve', [
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '0.5',
    ])->assertCreated()->json('reservation.reference');

    $this->postJson('/bridge/submit', solSubmitPayload(['reservation' => $reference]))
        ->assertCreated();

    $reservation = BridgeReservation::where('reference', $reference)->first();
    $request = BridgeRequest::first();

    expect($reservation->status)->toBe(BridgeReservation::COMMITTED)
        ->and($reservation->bridge_request_id)->toBe($request->id);

    // One claim on the pool, not two: the hold became the obligation.
    expect(BridgeReservation::outstanding()->count())->toBe(1);
});

test('submit without a reservation still records the obligation', function () {
    // Tokens sent straight to the relayer's public address. Nobody can stop
    // that transfer, and refusing the submit would only lose the record of it.
    Process::fake();
    fakeSolanaBalance('1010000000');

    $this->postJson('/bridge/submit', solSubmitPayload())->assertCreated();

    $request = BridgeRequest::first();

    expect(BridgeReservation::where('bridge_request_id', $request->id)->first()?->status)
        ->toBe(BridgeReservation::COMMITTED);
});

test('a used reservation cannot be handed in a second time', function () {
    Process::fake();
    fakeSolanaBalance('1010000000');

    $reference = $this->postJson('/bridge/reserve', [
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '0.5',
    ])->assertCreated()->json('reservation.reference');

    $this->postJson('/bridge/submit', solSubmitPayload(['reservation' => $reference]))->assertCreated();
    $this->postJson('/bridge/submit', solSubmitPayload([
        'reservation' => $reference,
        'source_tx_hash' => '0x'.str_repeat('cd', 32),
        'source_nonce' => 2,
    ]))->assertCreated();

    // Two transfers, two obligations. Reusing the reference did not let the
    // second one ride on the first one's claim.
    expect(BridgeRequest::count())->toBe(2)
        ->and(BridgeReservation::outstanding()->count())->toBe(2);
});

test('a reserved transfer that is never signed stops holding capacity', function () {
    fakeSolanaBalance('1010000000');

    $this->postJson('/bridge/reserve', [
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '0.9',
    ])->assertCreated();

    // While the hold stands, the same capacity is not offered again.
    $this->postJson('/bridge/reserve', [
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'sender_address' => '0x6666666666666666666666666666666666666666',
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '0.9',
    ])->assertStatus(422);

    $this->travelTo(now()->addSeconds(config('bridge.inventory.reservation_ttl_seconds') + 1));

    $this->artisan('bridge:release-reservations')->assertExitCode(0);

    expect(BridgeReservation::outstanding()->count())->toBe(0);

    $this->postJson('/bridge/reserve', [
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'sender_address' => '0x6666666666666666666666666666666666666666',
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '0.9',
    ])->assertCreated();

    $this->travelBack();
});

test('a refused reserve never reaches the relayer at all', function () {
    Queue::fake();
    Process::fake();
    fakeSolanaBalance('110000000');

    $this->postJson('/bridge/reserve', [
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '5',
    ])->assertStatus(422);

    // The gate is before the wallet, so a refusal costs a user a message and
    // not a transfer. Nothing downstream of it exists to go wrong.
    Queue::assertNotPushed(ProcessBridgeRequest::class);
    Process::assertNothingRan();
    expect(BridgeRequest::count())->toBe(0);
});

afterEach(function () {
    Cache::flush();
});
