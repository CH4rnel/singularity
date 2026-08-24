<?php

use App\Models\BridgeRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    // Pin the Solana RPC so Http::fake('*helius-rpc.com*') matches regardless
    // of the local BRIDGE_SOLANA_RPC_URL value.
    config()->set('bridge.chains.solana.rpc_url', 'https://mainnet.helius-rpc.com/?api-key=test');
});

function makeStuckRequest(array $overrides = []): BridgeRequest
{
    return BridgeRequest::create(array_merge([
        'direction' => 'sol_to_evm',
        'token' => 'USDC',
        'source_chain' => 'solana',
        'source_tx_hash' => 'soltx-'.uniqid(),
        'source_nonce' => random_int(1, PHP_INT_MAX),
        'sender_address' => 'SenderSolanaAddrXyz12345678901234567890',
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'amount' => '1',
        'fee_amount' => '0.1',
        'fee_usd' => '0.10',
        'gas_drop_planned' => false,
        'status' => 'failed',
        'error_message' => 'transient relayer error',
    ], $overrides));
}

test('command shows list when no id given', function () {
    makeStuckRequest();

    $this->artisan('bridge:relay')
        ->expectsOutputToContain('Stuck bridge requests')
        ->assertExitCode(0);
});

test('command reports failure for missing id', function () {
    $this->artisan('bridge:relay 99999')
        ->expectsOutputToContain('not found')
        ->assertExitCode(0);
});

test('command retries a failed request and marks it completed on success', function () {
    Http::fake([
        '*helius-rpc.com*' => Http::response([
            'result' => [
                'meta' => [
                    'err' => null,
                    'preTokenBalances' => [],
                    'postTokenBalances' => [
                        [
                            'owner' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
                            'mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                            'uiTokenAmount' => ['amount' => '1000000'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-mint*' => Process::result(
            output: json_encode(['txHash' => '0xevmtx', 'gasDropTxHash' => null]),
            exitCode: 0,
        ),
    ]);

    $request = makeStuckRequest();

    $this->artisan("bridge:relay {$request->id}")
        ->assertExitCode(0);

    $request->refresh();

    expect($request->status)->toBe('completed');
    expect($request->destination_tx_hash)->toBe('0xevmtx');
    expect($request->error_message)->toBeNull();
});

test('--tx looks up by source_tx_hash', function () {
    Http::fake([
        '*helius-rpc.com*' => Http::response([
            'result' => [
                'meta' => [
                    'err' => null,
                    'preTokenBalances' => [],
                    'postTokenBalances' => [
                        [
                            'owner' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
                            'mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                            'uiTokenAmount' => ['amount' => '1000000'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-mint*' => Process::result(
            output: json_encode(['txHash' => '0xevmtx', 'gasDropTxHash' => null]),
            exitCode: 0,
        ),
    ]);

    $request = makeStuckRequest(['source_tx_hash' => 'unique-tx-abc']);

    $this->artisan('bridge:relay --tx=unique-tx-abc')->assertExitCode(0);

    $request->refresh();
    expect($request->status)->toBe('completed');
});

test('a completed request is never re-paid, with or without --force', function () {
    // `--force` used to mean "re-run anything", completed rows included. A
    // completed row has a payout hash on it: re-running is a second transfer
    // to the same recipient, which is how one incident becomes two.
    $request = makeStuckRequest([
        'status' => 'completed',
        'destination_tx_hash' => '0xalreadypaid',
    ]);

    Process::fake();

    $this->artisan("bridge:relay {$request->id}")
        ->expectsOutputToContain('will not be paid again')
        ->assertExitCode(1);

    $this->artisan("bridge:relay {$request->id} --force")
        ->expectsOutputToContain('not even with --force')
        ->assertExitCode(1);

    Process::assertNothingRan();

    expect($request->refresh()->status)->toBe('completed')
        ->and($request->destination_tx_hash)->toBe('0xalreadypaid');
});

test('a payout-confirmed request that is not completed is still never re-paid', function () {
    // The dangerous shape: a payout that broadcast and then lost its worker.
    // The row is not `completed`, so the old guard would have let a retry
    // through — but the hash says the money already left.
    $request = makeStuckRequest([
        'status' => 'paying_out',
        'destination_tx_hash' => '0xbroadcast',
    ]);

    Process::fake();

    $this->artisan("bridge:relay {$request->id} --force")
        ->expectsOutputToContain('will not be paid again')
        ->assertExitCode(1);

    Process::assertNothingRan();
});
