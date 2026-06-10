<?php

use App\Models\BridgeRequest;
use App\Services\BridgeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
});

function makeDirectRequest(array $overrides = []): BridgeRequest
{
    return BridgeRequest::create(array_merge([
        'direction' => 'sol_to_evm',
        'token' => 'USDC',
        'source_chain' => 'solana',
        'source_tx_hash' => 'soltx-'.uniqid(),
        'source_nonce' => random_int(1, PHP_INT_MAX),
        'sender_address' => 'SenderSolanaAddrXyz12345678901234567890',
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'amount' => '10',
        'fee_amount' => '0.1',
        'fee_usd' => '0.10',
        'gas_drop_planned' => false,
        'status' => 'pending',
    ], $overrides));
}

test('sol_to_evm direct relay completes and records destination tx', function () {
    Http::fake([
        '*helius-rpc.com*' => Http::response([
            'result' => [
                'meta' => [
                    'err' => null,
                    'preTokenBalances' => [
                        [
                            'owner' => 'SenderSolanaAddrXyz12345678901234567890',
                            'mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                            'uiTokenAmount' => ['amount' => '15000000'],
                        ],
                        [
                            'owner' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
                            'mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                            'uiTokenAmount' => ['amount' => '0'],
                        ],
                    ],
                    'postTokenBalances' => [
                        [
                            'owner' => 'SenderSolanaAddrXyz12345678901234567890',
                            'mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                            'uiTokenAmount' => ['amount' => '5000000'],
                        ],
                        [
                            'owner' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
                            'mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                            'uiTokenAmount' => ['amount' => '10000000'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-mint*' => Process::result(
            output: "Relayer: 0x...\nTX: 0xdest\n".json_encode(['txHash' => '0xdesttx', 'gasDropTxHash' => null]),
            exitCode: 0,
        ),
    ]);

    $request = makeDirectRequest();
    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('completed');
    expect($request->destination_tx_hash)->toBe('0xdesttx');
});

test('sol_to_evm fails when Solana deposit is underfunded', function () {
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
                            'uiTokenAmount' => ['amount' => '1000000'], // 1 USDC, but user claimed 10
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $request = makeDirectRequest();
    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('failed');
    expect($request->error_message)->toContain('underfunded');
});

test('sol_to_evm fails when hot wallet balance does not increase', function () {
    Http::fake([
        '*helius-rpc.com*' => Http::response([
            'result' => [
                'meta' => [
                    'err' => null,
                    'preTokenBalances' => [],
                    'postTokenBalances' => [],
                ],
            ],
        ]),
    ]);

    $request = makeDirectRequest();
    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('failed');
    expect($request->error_message)->toContain('verify Solana deposit');
});

test('gas drop wei is forwarded to relay script', function () {
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
                            'uiTokenAmount' => ['amount' => '10000000'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-mint*' => Process::result(
            output: json_encode(['txHash' => '0xdesttx', 'gasDropTxHash' => '0xgasdrop']),
            exitCode: 0,
        ),
    ]);

    $request = makeDirectRequest([
        'gas_drop_planned' => true,
        'gas_drop_amount' => '0.01',
    ]);

    app(BridgeService::class)->processDirectRelay($request);

    Process::assertRan(function ($process) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        // 0.01 CYBER at 18 decimals = 10000000000000000 wei
        return str_contains($cmd, 'relay-mint.ts')
            && str_contains($cmd, '10000000000000000');
    });
});

test('evm_to_sol direct relay completes via Solana script', function () {
    Http::fake([
        // EVM RPC eth_getTransactionReceipt
        'https://rpc.cyberia.church' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [
                    [
                        'address' => '0xdc25597B19799010047F17e9591EFE08EFd40077',
                        'topics' => [
                            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                            '0x0000000000000000000000005555555555555555555555555555555555555555',
                            '0x0000000000000000000000000000000000000000000000000000000000abcdef',
                        ],
                        // 10 USDC at 6 decimals = 10000000 = 0x989680
                        'data' => '0x0000000000000000000000000000000000000000000000000000000000989680',
                    ],
                ],
            ],
        ]),
    ]);

    Process::fake([
        // mint model: burn relayer's received tokens first, then SPL transfer
        '*relay-burn*' => Process::result(
            output: json_encode(['txHash' => '0xburn']),
            exitCode: 0,
        ),
        '*relay-spl-transfer*' => Process::result(
            output: json_encode(['txHash' => 'solanaSig123', 'status' => 'success']),
            exitCode: 0,
        ),
    ]);

    $request = makeDirectRequest([
        'direction' => 'evm_to_sol',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xevmtx',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
    ]);

    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('completed');
    expect($request->destination_tx_hash)->toBe('solanaSig123');

    Process::assertRan(fn ($p) => str_contains(
        is_array($p->command) ? implode(' ', $p->command) : $p->command,
        'relay-burn.ts',
    ));
});

test('unknown token marks the request failed', function () {
    $request = makeDirectRequest(['token' => 'DOGE']);
    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('failed');
    expect($request->error_message)->toContain('Unknown token');
});
