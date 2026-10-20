<?php

use App\Models\BridgeRequest;
use App\Models\BridgeReservation;
use App\Services\BridgeAdmissionService;
use App\Services\BridgeInventoryService;
use App\Services\BridgeService;
use App\Support\TokenAmount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\BridgeNativeSourceProof as Proof;

beforeEach(function () {
    config()->set('services.bridge.evm_bridge_address', Proof::BRIDGE);
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('bridge.chains.cyberia.rpc_url', 'https://native-evm.test');
    config()->set('bridge.chains.solana.rpc_url', 'https://native-solana.test');
    config()->set('bridge.chains.solana.deposit_address', Proof::HOT_WALLET);
    Http::preventStrayRequests();
    Process::fake([
        '*relay-release-native*' => Process::result(output: json_encode(['txHash' => 'sol-payout'])),
        '*relay-bridge*' => Process::result(output: json_encode(['txHash' => 'evm-payout'])),
    ]);
});

function nativeSourceRequest(string $direction, string $amount = '1'): BridgeRequest
{
    $request = BridgeRequest::create([
        // Signed by the sender: these tests are about the relay, not B03.
        'recipient_proof' => 'signature',
        'recipient_proven_at' => now(),
        'direction' => $direction, 'token' => 'CYBER.sol',
        'source_chain' => $direction === 'evm_to_sol' ? 'cyberia' : 'solana',
        'source_tx_hash' => $direction === 'evm_to_sol' ? '0x'.str_repeat('ab', 32) : str_repeat('3', 88),
        'source_nonce' => 3,
        'sender_address' => $direction === 'evm_to_sol' ? Proof::SENDER : Proof::SOLANA_SENDER,
        'recipient_address' => $direction === 'evm_to_sol' ? Proof::SOLANA_SENDER : Proof::SENDER,
        'amount' => $amount, 'fee_amount' => '0', 'status' => BridgeRequest::PENDING,
    ]);
    app(BridgeAdmissionService::class)->commit($request, null);

    return $request;
}

function fakeNativeSourceProof(?array $evm = null, ?array $solana = null, bool $unknown = false, string|Closure $inventoryRaw = '999999999999999'): void
{
    Http::fake(function ($request) use ($evm, $solana, $unknown, $inventoryRaw) {
        return Http::response(['result' => match ($request->data()['method'] ?? '') {
            'eth_getTransactionReceipt' => $unknown ? null : ($evm['receipt'] ?? null),
            'eth_getTransactionByHash' => $unknown ? null : ($evm['transaction'] ?? null),
            'getTransaction' => $unknown ? null : $solana,
            'getBalance' => ['value' => 2000000000],
            'getTokenAccountsByOwner' => ['value' => [[
                'account' => ['data' => ['parsed' => ['info' => ['tokenAmount' => ['amount' => $inventoryRaw instanceof Closure ? $inventoryRaw() : $inventoryRaw, 'decimals' => 6]]]]],
            ]]],
            default => null,
        }]);
    });
}

test('native redemption pays receipt net once with zero and historical nonzero contract fees', function (string $net, string $payout) {
    $request = nativeSourceRequest('evm_to_sol');
    $request->update(['fee_amount' => '0.25']);
    fakeNativeSourceProof(evm: Proof::evm($request, net: $net));
    expect(app(BridgeService::class)->processEvmToSol($request))->toBeTrue()
        ->and($request->refresh()->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->source_verified_at)->not->toBeNull()
        ->and($request->fee_amount)->toBe(TokenAmount::fromRaw(bcsub('1000000000000000000', $net, 0), 18));
    Process::assertRan(fn ($process) => $process->command[5] === $payout);
    expect(BridgeReservation::where('bridge_request_id', $request->id)->count())->toBe(1)
        ->and(BridgeReservation::where('bridge_request_id', $request->id)->first()->net_raw)->toBe($payout);
})->with(['deployed zero fee' => ['1000000000000000000', '1000000'], 'historical one percent' => ['990000000000000000', '990000']]);

