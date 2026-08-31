<?php

use App\Services\BridgeAdmissionService;
use App\Services\BridgeInventoryService;
use App\Support\BridgeCapacity;
use Illuminate\Support\Facades\Http;

/**
 * What the bridge believes it can deliver, and — the part that matters — what
 * it does when it does not know.
 *
 * `destinationCapacity()` used to answer `null` both for "the relayer mints
 * here, there is no ceiling" and for "the RPC did not answer". The second one
 * therefore behaved like the first: a fail-OPEN gate in front of an
 * irreversible burn. Every test below exists to keep those two apart.
 */
beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('bridge.chains.base.rpc_url', 'https://base-rpc.test');
    config()->set('bridge.chains.bnb.rpc_url', 'https://bsc-rpc.test');
    config()->set('bridge.fee.native_transfer_gas_limit', 21000);
    config()->set('bridge.fee.native_gas_price_floor_gwei', '3');
    config()->set('bridge.fee.native_gas_multiplier_bps', 20000);
});

/**
 * Answer an EVM JSON-RPC fake per method, so a gas-balance read and a
 * balanceOf read can differ — which is the whole point of the solvency check.
 *
 * @param  array<string, string>  $byMethod  hex results keyed by rpc method
 */
function evmRpc(array $byMethod): Closure
{
    return fn ($request) => Http::response([
        'result' => $byMethod[$request->data()['method'] ?? ''] ?? '0x0',
    ]);
}

test('evm_to_base ETH capacity is the relayer native balance minus gas reserve', function () {
    Http::fake(function ($request) {
        return match ($request->data()['method'] ?? null) {
            'eth_getBalance' => Http::response(['result' => '0x1bc16d674ec80000']), // 2 ETH
            'eth_gasPrice' => Http::response(['result' => '0x0']), // floor (3 gwei) wins
            default => Http::response(['result' => '0x0']),
        };
    });

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_base', 'ETH');

    // 2 ETH − (3 gwei × 21000 × 2× multiplier) = 2 − 0.000126.
    expect($capacity->state)->toBe(BridgeCapacity::AVAILABLE)
        ->and($capacity->availableAmount())->toBe('1.999874000000000000');
});

test('evm_to_bnb USDT capacity is the relayer ERC20 balance (18-dec on BSC)', function () {
    Http::fake([
        '*bsc-rpc.test*' => evmRpc([
            'eth_getBalance' => '0xde0b6b3a7640000',   // 1 BNB — gas is payable
            'eth_call' => '0x8ac7230489e80000',        // 10 USDT
        ]),
    ]);

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_bnb', 'USDT');

    expect($capacity->availableAmount())->toBe('10.000000000000000000');
});

test('minting into the home chain is explicitly unlimited, never merely null', function () {
    $capacity = app(BridgeInventoryService::class)->capacity('sol_to_evm', 'USDC');

    expect($capacity->state)->toBe(BridgeCapacity::UNLIMITED)
        ->and($capacity->isUnlimited())->toBeTrue()
        ->and($capacity->availableAmount())->toBeNull()
        // Unlimited covers any amount — that is what makes it different from
        // the unavailable state, which has the same null on screen.
        ->and($capacity->covers('999999999999999999999999'))->toBeTrue();
});

test('native-model CYBER.sol into the home chain is uncapped — CyberBridge mints on release', function () {
    // Must NOT fall into the ERC20 branch: balanceOf(relayer) is just accrued
    // fees, and showing it falsely capped sol_to_evm bridges.
    Http::fake();

    $capacity = app(BridgeInventoryService::class)->capacity('sol_to_evm', 'CYBER.sol');

    expect($capacity->state)->toBe(BridgeCapacity::UNLIMITED);
    Http::assertNothingSent();
});

