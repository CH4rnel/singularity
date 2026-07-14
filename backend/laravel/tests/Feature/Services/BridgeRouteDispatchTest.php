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
    // The native BNB wrapper is env-driven; give it an address so BNB routes
    // are configured in tests. USDT/USDC/ETH wrappers are hardcoded in config.
    $tokens = config('bridge.tokens');
    $tokens['BNB']['chains']['cyberia']['address'] = '0x00000000000000000000000000000000000000b1';
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

test('evm_to_bnb burns the unified USDT wrapper on Cyberia and pays out on BSC', function () {
    $sender = '0x7777777777777777777777777777777777777777';

    Http::fake([
        '*cyberia-rpc.test*' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [
                    erc20TransferLog(
                        '0x94845aF24a3E431593A2b941b2b31836dE45185D', // unified USDT wrapper (6-dec)
                        $sender,
                        RELAYER,
                        '16e360', // 1.5 USDT in 6-dec raw
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
        'token' => 'USDT',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0x'.str_repeat('aa', 32),
        'sender_address' => $sender,
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeTrue();

    $request->refresh();
    expect($request->status)->toBe('completed')
        ->and($request->destination_tx_hash)->toBe('0xbscpayout');

    // Payout goes to the canonical BSC USDT contract, scaled 6→18 dec.
    Process::assertRan(function ($process) {
        $command = implode(' ', $process->command);

        return str_contains($command, 'relay-erc20-transfer')
            && str_contains($command, '0x55d398326f99059fF775485246999027B3197955')
            && str_contains($command, '1500000000000000000');
    });
});

test('unified USDT/USDC span every source chain (pooled reserves)', function () {
    config()->set('bridge.routes.base_to_evm.enabled', true);
    config()->set('bridge.routes.evm_to_base.enabled', true);

    $service = app(BridgeConfigService::class);

    // One USDT across Solana AND BNB; one USDC across Solana AND Base.
    expect(array_keys($service->tokensForRoute('evm_to_bnb')))->toContain('USDT')
        ->and(array_keys($service->tokensForRoute('sol_to_evm')))->toContain('USDT')
        ->and(array_keys($service->tokensForRoute('sol_to_evm')))->toContain('USDC')
        ->and(array_keys($service->tokensForRoute('evm_to_base')))->toContain('USDC');
});

test('Base routes are available and offer unified ETH and USDC', function () {
    config()->set('bridge.routes.base_to_evm.enabled', true);
    config()->set('bridge.routes.evm_to_base.enabled', true);

    $service = app(BridgeConfigService::class);
    $routes = array_keys($service->availableRoutes());

    expect($routes)->toContain('base_to_evm')->toContain('evm_to_base');

    // Native ETH on Base maps to the unified ETH wrapper; Base USDC to the
    // unified USDC wrapper — no per-chain tokens.
    expect(array_keys($service->tokensForRoute('base_to_evm')))
        ->toContain('ETH')
        ->toContain('USDC')
        ->not->toContain('ETH.BASE')
        ->not->toContain('USDC.BASE')
        ->and(array_keys($service->tokensForRoute('evm_to_base')))
        ->toContain('ETH')
        ->toContain('USDC');

    expect($service->tokenOnChain('ETH', 'base')['native'] ?? false)->toBeTrue();
    expect($service->depositAddress('base'))->toBe(RELAYER);
});

test('Robinhood Chain routes are available and offer unified ETH', function () {
    // Inbound is on by default; outbound opens once the relayer is funded
    // (enabled=true + coming_soon=false).
    config()->set('bridge.routes.evm_to_robinhood.enabled', true);
    config()->set('bridge.routes.evm_to_robinhood.coming_soon', false);

    $service = app(BridgeConfigService::class);
    $routes = array_keys($service->availableRoutes());

    expect($routes)->toContain('robinhood_to_evm')->toContain('evm_to_robinhood');

    // Native ETH on Robinhood Chain maps to the unified ETH wrapper.
    expect(array_keys($service->tokensForRoute('robinhood_to_evm')))
        ->toContain('ETH')
        ->and(array_keys($service->tokensForRoute('evm_to_robinhood')))
        ->toContain('ETH');

    expect($service->tokenOnChain('ETH', 'robinhood')['native'] ?? false)->toBeTrue();
    expect($service->depositAddress('robinhood'))->toBe(RELAYER);
    expect(config('bridge.chains.robinhood.evm_chain_id'))->toBe(4663);
});

test('ton_to_evm verifies a native TON deposit, canonicalizes the hash and mints 9→18', function () {
    $sender = 'UQDv2p2r_iB1nR7J8Ze5LGUeXENN-te4ezlHqU6JZvS9l9Ix';
    $msgHash = str_repeat('1a', 32); // what TON Connect hands the frontend
    $txHash = str_repeat('2b', 32); // the indexed root transaction

    Http::fake([
        "tonapi.test/v2/events/{$msgHash}" => Http::response(['error' => 'not found'], 404),
        "tonapi.test/v2/blockchain/messages/{$msgHash}/transaction" => Http::response(['hash' => $txHash]),
        "tonapi.test/v2/events/{$txHash}" => Http::response([
            'event_id' => $txHash,
            'in_progress' => false,
            'actions' => [
                [
                    'type' => 'TonTransfer',
                    'status' => 'ok',
                    'TonTransfer' => [
                        'sender' => ['address' => TonApiService::normalizeAddress($sender)],
                        'recipient' => ['address' => TonApiService::normalizeAddress(TON_DEPOSIT)],
                        'amount' => 1500000000, // 1.5 TON in nanotons
                    ],
                ],
            ],
        ]),
    ]);

    Process::fake([
        '*relay-mint*' => Process::result(output: json_encode(['txHash' => '0xtonminted'])),
    ]);

    $request = makeRequest([
        'direction' => 'ton_to_evm',
        'token' => 'TON',
        'source_chain' => 'ton',
        'source_tx_hash' => $msgHash,
        'sender_address' => $sender,
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeTrue();

    $request->refresh();
    expect($request->status)->toBe('completed')
        ->and($request->destination_tx_hash)->toBe('0xtonminted')
        // Replay protection: the request is re-pointed at the canonical tx
        // hash so resubmitting under either encoding is a duplicate.
        ->and($request->source_tx_hash)->toBe($txHash);

    // 1.5 TON → 18-dec wrapper units on the deployed Cyberia TON wrapper.
    Process::assertRan(function ($process) {
        $command = implode(' ', $process->command);

        return str_contains($command, 'relay-mint')
            && str_contains($command, '0x92aBF73698383176Aa2894F1f7263807C3a4e6e6')
            && str_contains($command, '1500000000000000000');
    });
});

test('evm_to_ton burns the TON wrapper and pays out native TON minus the flat fee', function () {
    $sender = '0x8888888888888888888888888888888888888888';

    Http::fake([
        '*cyberia-rpc.test*' => Http::response([
            'result' => [
                'status' => '0x1',
                'logs' => [
                    erc20TransferLog(
                        '0x92aBF73698383176Aa2894F1f7263807C3a4e6e6',
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
        '*relay-ton-transfer*' => Process::result(output: json_encode(['txHash' => 'tonnativepayout'])),
    ]);

    $request = makeRequest([
        'direction' => 'evm_to_ton',
        'token' => 'TON',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0x'.str_repeat('ce', 32),
        'sender_address' => $sender,
        'recipient_address' => 'UQDv2p2r_iB1nR7J8Ze5LGUeXENN-te4ezlHqU6JZvS9l9Ix',
    ]);

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeTrue();

    $request->refresh();
    expect($request->status)->toBe('completed')
        ->and($request->destination_tx_hash)->toBe('tonnativepayout');

    Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 'relay-burn'));

    // 1.5 TON minus the 0.01 flat payout fee → 1.49 TON in nanotons,
    // query_id = request id.
    Process::assertRan(function ($process) use ($request) {
        $command = implode(' ', $process->command);

        return str_contains($command, 'relay-ton-transfer')
            && str_contains($command, '1490000000')
            && str_contains($command, (string) $request->id);
    });
});

test('TON routes offer native TON alongside the jettons', function () {
    $service = app(BridgeConfigService::class);

    expect(array_keys($service->tokensForRoute('ton_to_evm')))
        ->toContain('TON')
        ->toContain('KRSQ')
        ->toContain('GOAL')
        ->and(array_keys($service->tokensForRoute('evm_to_ton')))
        ->toContain('TON');

    expect($service->tokenOnChain('TON', 'ton')['native'] ?? false)->toBeTrue();

    // With the hot wallet + mnemonic configured (beforeEach), the corridors
    // are live in both directions.
    expect(array_keys($service->availableRoutes()))
        ->toContain('ton_to_evm')
        ->toContain('evm_to_ton');
});

test('TON routes hide while the hot wallet is unset', function () {
    config()->set('bridge.chains.ton.deposit_address', null);

    expect(array_keys(app(BridgeConfigService::class)->availableRoutes()))
        ->not->toContain('ton_to_evm')
        ->not->toContain('evm_to_ton');
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