test('a smart wallet receipt binds its own burn without direct calldata', function () {
    $request = nativeSourceRequest('evm_to_sol');
    $proof = Proof::evm($request);
    $proof['transaction']['to'] = '0x'.str_repeat('9', 40);
    $proof['transaction']['from'] = '0x'.str_repeat('8', 40);
    $proof['transaction']['input'] = '0x12345678';
    fakeNativeSourceProof(evm: $proof);
    expect(app(BridgeService::class)->processEvmToSol($request))->toBeTrue();
    Process::assertRan(fn ($process) => $process->command[5] === '1000000');
});

test('an unset bridge address uses the deployed frontend bridge', function () {
    config()->set('services.bridge.evm_bridge_address', null);
    $request = nativeSourceRequest('evm_to_sol');
    fakeNativeSourceProof(evm: Proof::evm($request));
    expect(app(BridgeService::class)->processEvmToSol($request))->toBeTrue();
});

test('an explicit bridge override is used for receipt verification', function (bool $matches) {
    $override = '0x'.str_repeat('9', 40);
    config()->set('services.bridge.evm_bridge_address', $override);
    $request = nativeSourceRequest('evm_to_sol');
    $proof = Proof::evm($request);
    if ($matches) {
        $proof['transaction']['to'] = $override;
        $proof['receipt']['logs'][1]['address'] = $override;
    }
    fakeNativeSourceProof(evm: $proof);
    expect(app(BridgeService::class)->processEvmToSol($request))->toBe($matches);
    if (! $matches) {
        Process::assertNothingRan();
    }
})->with(['configured bridge' => [true], 'deployed bridge cannot bypass override' => [false]]);

test('a wallet batch binds gross to the selected nonce rather than an earlier burn', function () {
    $request = nativeSourceRequest('evm_to_sol');
    $proof = Proof::evm($request);
    $earlier = Proof::evm($request, '2000000000000000000');
    $earlier['receipt']['logs'][1]['data'] = '0x'.Proof::word('2000000000000000000').Proof::RECIPIENT_HEX.Proof::word('2');
    $proof['receipt']['logs'] = array_merge($earlier['receipt']['logs'], $proof['receipt']['logs']);
    $proof['transaction']['to'] = '0x'.str_repeat('9', 40);
    fakeNativeSourceProof(evm: $proof);
    expect(app(BridgeService::class)->processEvmToSol($request))->toBeTrue();
    Process::assertRan(fn ($process) => $process->command[5] === '1000000');
});

test('verified net obligations remain single, conservative and payable at exact capacity', function () {
    $request = nativeSourceRequest('evm_to_sol');
    $admission = app(BridgeAdmissionService::class);
    $pool = app(BridgeInventoryService::class)->poolKey('evm_to_sol', 'CYBER.sol');
    BridgeReservation::create([
        'reference' => 'another-active-claim', 'pool' => $pool, 'direction' => 'evm_to_sol',
        'token' => 'CYBER.sol', 'net_raw' => '10000', 'decimals' => 6, 'amount' => '0.01',
        'sender_address' => Proof::SENDER, 'recipient_address' => Proof::SOLANA_SENDER,
        'status' => BridgeReservation::PENDING_SOURCE, 'expires_at' => now()->addMinutes(5),
    ]);
    $reserved = $admission->outstandingRaw($pool);
    $proof = Proof::evm($request, net: '990000000000000000');
    $inventoryRaw = '980000';
    fakeNativeSourceProof(evm: $proof, inventoryRaw: function () use (&$inventoryRaw) {
        return $inventoryRaw;
    });
    $service = app(BridgeService::class);
    foreach ([1, 2] as $attempt) {
        expect($service->processEvmToSol($request))->toBeFalse()
            ->and($request->refresh()->status)->toBe(BridgeRequest::AWAITING_LIQUIDITY)
            ->and($admission->outstandingRaw($pool))->toBe('1000000')
            ->and(bccomp($admission->outstandingRaw($pool), $reserved, 0))->toBeLessThanOrEqual(0)
            ->and(BridgeReservation::where('bridge_request_id', $request->id)->count())->toBe(1);
    }
    Process::assertNothingRan();
    $inventoryRaw = '1000000';
    expect($service->processEvmToSol($request))->toBeTrue()
        ->and($admission->outstandingRaw($pool))->toBe('10000');
    Process::assertRan(fn ($process) => $process->command[5] === '990000');
});