test('CYBER.sol out to Solana reports the hot-wallet SPL inventory in the mint decimals', function () {
    config()->set('bridge.chains.solana.rpc_url', 'https://sol-rpc.test');

    Http::fake([
        '*sol-rpc.test*' => fn ($request) => Http::response(match ($request->data()['method'] ?? null) {
            'getBalance' => ['result' => ['value' => 500000000]], // 0.5 SOL of fees
            default => ['result' => ['value' => [[
                'account' => ['data' => ['parsed' => ['info' => ['tokenAmount' => [
                    'amount' => '7000000',
                    'decimals' => 6,
                ]]]]],
            ]]]],
        }),
    ]);

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_sol', 'CYBER.sol');

    // Six decimals, because the mint says six — not the 18-dec wrapper on the
    // other side of the corridor.
    expect($capacity->availableAmount())->toBe('7.000000')
        ->and($capacity->availableRaw)->toBe('7000000')
        ->and($capacity->decimals)->toBe(6);
});

test('an RPC failure on a capped route is unavailable and covers nothing', function () {
    Http::fake(['*base-rpc.test*' => Http::response('gateway timeout', 504)]);

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_base', 'ETH');

    expect($capacity->state)->toBe(BridgeCapacity::UNAVAILABLE)
        ->and($capacity->availableAmount())->toBeNull()
        // The bug this replaces: a failed read that behaves like "unlimited".
        ->and($capacity->covers('1'))->toBeFalse()
        ->and($capacity->covers('0'))->toBeFalse();
});

test('a malformed RPC body is unavailable, not zero and not unlimited', function () {
    Http::fake(['*base-rpc.test*' => Http::response(['result' => 'not-a-hex-quantity'])]);

    expect(app(BridgeInventoryService::class)->capacity('evm_to_base', 'ETH')->state)
        ->toBe(BridgeCapacity::UNAVAILABLE);
});

test('a malformed Solana token account is unavailable rather than a silent zero', function () {
    config()->set('bridge.chains.solana.rpc_url', 'https://sol-rpc.test');

    Http::fake([
        '*sol-rpc.test*' => fn ($request) => Http::response(match ($request->data()['method'] ?? null) {
            'getBalance' => ['result' => ['value' => 500000000]],
            default => ['result' => ['value' => [[
                'account' => ['data' => ['parsed' => ['info' => ['tokenAmount' => [
                    'amount' => null,
                    'decimals' => null,
                ]]]]],
            ]]]],
        }),
    ]);

    expect(app(BridgeInventoryService::class)->capacity('evm_to_sol', 'CYBER.sol')->state)
        ->toBe(BridgeCapacity::UNAVAILABLE);
});

test('an ERC20 reserve the relayer cannot pay gas for is zero capacity, not its balance', function () {
    // 10 USDT sitting on BSC with 1000 wei of BNB to move it. Holding a token
    // you cannot transfer is not deliverable inventory — the same failure the
    // wallet already names for a user holding USDC with no CYBER.
    Http::fake([
        '*bsc-rpc.test*' => evmRpc([
            'eth_getBalance' => '0x3e8',
            'eth_call' => '0x8ac7230489e80000',
        ]),
    ]);

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_bnb', 'USDT');

    expect($capacity->state)->toBe(BridgeCapacity::AVAILABLE)
        ->and($capacity->availableRaw)->toBe('0')
        ->and($capacity->covers('1'))->toBeFalse();
});

test('an SPL reserve on a hot wallet with no lamports is zero capacity', function () {
    config()->set('bridge.chains.solana.rpc_url', 'https://sol-rpc.test');

    Http::fake([
        '*sol-rpc.test*' => fn ($request) => Http::response(match ($request->data()['method'] ?? null) {
            // Below the rent + fee reserve: nothing here can be sent anywhere.
            'getBalance' => ['result' => ['value' => 1000]],
            default => ['result' => ['value' => []]],
        }),
    ]);

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_sol', 'CYBER.sol');

    expect($capacity->availableRaw)->toBe('0')
        ->and($capacity->covers('1'))->toBeFalse();
});

test('a chain whose reserves are held by hand is unmeasured, and says so', function () {
    config()->set('bridge.chains.yenten.deposit_address', 'YXandTfYjFC7fuR8h9aRCo5ZwAz4tvbvDL');

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_yenten', 'YTN');

    expect($capacity->state)->toBe(BridgeCapacity::UNMEASURED)
        ->and($capacity->availableAmount())->toBeNull()
        // Not admission-controlled, so it permits — but it is a fourth,
        // explicit state and never pretends to be a number.
        ->and($capacity->covers('1'))->toBeTrue()
        ->and($capacity->reason)->toContain('manual reserves');
});

