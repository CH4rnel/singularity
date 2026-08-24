<?php

use App\Jobs\ProcessBridgeRequest;
use App\Models\BridgeRequest;
use App\Models\BridgeReservation;
use App\Services\BridgeAdmissionService;
use App\Services\BridgeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Bridge request #68, as a test suite.
 *
 * The sequence that cost a user 0.492836888 SOL was: verify the deposit, burn
 * the wrapper, then try to pay — and the payout failed on
 * `insufficient lamports 97870923, need 492836888`. The wrapper was gone, the
 * SOL was never sent, and the only way back was a manual mint.
 *
 * Every test here pins one link of the new chain:
 *
 *   capacity (locked) → payout → durable hash → confirmation → burn
 *
 * with the property that no crash between any two of them can produce either a
 * second payout or a burn without one.
 */
beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('bridge.chains.solana.rpc_url', 'https://sol-rpc.test');
    config()->set('bridge.chains.solana.deposit_address', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w');
    config()->set('bridge.chains.cyberia.rpc_url', 'https://cyberia-rpc.test');
});

const SOL_SENDER = '0x89bb2E1CD29B15F0826c4E733a62FfBBB3CF127f';
const SOL_RECIPIENT = 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG';
const SOL_WRAPPER = '0x53450B1d205f1e41d10B653FBBDEa74160dafFf4';

/** A hot wallet whose balance and signature answers a test can change. */
function solWallet(string $lamports, ?array $signature = ['err' => null]): object
{
    return (object) ['lamports' => $lamports, 'signature' => $signature];
}

/**
 * The #68 shape: 0.492836888 SOL leaving Cyberia for Solana, where the payout
 * is native SOL out of the hot wallet and the source side is a relayer-owned
 * wrapper that has to be burned.
 */
function solOutRequest(array $overrides = []): BridgeRequest
{
    return BridgeRequest::create(array_merge([
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xa857756a4012d75b97200f879f02adf9a6a4594cd919e953aa148a3fae8e0d50',
        'source_nonce' => random_int(1, PHP_INT_MAX),
        'sender_address' => SOL_SENDER,
        'recipient_address' => SOL_RECIPIENT,
        'amount' => '0.492836888',
        'fee_amount' => '0',
        'status' => BridgeRequest::PENDING,
    ], $overrides));
}

/**
 * The Cyberia-side deposit receipt the relay verifies, plus a hot wallet whose
 * balance a test can move mid-run.
 *
 * `Http::fake()` APPENDS stubs and the first match wins, so re-faking the same
 * URL later in a test silently keeps the old answer. The wallet is therefore a
 * mutable holder read at request time — which is also closer to the truth: a
 * balance is a thing that changes under you, and that is the whole subject.
 */
function fakeSolOutChain(object $wallet): void
{
    Http::fake([
        '*sol-rpc.test*' => fn ($request) => Http::response(match ($request->data()['method'] ?? null) {
            'getBalance' => ['result' => ['value' => (int) $wallet->lamports]],
            'getSignatureStatuses' => ['result' => ['value' => [$wallet->signature]]],
            default => ['result' => ['value' => []]],
        }),
        '*cyberia-rpc.test*' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [[
                    'address' => SOL_WRAPPER,
                    'topics' => [
                        '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                        '0x'.str_pad(substr(SOL_SENDER, 2), 64, '0', STR_PAD_LEFT),
                        '0x0000000000000000000000000000000000000000000000000000000000abcdef',
                    ],
                    // 0.492836888 SOL in the 9-dec wrapper's raw units.
                    'data' => '0x'.str_pad(dechex(492836888), 64, '0', STR_PAD_LEFT),
                ]],
            ],
        ]),
    ]);
}

test('insufficient destination inventory parks the request without burning or paying', function () {
    // The exact numbers from the incident: 97870923 lamports in the hot wallet
    // against a 492836888 lamport payout.
    fakeSolOutChain(solWallet('97870923'));
    Process::fake();

    $request = solOutRequest();

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeFalse();

    $request->refresh();

    expect($request->status)->toBe(BridgeRequest::AWAITING_LIQUIDITY)
        ->and($request->error_message)->toContain('Awaiting liquidity')
        // The two facts that make this recoverable rather than an incident.
        ->and($request->wrapper_burned)->toBeFalse()
        ->and($request->destination_tx_hash)->toBeNull()
        // And the deposit is on the books: the bridge knows it owes this.
        ->and($request->source_verified_at)->not->toBeNull();

    Process::assertDidntRun(fn ($p) => str_contains(implode(' ', $p->command), 'relay-burn'));
    Process::assertDidntRun(fn ($p) => str_contains(implode(' ', $p->command), 'relay-sol-transfer'));

    expect(BridgeReservation::where('bridge_request_id', $request->id)->first()?->status)
        ->toBe(BridgeReservation::COMMITTED);
});