test('the recorded public deployed redemption remains payable', function () {
    $proof = json_decode(file_get_contents(base_path('tests/Fixtures/native-redemption-public.json')), true);
    $log = $proof['receipt']['logs'][1];
    $gross = TokenAmount::hexToDec($proof['receipt']['logs'][0]['data']);
    $recipientHex = substr($log['data'], 66, 64);
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $number = TokenAmount::hexToDec($recipientHex);
    $recipient = '';
    while (bccomp($number, '0', 0) > 0) {
        $recipient = $alphabet[(int) bcmod($number, '58')].$recipient;
        $number = bcdiv($number, '58', 0);
    }
    $request = nativeSourceRequest('evm_to_sol', TokenAmount::fromRaw($gross, 18));
    $request->update([
        'source_tx_hash' => $proof['transaction']['hash'],
        'sender_address' => $proof['transaction']['from'], 'recipient_address' => $recipient,
        'source_nonce' => (int) TokenAmount::hexToDec(substr($log['data'], 130, 64)),
    ]);
    fakeNativeSourceProof(evm: $proof);
    expect(app(BridgeService::class)->processEvmToSol($request))->toBeTrue();
    Process::assertRan(fn ($process) => $process->command[5] === TokenAmount::toRaw(TokenAmount::fromRaw($gross, 18), 6));
});

test('legacy sub-microtoken claimed rounding cannot increase the receipt payout', function (string $claim, bool $valid) {
    $request = nativeSourceRequest('evm_to_sol', $claim);
    fakeNativeSourceProof(evm: Proof::evm($request));
    expect(app(BridgeService::class)->processEvmToSol($request))->toBe($valid);
    if ($valid) {
        Process::assertRan(fn ($process) => $process->command[5] === '1000000');
        expect($request->refresh()->amount)->toBe('1.000000000000000000');
    } else {
        Process::assertNothingRan();
    }
})->with([
    'sub-microtoken above' => ['1.000000999999999999', true],
    'sub-microtoken below boundary' => ['0.999999000001', true],
    'microtoken inflation' => ['1.000001', false],
    'microtoken shortfall' => ['0.999999', false],
]);

test('invalid native EVM source evidence cannot trigger a payout', function (Closure $mutate) {
    $request = nativeSourceRequest('evm_to_sol');
    $proof = Proof::evm($request);
    $mutate($proof, $request);
    fakeNativeSourceProof(evm: $proof);
    expect(app(BridgeService::class)->processEvmToSol($request))->toBeFalse()
        ->and($request->refresh()->source_verified_at)->toBeNull()
        ->and($request->destination_tx_hash)->toBeNull();
    Process::assertNothingRan();
})->with([
    'reverted' => [fn (&$p) => $p['receipt']['status'] = '0x0'],
    'different receipt' => [fn (&$p) => $p['receipt']['transactionHash'] = '0x'.str_repeat('cd', 32)],
    'different contract' => [fn (&$p) => $p['receipt']['logs'][1]['address'] = Proof::SENDER],
    'different event topic' => [fn (&$p) => $p['receipt']['logs'][1]['topics'][0] = '0x'.str_repeat('0', 64)],
    'different sender' => [fn (&$p) => $p['receipt']['logs'][1]['topics'][1] = '0x'.str_repeat('0', 64)],
    'different nonce' => [fn (&$p, $r) => $r->update(['source_nonce' => 4])],
    'different recipient' => [fn (&$p, $r) => $r->update(['recipient_address' => Proof::HOT_WALLET])],
    'no wrapper burn' => [fn (&$p) => $p['receipt']['logs'][0]['address'] = Proof::SENDER],
    'wrong burn target' => [fn (&$p) => $p['receipt']['logs'][0]['topics'][2] = $p['receipt']['logs'][0]['topics'][1]],
    'burn after event' => [fn (&$p) => $p['receipt']['logs'] = array_reverse($p['receipt']['logs'])],
    'ambiguous repeated event' => [fn (&$p) => $p['receipt']['logs'] = array_merge($p['receipt']['logs'], $p['receipt']['logs'])],
    'net exceeds gross' => [fn (&$p) => $p['receipt']['logs'][1]['data'] = '0x'.Proof::word('2000000000000000000').Proof::RECIPIENT_HEX.Proof::word('3')],
    'zero net' => [fn (&$p) => $p['receipt']['logs'][1]['data'] = '0x'.Proof::word('0').Proof::RECIPIENT_HEX.Proof::word('3')],
    'direct calldata conflicts' => [fn (&$p) => $p['transaction']['input'] = '0x6714c820'.Proof::word('2000000000000000000').Proof::RECIPIENT_HEX],
    'removed log' => [fn (&$p) => $p['receipt']['logs'][1]['removed'] = true],
    'source-less' => [fn (&$p, $r) => $r->update(['source_tx_hash' => null])],
]);

