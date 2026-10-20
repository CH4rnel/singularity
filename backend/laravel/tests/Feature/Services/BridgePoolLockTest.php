<?php

use App\Models\BridgeRequest;
use App\Models\BridgeReservation;
use App\Services\BridgeAdmissionService;
use App\Services\BridgeInventoryService;
use App\Services\BridgeService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\BridgeNativeSourceProof as Proof;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('services.bridge.evm_bridge_address', Proof::BRIDGE);
    config()->set('bridge.chains.cyberia.rpc_url', 'https://pool-source.test');
    config()->set('bridge.chains.solana.rpc_url', 'https://pool-destination.test');
    config()->set('bridge.chains.solana.deposit_address', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w');
    config()->set('bridge.inventory.pool_lock_wait_seconds', 0);
    config()->set('bridge.inventory.solana_fee_reserve_sol', '0.01');

    Http::preventStrayRequests();
});

function poolLockBridgeRequest(string $token): BridgeRequest
{
    $request = BridgeRequest::create([
        'direction' => 'evm_to_sol',
        'token' => $token,
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0x'.str_repeat('ab', 32),
        'source_nonce' => 1,
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'HAUCigT3SuHwXDD7c2H6aaT4yDntXCpue2SkrzpDt2uG',
        'amount' => '1',
        'fee_amount' => '0',
        'status' => BridgeRequest::PENDING,
    ]);

    app(BridgeAdmissionService::class)->commit($request, null);

    return $request;
}

function fakePoolLockBridgeChains(string $token, ?Closure $onCapacityRead = null, int $lamports = 2000000000, ?BridgeRequest $bridgeRequest = null): void
{
    $sourceToken = config('bridge.tokens')[$token]['chains']['cyberia'];

    Http::fake([
        'https://pool-source.test' => $token === 'CYBER.sol'
            ? fn ($rpcRequest) => Http::response(['result' => Proof::evm($bridgeRequest)[
                $rpcRequest->data()['method'] === 'eth_getTransactionReceipt' ? 'receipt' : 'transaction'
            ]])
            : Http::response([
                'result' => [
                    'status' => '0x1',
                    'logs' => [[
                        'address' => $sourceToken['address'],
                        'topics' => [
                            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                            '0x'.str_pad(str_repeat('55', 20), 64, '0', STR_PAD_LEFT),
                            '0x'.str_pad('abcdef', 64, '0', STR_PAD_LEFT),
                        ],
                        'data' => '0x'.str_pad(dechex(10 ** $sourceToken['decimals']), 64, '0', STR_PAD_LEFT),
                    ]],
                ],
            ]),
        'https://pool-destination.test' => function ($request) use ($onCapacityRead, $lamports) {
            $onCapacityRead?->__invoke();

            return Http::response(match ($request->data()['method'] ?? null) {
                'getBalance' => ['result' => ['value' => $lamports]],
                'getTokenAccountsByOwner' => ['result' => ['value' => [[
                    'account' => ['data' => ['parsed' => ['info' => ['tokenAmount' => [
                        'amount' => '7000000',
                        'decimals' => 6,
                    ]]]]],
                ]]]],
                default => ['result' => null],
            });
        },
    ]);
}

function relayPoolLockBridgeRequest(BridgeRequest $request): bool
{
    $service = app(BridgeService::class);

    return $request->token === 'CYBER.sol'
        ? $service->processEvmToSol($request)
        : $service->processDirectRelay($request);
}

function competingBridgePoolLock(BridgeRequest $request): Lock
{
    $pool = app(BridgeInventoryService::class)->poolKey($request->direction, $request->token);

    return app(BridgeAdmissionService::class)->poolLock($pool);
}

