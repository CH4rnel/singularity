<?php

use App\Models\BridgeRequest;
use App\Services\BridgeConfigService;
use App\Services\BridgeService;
use App\Services\TonApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const RELAYER = '0x0000000000000000000000000000000000abcdef';
const TON_DEPOSIT = 'EQBofbbpUhtSvnZxOsPmzAv84fq1bG0-Mf79OPB4FrEXsT0I';
const KRSQ_MASTER = 'EQBcumfGKvl8jD1eAjRMggu7xf0JV7D1n5mj4zfYTOnuCXhp';
const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

beforeEach(function () {
    config()->set('services.bridge.relayer_address', RELAYER);
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('services.bridge.ton_relayer_mnemonic', 'test mnemonic words');
    config()->set('bridge.chains.cyberia.rpc_url', 'https://cyberia-rpc.test');
    config()->set('bridge.chains.bnb.rpc_url', 'https://bsc-rpc.test');
    config()->set('bridge.chains.base.rpc_url', 'https://base-rpc.test');
    config()->set('bridge.chains.ton.api_url', 'https://tonapi.test');
    config()->set('bridge.chains.ton.deposit_address', TON_DEPOSIT);
    // Token keys contain dots (USDT.BNB, ETH.BASE), so mutate the array
    // wholesale instead of using config() dot-paths. Wrapper addresses default
    // to '' (env-driven) so the corridors stay hidden until deployed.
    $tokens = config('bridge.tokens');
    $tokens['BNB']['chains']['cyberia']['address'] = '0x00000000000000000000000000000000000000b1';
    $tokens['USDT.BNB']['chains']['cyberia']['address'] = '0x00000000000000000000000000000000000000b2';
    $tokens['ETH.BASE']['chains']['cyberia']['address'] = '0x00000000000000000000000000000000000000e1';
    $tokens['USDC.BASE']['chains']['cyberia']['address'] = '0x00000000000000000000000000000000000000e2';
    config()->set('bridge.tokens', $tokens);

    // Instant TON lookups in tests.
    app()->singleton(TonApiService::class, fn () => new TonApiService('https://tonapi.test', null, 2, 0));
});

function makeRequest(array $attributes): BridgeRequest
{
    return BridgeRequest::create(array_merge([
        'source_nonce' => random_int(1, PHP_INT_MAX),
        'amount' => '1.5',
        'fee_amount' => '0',
        'status' => 'pending',
    ], $attributes));
}

function erc20TransferLog(string $token, string $from, string $to, string $valueHex): array
{
    return [
        'address' => $token,
        'topics' => [
            TRANSFER_TOPIC,
            '0x'.str_pad(substr($from, 2), 64, '0', STR_PAD_LEFT),
            '0x'.str_pad(substr($to, 2), 64, '0', STR_PAD_LEFT),
        ],
        'data' => '0x'.str_pad($valueHex, 64, '0', STR_PAD_LEFT),
    ];
}

