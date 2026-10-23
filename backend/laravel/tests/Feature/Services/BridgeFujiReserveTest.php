<?php

use App\Models\BridgeRequest;
use App\Services\BridgeAdmissionService;
use App\Services\BridgeInventoryService;
use App\Services\BridgeService;
use App\Support\BridgeCapacity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x'.str_repeat('a', 40));
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('bridge.chains.cyberia.rpc_url', 'https://old-rpc.test');
    config()->set('bridge.chains.cyberia_fuji.rpc_url', 'https://new-rpc.test');
    config()->set('bridge.routes.cyberia_fuji_to_evm.enabled', true);
    config()->set('bridge.routes.cyberia_fuji_to_evm.native_destination_reserve_floor', ['CYBER' => '1000']);
    config()->set('bridge.fee.native_transfer_gas_limit', 21000);
    config()->set('bridge.fee.native_gas_price_floor_gwei', '3');
    config()->set('bridge.fee.native_gas_multiplier_bps', 20000);
    Http::preventStrayRequests();
});

test('the old operational baseline and payout gas cannot be redeemed with new genesis CYBER', function (string $balance) {
    Http::fake(fn ($request) => Http::response([
        'result' => $request['method'] === 'eth_getBalance' ? $balance : '0x0',
    ]));

    $capacity = app(BridgeInventoryService::class)->capacity('cyberia_fuji_to_evm', 'CYBER');

    expect($capacity->state)->toBe(BridgeCapacity::AVAILABLE)
        ->and($capacity->availableRaw)->toBe('0')
        ->and($capacity->covers('1'))->toBeFalse();

    $result = app(BridgeAdmissionService::class)->reserve('cyberia_fuji_to_evm', 'CYBER', '1000',
        '0x'.str_repeat('b', 40), '0x'.str_repeat('c', 40));

    expect($result['ok'])->toBeFalse()->and($result['reason'])->toBe('insufficient');
})->with([
    'baseline only' => '0x3635c9adc5dea00000', // 1000 old CYBER
    'baseline and gas only' => '0x3635ca205e87dde000', // 1000.000126
]);

test('migration inventory above the baseline can only redeem its converted capacity once', function () {
    Http::fake(fn ($request) => Http::response([
        // The old wallet holds its 1000 baseline, 1 newly deposited CYBER,
        // and the 0.000126 destination gas reserve.
        'result' => $request['method'] === 'eth_getBalance' ? '0x3643aad7122f41e000' : '0x0',
    ]));

    $admission = app(BridgeAdmissionService::class);
    $sender = '0x'.str_repeat('b', 40);
    $recipient = '0x'.str_repeat('c', 40);

    expect($admission->availableCapacity('cyberia_fuji_to_evm', 'CYBER')->availableRaw)
        ->toBe('1000000000000000000');

    $tooMuch = $admission->reserve('cyberia_fuji_to_evm', 'CYBER', '2000.126', $sender, $recipient);
    expect($tooMuch['ok'])->toBeFalse()->and($tooMuch['reason'])->toBe('insufficient');

    $backed = $admission->reserve('cyberia_fuji_to_evm', 'CYBER', '1000.126', $sender, $recipient);
    expect($backed['ok'])->toBeTrue()
        ->and((string) $backed['reservation']->net_raw)->toBe('1000000000000000000')
        ->and($admission->availableCapacity('cyberia_fuji_to_evm', 'CYBER')->availableRaw)->toBe('0');

    $duplicate = $admission->reserve('cyberia_fuji_to_evm', 'CYBER', '1000.126', $sender, $recipient);
    expect($duplicate['ok'])->toBeFalse()->and($duplicate['reason'])->toBe('insufficient');
});

