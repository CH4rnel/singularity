<?php

use App\Models\BridgeRequest;
use App\Services\BridgeAdmissionService;
use App\Services\BridgeFeeService;
use App\Services\BridgeService;
use App\Support\BridgeAmount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x'.str_repeat('a', 40));
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('bridge.chains.cyberia.rpc_url', 'https://old.test');
    config()->set('bridge.chains.cyberia_fuji.rpc_url', 'https://new.test');
    config()->set('bridge.fee.native_gas_price_floor_gwei', '0');
    Http::preventStrayRequests();
    Process::fake(['*relay-native-transfer*' => Process::result(output: json_encode(['txHash' => '0xpayout']))]);
});

test('CYBER conversion keeps the old one-to-thousand economic units in both directions', function () {
    expect(BridgeAmount::destination('evm_to_cyberia_fuji', 'CYBER', '1.000000000000000001'))
        ->toBe('1000.000000000000001000')
        ->and(BridgeAmount::destination('cyberia_fuji_to_evm', 'CYBER', '1000'))
        ->toBe('1.000000000000000000')
        ->and(BridgeAmount::destination('cyberia_fuji_to_evm', 'CYBER', '0.000000000000000999'))
        ->toBe('0.000000000000000000');
});

test('the destination gas fee is converted to source units and rounded upwards', function () {
    expect(BridgeAmount::sourceFee('evm_to_cyberia_fuji', 'CYBER', '0.000000000000000001'))
        ->toBe('0.000000000000000001')
        ->and(BridgeAmount::sourceFee('cyberia_fuji_to_evm', 'CYBER', '0.000126'))
        ->toBe('0.126000000000000000');
    config()->set('bridge.fee.native_gas_price_floor_gwei', '3');
    Http::fake(['*' => Http::response(['result' => '0x0'])]);
    expect(app(BridgeFeeService::class)->feeForBridge('CYBER', '1000', 'cyberia_fuji_to_evm')['fee_amount'])
        ->toBe('0.126000000000000000');
});

test('admission reserves the converted destination amount and refuses a small pool', function () {
    Http::fake(fn ($r) => Http::response(['result' => ($r['method'] === 'eth_getBalance')
        ? '0x1b1ae4d6e2ef500000' : '0x0'])); // 500 new CYBER
    $admission = app(BridgeAdmissionService::class);
    expect($admission->quote('evm_to_cyberia_fuji', 'CYBER', '1')['net_raw'])
        ->toBe('1000000000000000000000');
    $result = $admission->reserve('evm_to_cyberia_fuji', 'CYBER', '1', '0x'.str_repeat('b', 40), '0x'.str_repeat('c', 40));
    expect($result['ok'])->toBeFalse()->and($result['reason'])->toBe('insufficient');
});

test('new bridge requests retain the agreed rate after configuration changes', function () {
    $request = app(BridgeService::class)->createRequest(null, 'evm_to_cyberia_fuji', 'cyberia', '0xsource', 0,
        '0x'.str_repeat('b', 40), '0x'.str_repeat('c', 40), '1', 'CYBER', '0');
    config()->set('bridge.routes.evm_to_cyberia_fuji.conversion_rates.CYBER.numerator', '2');
    expect($request->conversion_numerator)->toBe('1000')
        ->and(BridgeAmount::destination($request->direction, $request->token, '1', $request))
        ->toBe('1000.000000000000000000');
});

test('relay pays converted CYBER once and never burns native deposits', function () {
    $sender = '0x'.str_repeat('b', 40);
    Http::fake(fn ($r) => Http::response(['result' => match ($r['method']) {
        'eth_getTransactionByHash' => ['from' => $sender, 'to' => '0x'.str_repeat('a', 40), 'value' => '0xde0b6b3a7640000'],
        'eth_getTransactionReceipt' => ['status' => '0x1'],
        'eth_getBalance' => '0x21e19e0c9bab2400000', // 10,000 new CYBER
        default => '0x0',
    }]));
    $request = app(BridgeService::class)->createRequest(null, 'evm_to_cyberia_fuji', 'cyberia', '0xsource', 0,
        $sender, '0x'.str_repeat('c', 40), '1', 'CYBER', '0');
    // B03: a recipient other than the sender pays out only once it is proven.
    $request->forceFill(['recipient_proof' => 'signature', 'recipient_proven_at' => now()])->save();
    expect(app(BridgeService::class)->processDirectRelay($request))->toBeTrue()
        ->and($request->refresh()->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->wrapper_burned)->toBeFalse();
    Process::assertRan(fn ($p) => in_array('1000000000000000000000', (array) $p->command, true));
    expect(app(BridgeService::class)->processDirectRelay($request))->toBeFalse();
    Process::assertRanTimes(fn ($p) => str_contains(implode(' ', (array) $p->command), 'relay-native-transfer'), 1);
});

test('a malformed rate stops conversion instead of falling through to one-to-one', function () {
    config()->set('bridge.routes.evm_to_cyberia_fuji.conversion_rates.CYBER.denominator', '0');
    expect(fn () => BridgeAmount::destination('evm_to_cyberia_fuji', 'CYBER', '1'))
        ->toThrow(InvalidArgumentException::class);
});
