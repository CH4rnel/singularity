<?php

use App\Models\BridgeRequest;
use App\Services\BridgeService;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('bridge.chains.solana.rpc_url', 'https://mainnet.helius-rpc.com/?api-key=test');
    config()->set('bridge.chains.solana.deposit_address', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w');
    config()->set('bridge.chains.yenten.balance_api_urls', ['https://api.yentencoin.info']);
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

test('sol_to_evm native SOL deposit is verified by lamport delta and mints the wrapper', function () {
    Http::fake([
        '*helius-rpc.com*' => Http::response([
            'result' => [
                'transaction' => [
                    'message' => [
                        'accountKeys' => [
                            ['pubkey' => 'SenderSolanaAddrXyz12345678901234567890', 'signer' => true],
                            ['pubkey' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w', 'signer' => false],
                            ['pubkey' => '11111111111111111111111111111111', 'signer' => false],
                        ],
                    ],
                ],
                'meta' => [
                    'err' => null,
                    // Sender pays 10 SOL + 5000 lamports fee; hot wallet gains 10 SOL.
                    'preBalances' => [20000005000, 1000000000, 1],
                    'postBalances' => [10000000000, 11000000000, 1],
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-mint*' => Process::result(
            output: json_encode(['txHash' => '0xsolmint', 'gasDropTxHash' => null]),
            exitCode: 0,
        ),
    ]);

    $request = makeDirectRequest(['token' => 'SOL']);
    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('completed');
    expect($request->destination_tx_hash)->toBe('0xsolmint');
});

test('sol_to_evm native SOL fails when the hot wallet lamports do not increase', function () {
    Http::fake([
        '*helius-rpc.com*' => Http::response([
            'result' => [
                'transaction' => [
                    'message' => [
                        'accountKeys' => [
                            ['pubkey' => 'SenderSolanaAddrXyz12345678901234567890'],
                            ['pubkey' => 'SomeOtherRecipient1111111111111111111111'],
                        ],
                    ],
                ],
                'meta' => [
                    'err' => null,
                    'preBalances' => [20000005000, 0],
                    'postBalances' => [10000000000, 10000000000],
                ],
            ],
        ]),
    ]);

    $request = makeDirectRequest(['token' => 'SOL']);
    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('failed');
    expect($request->error_message)->toContain('verify Solana deposit');
});

test('evm_to_sol native SOL pays out via the system-transfer relay script', function () {
    Http::fake([
        'https://rpc.cyberia.church' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [
                    [
                        'address' => '0x53450B1d205f1e41d10B653FBBDEa74160dafFf4',
                        'topics' => [
                            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                            '0x0000000000000000000000005555555555555555555555555555555555555555',
                            '0x0000000000000000000000000000000000000000000000000000000000abcdef',
                        ],
                        // 10 SOL at 9 decimals = 10000000000 = 0x2540be400
                        'data' => '0x00000000000000000000000000000000000000000000000000000002540be400',
                    ],
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-burn*' => Process::result(
            output: json_encode(['txHash' => '0xburn']),
            exitCode: 0,
        ),
        '*relay-sol-transfer*' => Process::result(
            output: json_encode(['txHash' => 'nativeSolSig', 'status' => 'success']),
            exitCode: 0,
        ),
    ]);

    $request = makeDirectRequest([
        'direction' => 'evm_to_sol',
        'token' => 'SOL',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xevmtx-sol',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
    ]);

    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('completed');
    expect($request->destination_tx_hash)->toBe('nativeSolSig');

    Process::assertRan(fn ($p) => str_contains(
        is_array($p->command) ? implode(' ', $p->command) : $p->command,
        'relay-sol-transfer.ts',
    ));
});

test('unknown token marks the request failed', function () {
    $request = makeDirectRequest(['token' => 'DOGE']);
    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('failed');
    expect($request->error_message)->toContain('Unknown token');
});

test('yenten_to_evm verifies the deposit on the request one-time address and mints the wrapper', function () {
    $depositAddress = 'YRequestDepositAddr1111111111111111';

    Http::fake([
        // The relayer mints whatever the deposit address holds (2 YTN).
        'api.yentencoin.info/unspent/*' => Http::response([
            'result' => [['txid' => str_repeat('a', 64), 'index' => 0, 'value' => 200000000, 'height' => 5]],
            'error' => null,
        ]),
    ]);

    Process::fake([
        '*relay-mint*' => Process::result(
            output: json_encode(['txHash' => '0xytnmint']),
            exitCode: 0,
        ),
    ]);

    $request = makeDirectRequest([
        'direction' => 'yenten_to_evm',
        'token' => 'YTN',
        'source_chain' => 'yenten',
        'deposit_address' => $depositAddress,
        'amount' => '2',
        'fee_amount' => '0',
    ]);

    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('completed');
    expect($request->destination_tx_hash)->toBe('0xytnmint');

    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : $process->command,
        'relay-mint.ts 0x3a5820Be90c3fB9c5F3Fb47a4859544193B0f8C6',
    ));
});

test('evm relay records the confirmed hash even when the script also prints a broadcast hash', function () {
    Http::fake([
        'api.yentencoin.info/unspent/*' => Http::response([
            'result' => [['txid' => str_repeat('a', 64), 'index' => 0, 'value' => 200000000, 'height' => 5]],
            'error' => null,
        ]),
    ]);

    // The relay scripts now print a pre-receipt {"broadcastTxHash":...} line
    // before the confirmed {"txHash":...} line. The confirmed one must win.
    Process::fake([
        '*relay-mint*' => Process::result(
            output: "Relayer: 0xrelayer\n"
                .json_encode(['broadcastTxHash' => '0xbroadcasted'])."\n"
                .json_encode(['txHash' => '0xconfirmed', 'gasDropTxHash' => null]),
            exitCode: 0,
        ),
    ]);

    $request = makeDirectRequest([
        'direction' => 'yenten_to_evm',
        'token' => 'YTN',
        'source_chain' => 'yenten',
        'deposit_address' => 'YRequestDepositAddr1111111111111111',
        'amount' => '2',
        'fee_amount' => '0',
    ]);

    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('completed');
    expect($request->destination_tx_hash)->toBe('0xconfirmed');
});

test('extractRelayTxHash recovers the broadcast hash so a timed-out payout is not retried', function () {
    $extract = new ReflectionMethod(BridgeService::class, 'extractRelayTxHash');
    $extract->setAccessible(true);
    $service = app(BridgeService::class);

    // Timeout case: the receipt wait was killed, so only the pre-receipt
    // broadcast line reached us. Recovering it lets the caller mark the
    // request completed instead of failing (and double-paying on retry).
    $broadcastOnly = "Relayer: 0xrelayer\nTo:      0xrecipient\n"
        .json_encode(['broadcastTxHash' => '0xdeadbeef']);
    expect($extract->invoke($service, $broadcastOnly))->toBe('0xdeadbeef');

    // When both lines are present the confirmed hash wins.
    $both = json_encode(['broadcastTxHash' => '0xbroad'])."\n".json_encode(['txHash' => '0xfinal']);
    expect($extract->invoke($service, $both))->toBe('0xfinal');

    // Nothing usable (timed out before broadcasting) → null, safe to retry.
    expect($extract->invoke($service, "starting up\nnot json here"))->toBeNull();
});

test('a timed-out EVM relay is caught so a broadcast payout is not crashed into a false failure', function () {
    // Laravel's Process::run() re-throws Symfony's timeout wrapped as
    // Illuminate\Process\Exceptions\ProcessTimedOutException. runEvmRelayScript
    // must catch THAT class: catching Symfony's lets the exception escape, so a
    // slow-RPC payout that already broadcast on-chain is recorded as failed and
    // a blind retry double-pays. Regression guard for that exact mismatch.
    Process::fake([
        '*relay-native-transfer*' => new ProcessTimedOutException(
            new Symfony\Component\Process\Exception\ProcessTimedOutException(
                new Symfony\Component\Process\Process(['true']),
                Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL,
            ),
            new ProcessResult(new Symfony\Component\Process\Process(['true'])),
        ),
    ]);

    $run = new ReflectionMethod(BridgeService::class, 'runEvmRelayScript');
    $run->setAccessible(true);

    // Before the fix this invoke raised the uncaught Laravel timeout exception;
    // now it is caught and returns null (no hash streamable in the fake, so the
    // caller marks failed safely rather than the whole job crashing).
    $result = $run->invoke(
        app(BridgeService::class),
        ['scripts/relay-native-transfer.ts', '0xRecipient', '874000000000000'],
        config('bridge.chains.bnb'),
        999,
    );

    expect($result)->toBeNull();
});

test('an EVM relay that broadcast then exited non-zero recovers the hash iff the tx did not revert on-chain', function () {
    // The relay script can broadcast the payout and THEN exit 1 when the
    // receipt wait hits a flaky RPC (`read ETIMEDOUT`). The tx is already
    // mined, so we must recover the broadcast hash — unless the chain says it
    // reverted — instead of false-failing and double-paying on retry.
    $broadcast = '0x'.str_repeat('a', 64);

    config()->set('bridge.chains.bnb.rpc_url', 'https://bsc-test-rpc.example');

    Process::fake([
        '*relay-native-transfer*' => Process::result(
            output: "Relayer: 0xrelayer\nTo:      0xrec\n".json_encode(['broadcastTxHash' => $broadcast]),
            errorOutput: 'read ETIMEDOUT',
            exitCode: 1,
        ),
    ]);

    $run = new ReflectionMethod(BridgeService::class, 'runEvmRelayScript');
    $run->setAccessible(true);
    $chain = config('bridge.chains.bnb');
    $args = ['scripts/relay-native-transfer.ts', '0xRecipient', '874000000000000'];

    // One receipt lookup per invocation, in order: confirmed, unknown, reverted.
    Http::fake(['https://bsc-test-rpc.example*' => Http::sequence()
        ->push(['result' => ['status' => '0x1']])   // mined & succeeded
        ->push(['result' => null])                  // not mined yet / RPC blank
        ->push(['result' => ['status' => '0x0']]),  // mined & reverted
    ]);

    // Confirmed on-chain → recover the hash, mark completed (no retry).
    expect($run->invoke(app(BridgeService::class), $args, $chain, 28))->toBe($broadcast);

    // Not mined yet / RPC unreachable → still recover: the tx was broadcast, so
    // recovering beats a double-paying retry.
    expect($run->invoke(app(BridgeService::class), $args, $chain, 28))->toBe($broadcast);

    // Reverted on-chain → a real failure, return null (safe to retry).
    expect($run->invoke(app(BridgeService::class), $args, $chain, 28))->toBeNull();
});

test('evm_to_yenten burns the wrapper and runs the light-wallet payout', function () {
    config()->set('bridge.chains.yenten.deposit_address', 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL');
    config()->set('bridge.chains.yenten.relayer_wif', 'test-wif-not-used-by-process-fake');
    config()->set('bridge.fee.yenten_payout_fee_ytn', '0.01');

    Http::fake([
        'https://rpc.cyberia.church' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [[
                    'address' => '0x3a5820Be90c3fB9c5F3Fb47a4859544193B0f8C6',
                    'topics' => [
                        '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                        '0x0000000000000000000000005555555555555555555555555555555555555555',
                        '0x0000000000000000000000000000000000000000000000000000000000abcdef',
                    ],
                    'data' => '0x0000000000000000000000000000000000000000000000001bc16d674ec80000',
                ]],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-burn*' => Process::result(
            output: json_encode(['txHash' => '0xytnburn']),
            exitCode: 0,
        ),
        '*npm*relay*' => Process::result(
            output: json_encode(['txHash' => str_repeat('b', 64)]),
            exitCode: 0,
        ),
    ]);

    $request = makeDirectRequest([
        'direction' => 'evm_to_yenten',
        'token' => 'YTN',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xytnsource',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL',
        'amount' => '2',
        'fee_amount' => '0',
    ]);

    app(BridgeService::class)->processDirectRelay($request);
    $request->refresh();

    expect($request->status)->toBe('completed');
    expect($request->destination_tx_hash)->toBe(str_repeat('b', 64));
    expect((float) $request->fee_amount)->toBe(0.01);

    // The payout is net of the retained YTN fee: 2 - 0.01 = 1.99 YTN.
    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : $process->command,
        'relay -- YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL 199000000',
    ));
});

test('evm_to_yenten payout keys never include unclaimed deposit addresses', function () {
    config()->set('bridge.chains.yenten.deposit_address', 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL');
    config()->set('bridge.chains.yenten.relayer_wif', 'central-wif');

    // Awaiting deposit: the user may still claim these coins — off limits.
    makeDirectRequest([
        'direction' => 'yenten_to_evm',
        'token' => 'YTN',
        'source_chain' => 'yenten',
        'source_tx_hash' => null,
        'deposit_address' => 'YAwaitingDepositAddr111111111111111',
        'deposit_wif' => 'awaiting-wif',
        'status' => 'awaiting_deposit',
        'amount' => '0',
    ]);

    // Claimed and minted: these coins belong to the pool.
    makeDirectRequest([
        'direction' => 'yenten_to_evm',
        'token' => 'YTN',
        'source_chain' => 'yenten',
        'source_tx_hash' => null,
        'deposit_address' => 'YClaimedDepositAddr1111111111111111',
        'deposit_wif' => 'claimed-wif',
        'status' => 'completed',
        'amount' => '2',
    ]);

    Http::fake([
        'https://rpc.cyberia.church' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [[
                    'address' => '0x3a5820Be90c3fB9c5F3Fb47a4859544193B0f8C6',
                    'topics' => [
                        '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                        '0x0000000000000000000000005555555555555555555555555555555555555555',
                        '0x0000000000000000000000000000000000000000000000000000000000abcdef',
                    ],
                    'data' => '0x0000000000000000000000000000000000000000000000001bc16d674ec80000',
                ]],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-burn*' => Process::result(output: json_encode(['txHash' => '0xytnburn'])),
        '*npm*relay*' => Process::result(output: json_encode(['txHash' => str_repeat('c', 64)])),
    ]);

    $request = makeDirectRequest([
        'direction' => 'evm_to_yenten',
        'token' => 'YTN',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xytnsource2',
        'sender_address' => '0x5555555555555555555555555555555555555555',
        'recipient_address' => 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL',
        'amount' => '2',
        'fee_amount' => '0',
    ]);

    app(BridgeService::class)->processDirectRelay($request);

    Process::assertRan(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        if (! str_contains($command, 'relay --')) {
            return false;
        }

        $wifs = json_decode((string) ($process->environment['YENTEN_RELAYER_WIFS'] ?? '[]'), true);

        return in_array('central-wif', $wifs, true)
            && in_array('claimed-wif', $wifs, true)
            && ! in_array('awaiting-wif', $wifs, true);
    });
});