test('ton_to_evm verifies the jetton deposit and mints with 9→18 scaling', function () {
    $sender = 'UQDv2p2r_iB1nR7J8Ze5LGUeXENN-te4ezlHqU6JZvS9l9Ix';

    Http::fake([
        'tonapi.test/*' => Http::response([
            'in_progress' => false,
            'actions' => [
                [
                    'type' => 'JettonTransfer',
                    'status' => 'ok',
                    'JettonTransfer' => [
                        'sender' => ['address' => TonApiService::normalizeAddress($sender)],
                        'recipient' => ['address' => TonApiService::normalizeAddress(TON_DEPOSIT)],
                        'jetton' => ['address' => TonApiService::normalizeAddress(KRSQ_MASTER)],
                        'amount' => '1500000000', // 1.5 KRSQ in 9-dec raw
                    ],
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-mint*' => Process::result(output: json_encode(['txHash' => '0xminted'])),
    ]);

    $request = makeRequest([
        'direction' => 'ton_to_evm',
        'token' => 'KRSQ',
        'source_chain' => 'ton',
        'source_tx_hash' => str_repeat('ab', 32),
        'sender_address' => $sender,
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeTrue();

    $request->refresh();
    expect($request->status)->toBe('completed')
        ->and($request->destination_tx_hash)->toBe('0xminted');

    // 1.5 KRSQ → 18-dec wrapper units.
    Process::assertRan(function ($process) {
        $command = implode(' ', $process->command);

        return str_contains($command, 'relay-mint')
            && str_contains($command, '0x4945419ccEEF0Dc70B054700DE2750A056B03eE3')
            && str_contains($command, '1500000000000000000');
    });
});

test('evm_to_ton burns the wrapper and pays out via the TON relay script', function () {
    $sender = '0x5555555555555555555555555555555555555555';

    Http::fake([
        '*cyberia-rpc.test*' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [
                    erc20TransferLog(
                        '0x4945419ccEEF0Dc70B054700DE2750A056B03eE3',
                        $sender,
                        RELAYER,
                        '14d1120d7b160000', // 1.5e18
                    ),
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-burn*' => Process::result(output: json_encode(['txHash' => '0xburned'])),
        '*relay-jetton-transfer*' => Process::result(output: json_encode(['txHash' => 'tonpayout'])),
    ]);

    $request = makeRequest([
        'direction' => 'evm_to_ton',
        'token' => 'KRSQ',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0x'.str_repeat('cd', 32),
        'sender_address' => $sender,
        'recipient_address' => 'UQDv2p2r_iB1nR7J8Ze5LGUeXENN-te4ezlHqU6JZvS9l9Ix',
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeTrue();

    $request->refresh();
    expect($request->status)->toBe('completed')
        ->and($request->destination_tx_hash)->toBe('tonpayout');

    Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 'relay-burn'));

    // 1.5 KRSQ → 9-dec jetton units, query_id = request id.
    Process::assertRan(function ($process) use ($request) {
        $command = implode(' ', $process->command);

        return str_contains($command, 'relay-jetton-transfer')
            && str_contains($command, KRSQ_MASTER)
            && str_contains($command, '1500000000')
            && str_contains($command, (string) $request->id);
    });
});

test('bnb_to_evm verifies a native BNB deposit and mints the wrapper', function () {
    $sender = '0x6666666666666666666666666666666666666666';

    Http::fake([
        '*bsc-rpc.test*' => Http::sequence()
            ->push([
                'result' => [
                    'from' => $sender,
                    'to' => RELAYER,
                    'value' => '0x14d1120d7b160000', // 1.5e18 wei
                ],
            ])
            ->push(['result' => ['status' => '0x1']]),
    ]);

    Process::fake([
        '*relay-mint*' => Process::result(output: json_encode(['txHash' => '0xbnbminted'])),
    ]);

    $request = makeRequest([
        'direction' => 'bnb_to_evm',
        'token' => 'BNB',
        'source_chain' => 'bnb',
        'source_tx_hash' => '0x'.str_repeat('ef', 32),
        'sender_address' => $sender,
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeTrue();

    $request->refresh();
    expect($request->status)->toBe('completed');

    Process::assertRan(function ($process) {
        $command = implode(' ', $process->command);

        return str_contains($command, 'relay-mint')
            && str_contains($command, '0x00000000000000000000000000000000000000b1')
            && str_contains($command, '1500000000000000000');
    });
});

test('evm_to_bnb burns USDT.BNB on Cyberia and pays out on BSC', function () {
    $sender = '0x7777777777777777777777777777777777777777';

    Http::fake([
        '*cyberia-rpc.test*' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [
                    erc20TransferLog(
                        '0x00000000000000000000000000000000000000b2',
                        $sender,
                        RELAYER,
                        '14d1120d7b160000',
                    ),
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-burn*' => Process::result(output: json_encode(['txHash' => '0xburned'])),
        '*relay-erc20-transfer*' => Process::result(output: json_encode(['txHash' => '0xbscpayout'])),
    ]);

    $request = makeRequest([
        'direction' => 'evm_to_bnb',
        'token' => 'USDT.BNB',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0x'.str_repeat('aa', 32),
        'sender_address' => $sender,
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeTrue();

    $request->refresh();
    expect($request->status)->toBe('completed')
        ->and($request->destination_tx_hash)->toBe('0xbscpayout');

    // Payout goes to the canonical BSC USDT contract in 18-dec units.
    Process::assertRan(function ($process) {
        $command = implode(' ', $process->command);

        return str_contains($command, 'relay-erc20-transfer')
            && str_contains($command, '0x55d398326f99059fF775485246999027B3197955')
            && str_contains($command, '1500000000000000000');
    });
});

test('USDT stays off BNB routes and USDT.BNB off Solana routes', function () {
    $service = app(BridgeConfigService::class);

    expect(array_keys($service->tokensForRoute('evm_to_bnb')))
        ->not->toContain('USDT')
        ->and(array_keys($service->tokensForRoute('bnb_to_evm')))
        ->not->toContain('USDT')
        ->and(array_keys($service->tokensForRoute('sol_to_evm')))
        ->not->toContain('USDT.BNB')
        ->and(array_keys($service->tokensForRoute('evm_to_bnb')))
        ->toContain('USDT.BNB');
});

test('Base routes are available and offer dedicated ETH.BASE and USDC.BASE', function () {
    config()->set('bridge.routes.base_to_evm.enabled', true);
    config()->set('bridge.routes.evm_to_base.enabled', true);

    $service = app(BridgeConfigService::class);
    $routes = array_keys($service->availableRoutes());

    expect($routes)->toContain('base_to_evm')->toContain('evm_to_base');

    // ETH.BASE bridges native on Base into its dedicated Cyberia wrapper;
    // USDC.BASE is Circle USDC on Base into its own wrapper.
    expect(array_keys($service->tokensForRoute('base_to_evm')))
        ->toContain('ETH.BASE')
        ->toContain('USDC.BASE')
        ->and(array_keys($service->tokensForRoute('evm_to_base')))
        ->toContain('ETH.BASE')
        ->toContain('USDC.BASE');

    expect($service->tokenOnChain('ETH.BASE', 'base')['native'] ?? false)->toBeTrue();
    expect($service->depositAddress('base'))->toBe(RELAYER);
});

test('Base and Solana reserves never mix: USDC.BASE off Solana, USDC off Base', function () {
    config()->set('bridge.routes.base_to_evm.enabled', true);
    config()->set('bridge.routes.evm_to_base.enabled', true);

    $service = app(BridgeConfigService::class);

    // The shared USDC (Solana reserve) must not appear on Base routes, and the
    // Base-only USDC.BASE must not appear on Solana routes.
    expect(array_keys($service->tokensForRoute('base_to_evm')))
        ->not->toContain('USDC')
        ->not->toContain('ETH')
        ->and(array_keys($service->tokensForRoute('sol_to_evm')))
        ->toContain('USDC')
        ->not->toContain('USDC.BASE')
        ->not->toContain('ETH.BASE');
});

test('Base tokens hide when their wrapper address is unset', function () {
    config()->set('bridge.routes.base_to_evm.enabled', true);
    config()->set('bridge.routes.evm_to_base.enabled', true);

    // Simulate the default (env-unset) state: empty Cyberia wrapper addresses.
    $tokens = config('bridge.tokens');
    $tokens['ETH.BASE']['chains']['cyberia']['address'] = '';
    $tokens['USDC.BASE']['chains']['cyberia']['address'] = '';
    config()->set('bridge.tokens', $tokens);

    $service = app(BridgeConfigService::class);

    // No token configured on both chains → route drops out of availableRoutes.
    expect($service->tokensForRoute('base_to_evm'))->toBe([]);
    expect(array_keys($service->availableRoutes()))
        ->not->toContain('base_to_evm')
        ->not->toContain('evm_to_base');
});

test('a deposit of the wrong jetton fails ton_to_evm verification', function () {
    Http::fake([
        'tonapi.test/*' => Http::response([
            'in_progress' => false,
            'actions' => [
                [
                    'type' => 'JettonTransfer',
                    'status' => 'ok',
                    'JettonTransfer' => [
                        'sender' => ['address' => '0:'.str_repeat('11', 32)],
                        'recipient' => ['address' => TonApiService::normalizeAddress(TON_DEPOSIT)],
                        'jetton' => ['address' => '0:'.str_repeat('99', 32)],
                        'amount' => '1500000000',
                    ],
                ],
            ],
        ]),
    ]);

    $request = makeRequest([
        'direction' => 'ton_to_evm',
        'token' => 'KRSQ',
        'source_chain' => 'ton',
        'source_tx_hash' => str_repeat('bb', 32),
        'sender_address' => 'UQDv2p2r_iB1nR7J8Ze5LGUeXENN-te4ezlHqU6JZvS9l9Ix',
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeFalse();

    $request->refresh();
    expect($request->status)->toBe('failed');
});

test('publicChains serves the browser-facing public RPC, never the internal one', function () {
    // Prod points the relayer at the internal node; the browser must not get it.
    config()->set('bridge.chains.cyberia.rpc_url', 'http://polygon-edge:8545');
    config()->set('bridge.chains.cyberia.public_rpc_url', 'https://rpc.cyberia.church');

    $chains = collect(app(BridgeConfigService::class)->publicChains())->keyBy('key');

    expect($chains['cyberia']['rpcUrl'])->toBe('https://rpc.cyberia.church');
    // Chains without a public override fall back to their (already public) rpc_url.
    expect($chains['base']['rpcUrl'])->toBe(config('bridge.chains.base.rpc_url'));
});