test('Token-2022 native deposits pay verified credit despite inflated claims or legacy fees', function () {
    $request = nativeSourceRequest('sol_to_evm', '1000000');
    $request->update(['fee_amount' => '250']);
    fakeNativeSourceProof(solana: Proof::solana($request, '1234567'));
    expect(app(BridgeService::class)->processSolToEvm($request))->toBeTrue()
        ->and($request->refresh()->amount)->toBe('1.234567000000000000')
        ->and($request->fee_amount)->toBe('0.000000000000000000');
    Process::assertRan(fn ($process) => $process->command[5] === '1234567000000000000');
});

test('native deltas sum multiple accounts and accept newly created destination accounts', function () {
    $request = nativeSourceRequest('sol_to_evm');
    $proof = Proof::solana($request);
    $proof['meta']['preTokenBalances'] = [Proof::balance(2, $request->sender_address, '600000'), Proof::balance(4, $request->sender_address, '400000')];
    $proof['meta']['postTokenBalances'] = [
        Proof::balance(2, $request->sender_address, '0'), Proof::balance(4, $request->sender_address, '0'),
        Proof::balance(3, Proof::HOT_WALLET, '700000'), Proof::balance(5, Proof::HOT_WALLET, '300000'),
    ];
    fakeNativeSourceProof(solana: $proof);
    expect(app(BridgeService::class)->processSolToEvm($request))->toBeTrue();
    Process::assertRan(fn ($process) => $process->command[5] === '1000000000000000000');
});

test('invalid native Solana source evidence cannot trigger a mint', function (Closure $mutate) {
    $request = nativeSourceRequest('sol_to_evm');
    $proof = Proof::solana($request);
    $mutate($proof, $request);
    fakeNativeSourceProof(solana: $proof);
    expect(app(BridgeService::class)->processSolToEvm($request))->toBeFalse()
        ->and($request->refresh()->source_verified_at)->toBeNull()
        ->and($request->destination_tx_hash)->toBeNull();
    Process::assertNothingRan();
})->with([
    'failed transaction' => [fn (&$p) => $p['meta']['err'] = ['InstructionError' => [0, 'error']]],
    'signature mismatch' => [fn (&$p) => $p['transaction']['signatures'] = ['other-signature']],
    'sender not signer' => [fn (&$p) => $p['transaction']['message']['accountKeys'][0]['signer'] = false],
    'wrong mint' => [function (&$p) {
        foreach (['preTokenBalances', 'postTokenBalances'] as $side) {
            foreach ($p['meta'][$side] as &$b) {
                $b['mint'] = Proof::HOT_WALLET;
            }
        }
    }],
    'wrong hot wallet' => [fn (&$p) => $p['meta']['postTokenBalances'][1]['owner'] = Proof::SOLANA_SENDER],
    'sender missing' => [fn (&$p) => $p['meta']['preTokenBalances'][0]['owner'] = 'someone-else'],
    'debit below credit' => [fn (&$p) => $p['meta']['preTokenBalances'][0]['uiTokenAmount']['amount'] = '999999'],
    'debit above credit' => [fn (&$p) => $p['meta']['preTokenBalances'][0]['uiTokenAmount']['amount'] = '1000001'],
    'wrong decimals' => [fn (&$p) => $p['meta']['postTokenBalances'][1]['uiTokenAmount']['decimals'] = 9],
    'wrong token program' => [fn (&$p) => $p['meta']['postTokenBalances'][1]['programId'] = 'wrong-program'],
    'duplicate account index' => [fn (&$p) => $p['meta']['postTokenBalances'][] = $p['meta']['postTokenBalances'][1]],
    'source-less' => [fn (&$p, $r) => $r->update(['source_tx_hash' => null])],
]);