test('raw-unit boundaries hold at 6, 9 and 18 decimals', function () {
    // The comparison that decides whether money moves is integer-only. A float
    // would round a 6-dec stablecoin at the cent and an 18-dec wrapper at the
    // microether, and "exactly the balance" would stop being deliverable.
    $six = BridgeCapacity::available('1000000', 6);          // 1.000000
    expect($six->covers('1000000'))->toBeTrue()              // exactly equal
        ->and($six->covers('999999'))->toBeTrue()
        ->and($six->covers('1000001'))->toBeFalse();

    $nine = BridgeCapacity::available('492836888', 9);       // the #68 amount
    expect($nine->covers('492836888'))->toBeTrue()
        ->and($nine->covers('492836889'))->toBeFalse()
        // The lamports actually in the hot wallet that day.
        ->and(BridgeCapacity::available('97870923', 9)->covers('492836888'))->toBeFalse();

    $eighteen = BridgeCapacity::available('1000000000000000000', 18);
    expect($eighteen->covers('1000000000000000000'))->toBeTrue()
        ->and($eighteen->covers('1000000000000000001'))->toBeFalse();

    // A reserve larger than the balance floors at zero, never negative.
    expect(BridgeCapacity::available('5', 18)->minus('9')->availableRaw)->toBe('0');
});

test('capacity endpoint returns live inventory for an available route', function () {
    config()->set('bridge.routes.evm_to_base.enabled', true);

    Http::fake([
        '*base-rpc.test*' => evmRpc([
            'eth_getBalance' => '0xde0b6b3a7640000', // 1 ETH — gas is payable
            'eth_call' => '0x4c4b40',                // 5 USDC (6-dec)
        ]),
    ]);

    $this->getJson('/bridge/capacity?direction=evm_to_base&token=USDC')
        ->assertOk()
        ->assertJson([
            'state' => 'available',
            'available' => '5.000000',
            'available_raw' => '5000000',
            'decimals' => 6,
        ]);
});

test('capacity endpoint reports a failed read as unavailable, never as an open door', function () {
    config()->set('bridge.routes.evm_to_base.enabled', true);

    Http::fake(['*base-rpc.test*' => Http::response('nope', 500)]);

    $this->getJson('/bridge/capacity?direction=evm_to_base&token=USDC')
        ->assertOk()
        ->assertJson(['state' => 'unavailable', 'available' => null]);
});

test('capacity endpoint refuses to quote a disabled route', function () {
    // evm_to_base is disabled by default → not offered. `available` stays null
    // as it always did, but the state now says which kind of null it is.
    $this->getJson('/bridge/capacity?direction=evm_to_base&token=ETH')
        ->assertOk()
        ->assertJson(['state' => 'unavailable', 'available' => null]);
});

test('the public capacity is net of what is already promised to somebody else', function () {
    config()->set('bridge.routes.evm_to_base.enabled', true);

    Http::fake([
        '*base-rpc.test*' => evmRpc([
            'eth_getBalance' => '0xde0b6b3a7640000',
            'eth_call' => '0x4c4b40', // 5 USDC held
        ]),
    ]);

    $admission = app(BridgeAdmissionService::class);

    expect($admission->availableCapacity('evm_to_base', 'USDC')->availableRaw)->toBe('5000000');

    $reserved = $admission->reserve(
        'evm_to_base',
        'USDC',
        '2',
        'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w',
        '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
    );

    expect($reserved['ok'])->toBeTrue();

    // 5 USDC held, 2 promised (minus the fee the payout retains) — the screen
    // must show what a NEW transfer can have, not the balance.
    $left = $admission->availableCapacity('evm_to_base', 'USDC');
    $netReserved = (string) $reserved['reservation']->net_raw;

    expect($left->availableRaw)->toBe(bcsub('5000000', $netReserved, 0));
});