test('an unreadable destination parks the request rather than guessing', function () {
    Http::fake([
        '*sol-rpc.test*' => Http::response('gateway timeout', 504),
        '*cyberia-rpc.test*' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [[
                    'address' => SOL_WRAPPER,
                    'topics' => [
                        '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                        '0x'.str_pad(substr(SOL_SENDER, 2), 64, '0', STR_PAD_LEFT),
                        '0x0000000000000000000000000000000000000000000000000000000000abcdef',
                    ],
                    'data' => '0x'.str_pad(dechex(492836888), 64, '0', STR_PAD_LEFT),
                ]],
            ],
        ]),
    ]);
    Process::fake();

    $request = solOutRequest();
    app(BridgeService::class)->processDirectRelay($request);

    expect($request->refresh()->status)->toBe(BridgeRequest::AWAITING_LIQUIDITY)
        ->and($request->error_message)->toContain('could not be read')
        ->and($request->wrapper_burned)->toBeFalse();

    Process::assertNothingRan();
});

test('a failed payout never reaches the burn', function () {
    fakeSolOutChain(solWallet('2000000000'));

    Process::fake([
        '*relay-sol-transfer*' => Process::result(errorOutput: 'blockhash not found', exitCode: 1),
        '*relay-burn*' => Process::result(output: json_encode(['txHash' => '0xburn'])),
    ]);

    $request = solOutRequest();
    app(BridgeService::class)->processDirectRelay($request);

    $request->refresh();

    expect($request->status)->toBe(BridgeRequest::FAILED)
        ->and($request->wrapper_burned)->toBeFalse();

    Process::assertDidntRun(fn ($p) => str_contains(implode(' ', $p->command), 'relay-burn'));
});

test('a delivered payout whose burn fails becomes burn_pending, and the retry burns only', function () {
    fakeSolOutChain(solWallet('2000000000'));

    Process::fake([
        '*relay-sol-transfer*' => Process::result(
            output: json_encode(['broadcastTxHash' => 'solSig123'])."\n"
                .json_encode(['txHash' => 'solSig123', 'status' => 'success']),
        ),
        '*relay-burn*' => Process::result(errorOutput: 'nonce too low', exitCode: 1),
    ]);

    $request = solOutRequest();
    app(BridgeService::class)->processDirectRelay($request);

    $request->refresh();

    expect($request->status)->toBe(BridgeRequest::BURN_PENDING)
        ->and($request->destination_tx_hash)->toBe('solSig123')
        ->and($request->payout_confirmed_at)->not->toBeNull()
        ->and($request->wrapper_burned)->toBeFalse();

    // The retry: exactly one burn, and NOT a second payout. Counted from
    // inside the stubs, because Process::fake() keeps what it already
    // recorded and `assertDidntRun` would see the first run.
    $payouts = 0;
    $burns = 0;

    Process::fake([
        '*relay-sol-transfer*' => function () use (&$payouts) {
            $payouts++;

            return Process::result(output: json_encode(['txHash' => 'solSigSECOND']));
        },
        '*relay-burn*' => function () use (&$burns) {
            $burns++;

            return Process::result(output: json_encode(['txHash' => '0xburn']));
        },
    ]);

    app(BridgeService::class)->processDirectRelay($request->refresh());

    $request->refresh();

    expect($request->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->wrapper_burned)->toBeTrue()
        // The signature is still the first one. A second payout would have
        // rewritten it — that is the assertion, not the burn.
        ->and($request->destination_tx_hash)->toBe('solSig123')
        ->and($payouts)->toBe(0)
        ->and($burns)->toBe(1);
});

test('a payout that broadcast and then crashed keeps its hash and is never re-sent', function () {
    fakeSolOutChain(solWallet('2000000000'));

    // The crash window: the script prints its signature and dies before it can
    // confirm. A Solana signature exists nowhere else — losing it means the
    // payout can only be found by a human reading an explorer.
    Process::fake([
        '*relay-sol-transfer*' => Process::result(
            output: json_encode(['broadcastTxHash' => 'solSigBroadcast']),
            errorOutput: 'read ETIMEDOUT',
            exitCode: 1,
        ),
    ]);

    $request = solOutRequest();
    app(BridgeService::class)->processDirectRelay($request);

    $request->refresh();

    expect($request->status)->toBe(BridgeRequest::PAYING_OUT)
        ->and($request->destination_tx_hash)->toBe('solSigBroadcast')
        ->and($request->payout_broadcast_at)->not->toBeNull()
        ->and($request->wrapper_burned)->toBeFalse();

    // The retry reconciles the signature (the fake reports it landed) and
    // finishes the accounting instead of sending a second transfer.
    $payouts = 0;

    Process::fake([
        '*relay-sol-transfer*' => function () use (&$payouts) {
            $payouts++;

            return Process::result(output: json_encode(['txHash' => 'solSigSECOND']));
        },
        '*relay-burn*' => Process::result(output: json_encode(['txHash' => '0xburn'])),
    ]);

    app(BridgeService::class)->processDirectRelay($request->refresh());

    $request->refresh();

    expect($request->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->destination_tx_hash)->toBe('solSigBroadcast')
        ->and($request->wrapper_burned)->toBeTrue()
        ->and($payouts)->toBe(0);
});

