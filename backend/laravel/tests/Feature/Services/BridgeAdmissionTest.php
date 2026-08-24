<?php

use App\Models\BridgeRequest;
use App\Models\BridgeReservation;
use App\Services\BridgeAdmissionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Admission control: capacity is CLAIMED before a signature, under a lock, and
 * a claim keeps counting until the payout has actually left.
 *
 * The failure this prevents is not hypothetical. Bridge request #68 read a
 * capacity, was reviewed, was signed, and by the time the relayer looked again
 * the hot wallet held 97870923 lamports against a 492836888 lamport payout —
 * except that by then it had already burned the user's wrapper. One extra live
 * check in the interface would not have helped: the gap it fell through is
 * between reading a balance and spending it.
 */
beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.solana.rpc_url', 'https://sol-rpc.test');
    config()->set('bridge.chains.solana.deposit_address', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w');
});

/**
 * A Solana hot wallet holding exactly `$sol` of native SOL and nothing else.
 * The default is the 1.0 SOL the concurrency tests are written against.
 */
function fakeSolanaWallet(string $lamports = '1010000000'): void
{
    Http::fake([
        '*sol-rpc.test*' => fn ($request) => Http::response(
            ($request->data()['method'] ?? '') === 'getBalance'
                ? ['result' => ['value' => (int) $lamports]]
                : ['result' => ['value' => []]],
        ),
    ]);
}