test('the destination pool stays locked during inventory reads and payout and is released on success', function (string $token) {
    $request = poolLockBridgeRequest($token);
    $capacityBlocked = [];
    $payoutBlocked = null;

    fakePoolLockBridgeChains($token, function () use ($request, &$capacityBlocked) {
        $competitor = competingBridgePoolLock($request);
        $acquired = $competitor->get();
        $capacityBlocked[] = ! $acquired;

        if ($acquired) {
            $competitor->release();
        }
    }, bridgeRequest: $request);

    $payout = function () use ($request, &$payoutBlocked) {
        $competitor = competingBridgePoolLock($request);
        $acquired = $competitor->get();
        $payoutBlocked = ! $acquired;

        if ($acquired) {
            $competitor->release();
        }

        return Process::result(output: json_encode(['txHash' => 'sol-payout']));
    };

    Process::fake([
        '*relay-sol-transfer*' => $payout,
        '*relay-release-native*' => $payout,
        '*relay-burn*' => Process::result(output: json_encode(['txHash' => '0xburn'])),
    ]);

    expect(relayPoolLockBridgeRequest($request))->toBeTrue()
        ->and($capacityBlocked)->not->toBeEmpty()->not->toContain(false)
        ->and($payoutBlocked)->toBeTrue()
        ->and($request->refresh()->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->destination_tx_hash)->toBe('sol-payout');

    $next = competingBridgePoolLock($request);
    expect($next->get())->toBeTrue();
    $next->release();
})->with(['direct wrapper' => ['SOL'], 'native bridge' => ['CYBER.sol']]);

test('a payout exception releases its pool lock without burning or dropping the obligation', function (string $token) {
    $request = poolLockBridgeRequest($token);
    $payoutBlocked = null;
    fakePoolLockBridgeChains($token, bridgeRequest: $request);

    Process::fake(function () use ($request, &$payoutBlocked) {
        $competitor = competingBridgePoolLock($request);
        $acquired = $competitor->get();
        $payoutBlocked = ! $acquired;

        if ($acquired) {
            $competitor->release();
        }

        throw new RuntimeException('Signer stopped before broadcasting');
    });

    expect(relayPoolLockBridgeRequest($request))->toBeFalse()
        ->and($payoutBlocked)->toBeTrue()
        ->and($request->refresh()->status)->toBe(BridgeRequest::FAILED)
        ->and($request->wrapper_burned)->toBeFalse()
        ->and($request->destination_tx_hash)->toBeNull()
        ->and(BridgeReservation::where('bridge_request_id', $request->id)->first()?->status)
        ->toBe(BridgeReservation::COMMITTED);

    Process::assertDidntRun(fn ($process) => str_contains(implode(' ', $process->command), 'relay-burn'));
    $next = competingBridgePoolLock($request);
    expect($next->get())->toBeTrue();
    $next->release();
})->with(['direct wrapper' => ['SOL'], 'native bridge' => ['CYBER.sol']]);

test('a busy destination pool parks the verified obligation without releasing the other worker lock', function (string $token) {
    $request = poolLockBridgeRequest($token);
    fakePoolLockBridgeChains($token, bridgeRequest: $request);
    Process::fake();
    $holder = competingBridgePoolLock($request);
    expect($holder->get())->toBeTrue();

    try {
        expect(relayPoolLockBridgeRequest($request))->toBeFalse()
            ->and($request->refresh()->status)->toBe(BridgeRequest::AWAITING_LIQUIDITY)
            ->and($request->error_message)->toContain('Another payout')
            ->and($request->source_verified_at)->not->toBeNull()
            ->and($request->wrapper_burned)->toBeFalse()
            ->and($request->destination_tx_hash)->toBeNull()
            ->and(BridgeReservation::where('bridge_request_id', $request->id)->first()?->status)
            ->toBe(BridgeReservation::COMMITTED);

        Process::assertNothingRan();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'pool-destination.test'));
        expect(competingBridgePoolLock($request)->get())->toBeFalse();
    } finally {
        $holder->release();
    }

    $next = competingBridgePoolLock($request);
    expect($next->get())->toBeTrue();
    $next->release();
})->with(['direct wrapper' => ['SOL'], 'native bridge' => ['CYBER.sol']]);

test('insufficient deliverable inventory releases the pool lock without paying', function (string $token) {
    $request = poolLockBridgeRequest($token);
    fakePoolLockBridgeChains($token, lamports: 0, bridgeRequest: $request);
    Process::fake();

    expect(relayPoolLockBridgeRequest($request))->toBeFalse()
        ->and($request->refresh()->status)->toBe(BridgeRequest::AWAITING_LIQUIDITY)
        ->and($request->wrapper_burned)->toBeFalse()
        ->and($request->destination_tx_hash)->toBeNull();

    Process::assertNothingRan();
    $next = competingBridgePoolLock($request);
    expect($next->get())->toBeTrue();
    $next->release();
})->with(['direct wrapper' => ['SOL'], 'native bridge' => ['CYBER.sol']]);