test('an unconfirmable broadcast waits instead of paying again', function () {
    // The cluster cannot tell us whether the signature landed. "Do not know"
    // must never become "did not happen".
    fakeSolOutChain(solWallet('2000000000', signature: null));
    Process::fake();

    $request = solOutRequest([
        'status' => BridgeRequest::PAYING_OUT,
        'destination_tx_hash' => 'solSigUnknown',
        'payout_broadcast_at' => now(),
        'source_verified_at' => now(),
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeFalse();

    expect($request->refresh()->status)->toBe(BridgeRequest::PAYING_OUT)
        ->and($request->destination_tx_hash)->toBe('solSigUnknown');

    Process::assertNothingRan();
});

test('two jobs racing the same request produce one payout and one burn', function () {
    fakeSolOutChain(solWallet('2000000000'));

    Process::fake([
        '*relay-sol-transfer*' => Process::result(
            output: json_encode(['txHash' => 'solSigOnce', 'status' => 'success']),
        ),
        '*relay-burn*' => Process::result(output: json_encode(['txHash' => '0xburnOnce'])),
    ]);

    $request = solOutRequest();

    // Two workers, one after the other in a single process — which is what the
    // atomic claim reduces a real race to. The second finds nothing to do.
    ProcessBridgeRequest::dispatchSync($request->id);
    ProcessBridgeRequest::dispatchSync($request->id);

    $request->refresh();

    expect($request->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->destination_tx_hash)->toBe('solSigOnce');

    $payouts = 0;
    $burns = 0;

    Process::assertRan(function ($p) use (&$payouts, &$burns) {
        $command = implode(' ', $p->command);
        $payouts += str_contains($command, 'relay-sol-transfer') ? 1 : 0;
        $burns += str_contains($command, 'relay-burn') ? 1 : 0;

        return true;
    });

    expect($payouts)->toBe(1)->and($burns)->toBe(1);
});

test('a request already held by another worker is left alone', function () {
    fakeSolOutChain(solWallet('2000000000'));
    Process::fake();

    $request = solOutRequest();

    $lock = Cache::lock('bridge:request:'.$request->id, 60);
    expect($lock->get())->toBeTrue();

    try {
        ProcessBridgeRequest::dispatchSync($request->id);
    } finally {
        $lock->release();
    }

    expect($request->refresh()->status)->toBe(BridgeRequest::PENDING);
    Process::assertNothingRan();
});

test('a completed request cannot be relayed again', function () {
    fakeSolOutChain(solWallet('2000000000'));
    Process::fake();

    $request = solOutRequest([
        'status' => BridgeRequest::COMPLETED,
        'destination_tx_hash' => 'solSigDone',
        'wrapper_burned' => true,
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeFalse();
    ProcessBridgeRequest::dispatchSync($request->id);

    expect($request->refresh()->destination_tx_hash)->toBe('solSigDone');
    Process::assertNothingRan();
});

test('a parked request is picked up again once the inventory arrives', function () {
    $wallet = solWallet('97870923');
    fakeSolOutChain($wallet);
    // Named patterns rather than a bare fake: `Process::fake()` registers a
    // '*' handler that keeps winning over anything added later, and the
    // second phase of this test needs to replace these two exactly.
    Process::fake([
        '*relay-sol-transfer*' => Process::result(errorOutput: 'must not run', exitCode: 1),
        '*relay-burn*' => Process::result(errorOutput: 'must not run', exitCode: 1),
    ]);

    $request = solOutRequest();
    app(BridgeService::class)->processDirectRelay($request);

    expect($request->refresh()->status)->toBe(BridgeRequest::AWAITING_LIQUIDITY);

    // The operator tops up the hot wallet. Nothing about the request changed —
    // it was waiting, not broken, and that is the point of the state.
    $wallet->lamports = '2000000000';
    Process::fake([
        '*relay-sol-transfer*' => Process::result(output: json_encode(['txHash' => 'solSigLater'])),
        '*relay-burn*' => Process::result(output: json_encode(['txHash' => '0xburn'])),
    ]);

    ProcessBridgeRequest::dispatchSync($request->id);

    expect($request->refresh()->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->destination_tx_hash)->toBe('solSigLater')
        ->and($request->wrapper_burned)->toBeTrue();
});

test('a payout settles its obligation so the pool is not double-counted', function () {
    fakeSolOutChain(solWallet('2000000000'));

    Process::fake([
        '*relay-sol-transfer*' => Process::result(output: json_encode(['txHash' => 'solSigSettled'])),
        '*relay-burn*' => Process::result(output: json_encode(['txHash' => '0xburn'])),
    ]);

    $request = solOutRequest();
    app(BridgeAdmissionService::class)->commit($request, null);

    expect(BridgeReservation::outstanding()->count())->toBe(1);

    app(BridgeService::class)->processDirectRelay($request);

    // The lamports have really moved, so the live read already reflects them;
    // still counting the claim on top would understate capacity forever.
    expect($request->refresh()->status)->toBe(BridgeRequest::COMPLETED);
    expect(BridgeReservation::where('bridge_request_id', $request->id)->first()->status)
        ->toBe(BridgeReservation::SETTLED);
    expect(BridgeReservation::outstanding()->count())->toBe(0);
});

afterEach(function () {
    Cache::flush();
});