test('an unreadable source preserves its retryable obligation without reading payout inventory', function (string $direction) {
    $request = nativeSourceRequest($direction);
    fakeNativeSourceProof(unknown: true);
    $service = app(BridgeService::class);
    expect($direction === 'evm_to_sol' ? $service->processEvmToSol($request) : $service->processSolToEvm($request))->toBeFalse()
        ->and($request->refresh()->status)->toBe(BridgeRequest::PENDING)
        ->and($request->source_verified_at)->toBeNull()
        ->and(BridgeReservation::where('bridge_request_id', $request->id)->first()->status)->toBe(BridgeReservation::COMMITTED);
    Process::assertNothingRan();
    Http::assertNotSent(fn ($r) => in_array($r->data()['method'], ['getBalance', 'getTokenAccountsByOwner']));
})->with(['evm_to_sol', 'sol_to_evm']);

test('RPC connection errors keep native source verification recoverable', function (string $direction) {
    $request = nativeSourceRequest($direction);
    Http::fake(fn () => throw new ConnectionException('Fixture network unavailable'));
    $service = app(BridgeService::class);
    expect($direction === 'evm_to_sol' ? $service->processEvmToSol($request) : $service->processSolToEvm($request))->toBeFalse()
        ->and($request->refresh()->status)->toBe(BridgeRequest::PENDING)
        ->and(BridgeReservation::where('bridge_request_id', $request->id)->first()->status)->toBe(BridgeReservation::COMMITTED);
    Process::assertNothingRan();
})->with(['evm_to_sol', 'sol_to_evm']);

test('incomplete source RPC metadata keeps the obligation pending', function (string $direction) {
    $request = nativeSourceRequest($direction);
    $evm = Proof::evm($request);
    unset($evm['receipt']['status']);
    $solana = Proof::solana($request);
    unset($solana['meta']['err']);
    fakeNativeSourceProof(evm: $evm, solana: $solana);
    $service = app(BridgeService::class);
    expect($direction === 'evm_to_sol' ? $service->processEvmToSol($request) : $service->processSolToEvm($request))->toBeFalse()
        ->and($request->refresh()->status)->toBe(BridgeRequest::PENDING)
        ->and(BridgeReservation::where('bridge_request_id', $request->id)->first()->status)->toBe(BridgeReservation::COMMITTED);
    Process::assertNothingRan();
})->with(['evm_to_sol', 'sol_to_evm']);

test('a real sub-unit redemption retains its verified obligation for reconciliation', function () {
    $request = nativeSourceRequest('evm_to_sol', '0.000000000000000001');
    fakeNativeSourceProof(evm: Proof::evm($request, '1'));
    expect(app(BridgeService::class)->processEvmToSol($request))->toBeFalse()
        ->and($request->refresh()->status)->toBe(BridgeRequest::AWAITING_LIQUIDITY)
        ->and($request->source_verified_at)->not->toBeNull()
        ->and(BridgeReservation::where('bridge_request_id', $request->id)->first()->status)->toBe(BridgeReservation::COMMITTED);
    Process::assertNothingRan();
});