test('a missing or invalid migration baseline fails closed even when the reverse flag is forced on', function (mixed $floor) {
    config()->set('bridge.routes.cyberia_fuji_to_evm.native_destination_reserve_floor', ['CYBER' => $floor]);
    Http::fake(fn ($request) => Http::response([
        'result' => $request['method'] === 'eth_getBalance' ? '0xd3c21bcecceda1000000' : '0x0',
    ]));

    $capacity = app(BridgeInventoryService::class)->capacity('cyberia_fuji_to_evm', 'CYBER');

    expect($capacity->state)->toBe(BridgeCapacity::UNAVAILABLE)
        ->and($capacity->covers('1'))->toBeFalse();

    $result = app(BridgeAdmissionService::class)->reserve('cyberia_fuji_to_evm', 'CYBER', '1000',
        '0x'.str_repeat('b', 40), '0x'.str_repeat('c', 40));

    expect($result['ok'])->toBeFalse()->and($result['reason'])->toBe('unavailable');
})->with([
    'unset' => [null],
    'empty' => [''],
    'negative' => ['-1'],
    'exponential' => ['1e3'],
    'malformed' => ['unknown'],
    'excess precision' => ['0.0000000000000000001'],
]);

test('the migration baseline does not reduce other existing native CYBER corridors', function () {
    Http::fake(fn ($request) => Http::response([
        'result' => $request['method'] === 'eth_getBalance' ? '0x3643aad7122f41e000' : '0x0',
    ]));

    $inventory = app(BridgeInventoryService::class);

    expect($inventory->capacity('cyberia_fuji_to_evm', 'CYBER')->availableAmount())->toBe('1.000000000000000000')
        ->and($inventory->capacity('robinhood_to_evm', 'CYBER')->availableAmount())->toBe('1001.000000000000000000');
});

test('a verified reverse deposit cannot reach payout while only the protected baseline remains', function () {
    $sender = '0x'.str_repeat('b', 40);
    Http::fake(fn ($request) => Http::response(['result' => match ($request['method']) {
        'eth_getTransactionByHash' => [
            'from' => $sender,
            'to' => '0x'.str_repeat('a', 40),
            'value' => '0x3635c9adc5dea00000', // 1000 new CYBER
        ],
        'eth_getTransactionReceipt' => ['status' => '0x1'],
        'eth_getBalance' => '0x3635ca205e87dde000', // baseline plus gas only
        default => '0x0',
    }]));
    Process::fake();

    $request = app(BridgeService::class)->createRequest(null, 'cyberia_fuji_to_evm', 'cyberia_fuji', '0xsource', 0,
        $sender, '0x'.str_repeat('c', 40), '1000', 'CYBER', '0.126');

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeFalse()
        ->and($request->refresh()->status)->toBe(BridgeRequest::AWAITING_LIQUIDITY)
        ->and($request->destination_tx_hash)->toBeNull()
        ->and($request->wrapper_burned)->toBeFalse();

    Process::assertNothingRan();
});

test('a verified reverse deposit pays only the converted amount above the protected baseline', function () {
    $sender = '0x'.str_repeat('b', 40);
    Http::fake(fn ($request) => Http::response(['result' => match ($request['method']) {
        'eth_getTransactionByHash' => [
            'from' => $sender,
            'to' => '0x'.str_repeat('a', 40),
            'value' => '0x363789521af8530000', // 1000.126 new CYBER
        ],
        'eth_getTransactionReceipt' => ['status' => '0x1'],
        'eth_getBalance' => '0x3643aad7122f41e000', // baseline + 1 old CYBER + gas
        default => '0x0',
    }]));
    Process::fake([
        '*relay-native-transfer*' => Process::result(output: json_encode(['txHash' => '0xpayout'])),
    ]);

    $request = app(BridgeService::class)->createRequest(null, 'cyberia_fuji_to_evm', 'cyberia_fuji', '0xsource', 0,
        $sender, '0x'.str_repeat('c', 40), '1000.126', 'CYBER', '0.126');

    expect(app(BridgeService::class)->processDirectRelay($request))->toBeTrue()
        ->and($request->refresh()->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->wrapper_burned)->toBeFalse();

    Process::assertRan(fn ($process) => in_array('1000000000000000000', (array) $process->command, true));
    Process::assertRanTimes(fn ($process) => str_contains(implode(' ', (array) $process->command), 'relay-native-transfer'), 1);
});
