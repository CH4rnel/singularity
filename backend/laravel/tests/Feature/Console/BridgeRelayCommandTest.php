<?php

use App\Models\BridgeRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
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

test('--force allows re-running completed requests', function () {
    $request = makeStuckRequest(['status' => 'completed']);

    $this->artisan("bridge:relay {$request->id}")
        ->expectsOutputToContain('Use --force')
        ->assertExitCode(1);
});