function reserveSol(string $amount, string $recipient = 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w'): array
{
    return app(BridgeAdmissionService::class)->reserve(
        'evm_to_sol',
        'SOL',
        $amount,
        '0x5555555555555555555555555555555555555555',
        $recipient,
    );
}

test('a reservation is refused when the destination inventory cannot be read', function () {
    // The RPC is down. Under the old code this read as "uncapped" and the
    // transfer sailed through to a burn.
    Http::fake(['*sol-rpc.test*' => Http::response('bad gateway', 502)]);

    $result = reserveSol('0.1');

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('unavailable')
        ->and($result['capacity']->state)->toBe('unavailable');

    expect(BridgeReservation::count())->toBe(0);
});

test('an amount larger than capacity is refused, and nothing is written', function () {
    fakeSolanaWallet('1010000000'); // 1.0 SOL deliverable after the reserve

    $result = reserveSol('2');

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('insufficient');

    expect(BridgeReservation::count())->toBe(0);
});

test('an amount exactly equal to capacity is allowed', function () {
    fakeSolanaWallet('1010000000'); // 1.0 SOL after the 0.01 fee reserve

    $result = reserveSol('1');

    expect($result['ok'])->toBeTrue()
        ->and($result['reservation']->net_raw)->toBe('1000000000')
        ->and($result['reservation']->decimals)->toBe(9);
});

test('two concurrent reservations for 0.6 of a 1.0 balance cannot both succeed', function () {
    fakeSolanaWallet('1010000000');

    $first = reserveSol('0.6');
    $second = reserveSol('0.6');

    expect($first['ok'])->toBeTrue()
        ->and($second['ok'])->toBeFalse()
        ->and($second['reason'])->toBe('insufficient');

    // The invariant, stated as arithmetic: claims never exceed the balance.
    $pool = $first['reservation']->pool;
    $outstanding = app(BridgeAdmissionService::class)->outstandingRaw($pool);

    expect(bccomp($outstanding, '1000000000', 0))->toBeLessThanOrEqual(0);
    expect(BridgeReservation::outstanding()->count())->toBe(1);
});

test('a reservation is made under the destination pool lock, not merely after a read', function () {
    fakeSolanaWallet('1010000000');

    $admission = app(BridgeAdmissionService::class);
    $pool = 'solana:native';
    $held = $admission->poolLock($pool);

    expect($held->get())->toBeTrue();

    try {
        // Somebody else is inside the read-decide-write. The gate refuses
        // rather than racing them — a lock that can be walked past is not one.
        $result = reserveSol('0.1');

        expect($result['ok'])->toBeFalse()
            ->and($result['reason'])->toBe('busy');
        expect(BridgeReservation::count())->toBe(0);
    } finally {
        $held->release();
    }

    expect(reserveSol('0.1')['ok'])->toBeTrue();
});

test('an expired pre-signature hold gives its capacity back', function () {
    fakeSolanaWallet('1010000000');

    $reservation = reserveSol('0.9')['reservation'];

    // Still inside its window: the capacity is somebody else's.
    expect(reserveSol('0.9')['ok'])->toBeFalse();

    $this->travelTo(now()->addSeconds(config('bridge.inventory.reservation_ttl_seconds') + 1));

    // The window lapsed with no source transfer behind it. Capacity returns
    // immediately, without waiting for the sweeper to run.
    expect(reserveSol('0.9')['ok'])->toBeTrue();
    expect($reservation->refresh()->hasExpired())->toBeTrue();

    $this->travelBack();
});

test('a committed obligation is never released by an expiry', function () {
    fakeSolanaWallet('1010000000');

    $reservation = reserveSol('0.9')['reservation'];

    $request = BridgeRequest::create([
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xsourcetx',
        'source_nonce' => 1,
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '0.9',
        'fee_amount' => '0',
        'status' => BridgeRequest::PENDING,
    ]);

    app(BridgeAdmissionService::class)->commit($request, $reservation->reference);

    expect($reservation->refresh()->status)->toBe(BridgeReservation::COMMITTED);

    $this->travelTo(now()->addSeconds(config('bridge.inventory.reservation_ttl_seconds') + 1));

    // The user's money is on the source chain. An expiry cannot make the
    // bridge stop owing them a payout.
    expect(BridgeReservation::outstanding()->count())->toBe(1);
    expect(reserveSol('0.9')['ok'])->toBeFalse();

    app(BridgeAdmissionService::class)->releaseExpired();
    expect($reservation->refresh()->status)->toBe(BridgeReservation::COMMITTED);

    $this->travelBack();
});

test('a reservation is consumed exactly once', function () {
    fakeSolanaWallet('1010000000');

    $reservation = reserveSol('0.4')['reservation'];
    $admission = app(BridgeAdmissionService::class);

    $makeRequest = fn (string $hash) => BridgeRequest::create([
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'source_chain' => 'cyberia',
        'source_tx_hash' => $hash,
        'source_nonce' => random_int(1, PHP_INT_MAX),
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '0.4',
        'fee_amount' => '0',
        'status' => BridgeRequest::PENDING,
    ]);

    $first = $makeRequest('0xone');
    $second = $makeRequest('0xtwo');

    $admission->commit($first, $reservation->reference);
    $admission->commit($second, $reservation->reference);

    // Handing the same reference in twice does not let two transfers share one
    // claim: the second gets an obligation of its own.
    expect($reservation->refresh()->bridge_request_id)->toBe($first->id);
    expect(BridgeReservation::outstanding()->count())->toBe(2);
    expect(app(BridgeAdmissionService::class)->outstandingRaw($reservation->pool))
        ->toBe('800000000');
});

test('a submitted transfer with no reservation still becomes an obligation', function () {
    // Someone sent tokens straight to the relayer's public address. Submit
    // cannot un-send them, so the only honest thing it can do is count them.
    fakeSolanaWallet('1010000000');

    $request = BridgeRequest::create([
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xdirect',
        'source_nonce' => 5,
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '0.7',
        'fee_amount' => '0',
        'status' => BridgeRequest::PENDING,
    ]);

    app(BridgeAdmissionService::class)->commit($request, null);

    expect(BridgeReservation::where('bridge_request_id', $request->id)->first()?->status)
        ->toBe(BridgeReservation::COMMITTED);

    // And it competes for the same balance as everybody else.
    expect(reserveSol('0.7')['ok'])->toBeFalse();
});

test('a reservation whose recipient was changed after the fact is not honoured', function () {
    fakeSolanaWallet('1010000000');

    $reservation = reserveSol('0.4')['reservation'];

    $request = BridgeRequest::create([
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xswapped',
        'source_nonce' => 9,
        'sender_address' => '0x5555555555555555555555555555555555555555',
        // Not the address the capacity was claimed for.
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '0.4',
        'fee_amount' => '0',
        'status' => BridgeRequest::PENDING,
    ]);

    app(BridgeAdmissionService::class)->commit($request, $reservation->reference);

    // The original hold is untouched and the transfer gets its own obligation,
    // so the pool is never credited a claim it did not make.
    expect($reservation->refresh()->bridge_request_id)->toBeNull()
        ->and($reservation->status)->toBe(BridgeReservation::PENDING_SOURCE);
    expect(BridgeReservation::outstanding()->count())->toBe(2);
});

test('the reserve endpoint is the gate, and it answers with the capacity it refused on', function () {
    Http::fake(['*sol-rpc.test*' => Http::response('down', 503)]);

    $this->postJson('/bridge/reserve', [
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '0.1',
    ])
        ->assertStatus(422)
        ->assertJson(['reason' => 'unavailable', 'capacity' => ['state' => 'unavailable']]);

    expect(BridgeReservation::count())->toBe(0);
});

test('the reserve endpoint hands back a reference that submit consumes', function () {
    fakeSolanaWallet('1010000000');

    $response = $this->postJson('/bridge/reserve', [
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        'amount' => '0.5',
    ])->assertCreated();

    $reference = $response->json('reservation.reference');

    // Unpredictable: a browser is given this and nothing else, so a hold
    // cannot be guessed and spent by somebody who never made it.
    expect(strlen($reference))->toBeGreaterThan(40);

    expect(BridgeReservation::where('reference', $reference)->first()->status)
        ->toBe(BridgeReservation::PENDING_SOURCE);
});

afterEach(function () {
    Cache::flush();
});
