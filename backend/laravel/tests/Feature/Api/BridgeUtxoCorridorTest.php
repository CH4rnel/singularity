<?php

use App\Models\BridgeRequest;
use App\Services\Bitcoin\BitcoinDepositDeriver;
use App\Services\BridgeAdmissionService;
use App\Services\BridgeConfigService;
use App\Services\BridgeInventoryService;
use App\Services\BridgeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Bitcoin and Litecoin, both ways.
 *
 * These two are the mirror image of the Monero corridor. There, nothing can be
 * read without the wallet, so the corridor exists only where this server holds
 * one. Here everything can be read by anybody — Esplora will say what is
 * unspent on any address, keyless — and the scarce thing is the *key*: seeing
 * a deposit needs no secret, paying one out needs one, and the two directions
 * therefore switch on independently.
 *
 * What is pinned below is that separation, and the arithmetic nobody should
 * have to rediscover: an output's depth decides whether it is creditable, an
 * index that goes quiet is never read as an empty address, and capacity is
 * what the pool holds minus what the miner will want.
 */
const BTC_ESPLORA = 'https://mempool.space/api';
const BTC_CENTRAL = '1BgGZ9tcN4rm9KBzDn7KprQz87SZ26SAMH';
const BTC_EVM_RECIPIENT = '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045';
/** A P2PKH address on Bitcoin, for the outbound direction's recipient. */
const BTC_PAYOUT_RECIPIENT = '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa';
const BTC_SEED = 'f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1';
/** Any valid WIF: the relay is faked here, so it only has to be present. */
const BTC_RELAYER_WIF = 'L1aW4aubDFB7yfras2S1mN3bqg9nwySY8nkoLmJebSLD5BWv3ENZ';

beforeEach(function () {
    config()->set('services.bridge.relayer_address', '0x0000000000000000000000000000000000abcdef');
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('bridge.chains.bitcoin.enabled', true);
    config()->set('bridge.chains.bitcoin.esplora_url', BTC_ESPLORA);
    config()->set('bridge.chains.bitcoin.hd_seed', BTC_SEED);
    config()->set('bridge.chains.bitcoin.deposit_address', BTC_CENTRAL);
    config()->set('bridge.chains.bitcoin.relayer_wif', BTC_RELAYER_WIF);
    config()->set('bridge.chains.bitcoin.minimum_confirmations', 3);
    config()->set('bridge.chains.bitcoin.deposit_ttl_minutes', 1440);
    config()->set('bridge.chains.bitcoin.fee_reserve', '0.0002');
    config()->set('bridge.chains.bitcoin.payout_fee', '0.00005');
    config()->set('bridge.routes.btc_to_evm.enabled', true);
    config()->set('bridge.routes.btc_to_evm.coming_soon', false);
    config()->set('bridge.routes.evm_to_btc.enabled', true);
    config()->set('bridge.routes.evm_to_btc.coming_soon', false);

    esploraUtxos([]);
    esploraStatus(200);
    fakeEsplora();
});

/**
 * What the fake index currently holds, keyed by address.
 *
 * A single mutable answer rather than a second `Http::fake()` half way
 * through a test: Laravel *merges* successive fakes and the earliest matching
 * stub wins, so re-faking to make a deposit appear silently keeps the address
 * empty — which is exactly the kind of quiet lie these tests exist to catch.
 *
 * @param  array<string, array<int, array<string, mixed>>>|null  $set
 * @return array<string, array<int, array<string, mixed>>>
 */
function esploraUtxos(?array $set = null): array
{
    static $utxos = [];

    if ($set !== null) {
        $utxos = $set;
    }

    return $utxos;
}

/** HTTP status the index answers with, for the times it answers nothing useful. */
function esploraStatus(?int $set = null): int
{
    static $status = 200;

    if ($set !== null) {
        $status = $set;
    }

    return $status;
}

/** Registered once per test; what it says is decided by the two helpers above. */
function fakeEsplora(int $tip = 800_100): void
{
    Http::fake([
        'mempool.space/api/*' => function (Request $request) use ($tip) {
            if (esploraStatus() !== 200) {
                return Http::response('unavailable', esploraStatus());
            }

            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            if (str_ends_with($path, '/blocks/tip/height')) {
                return Http::response((string) $tip);
            }

            if (preg_match('#/address/([^/]+)/utxo$#', $path, $matches) === 1) {
                return Http::response(esploraUtxos()[urldecode($matches[1])] ?? []);
            }

            if (preg_match('#/tx/[0-9a-f]{64}$#', $path) === 1) {
                return Http::response(['txid' => 'seen']);
            }

            return Http::response('not found', 404);
        },
    ]);
}

/** An unspent output `$confirmations` blocks deep, as Esplora reports it. */
function utxoAt(int $satoshis, int $confirmations, int $tip = 800_100): array
{
    return [
        'txid' => str_repeat('a', 64),
        'vout' => 0,
        'value' => $satoshis,
        'status' => $confirmations > 0
            ? ['confirmed' => true, 'block_height' => $tip - $confirmations + 1]
            : ['confirmed' => false],
    ];
}

function prepareBitcoin(): array
{
    return test()->postJson('/bridge/prepare', [
        'direction' => 'btc_to_evm',
        'token' => 'BTC',
        'recipient_address' => BTC_EVM_RECIPIENT,
    ])->json('bridge_request');
}

test('each request gets its own derived address, and the same request always gets the same one', function () {
    $first = prepareBitcoin();
    $second = prepareBitcoin();

    // A bitcoin P2PKH address, and one per request: the address is what binds
    // a deposit to the recipient committed before it was handed out.
    expect($first['deposit_address'])->toStartWith('1')
        ->and($second['deposit_address'])->not->toBe($first['deposit_address'])
        ->and($first['status'])->toBe('awaiting_deposit')
        ->and($first['confirmations'])->toBe(3);

    // Re-derivable from the seed alone: the database stores an address, and
    // the key that spends it is computed again when it is needed.
    $deriver = BitcoinDepositDeriver::forChain('bitcoin');

    expect($deriver->depositAddress($first['id']))->toBe($first['deposit_address']);
});

test('each direction switches on independently, because each needs a different thing', function () {
    // No seed: no address can be handed out that belongs to one request, so
    // deposits are not taken. Paying out is unaffected — it spends the
    // central wallet and the keys of deposits already minted, and those are
    // recorded rather than derived.
    config()->set('bridge.chains.bitcoin.hd_seed', '');

    $routes = app(BridgeConfigService::class)->availableRoutes();

    expect($routes)->not->toHaveKey('btc_to_evm')
        ->and($routes)->toHaveKey('evm_to_btc');

    // No spending key: the opposite half disappears and taking deposits
    // carries on, which is the arrangement this corridor is built around.
    config()->set('bridge.chains.bitcoin.hd_seed', BTC_SEED);
    config()->set('bridge.chains.bitcoin.relayer_wif', '');

    $routes = app(BridgeConfigService::class)->availableRoutes();

    expect($routes)->toHaveKey('btc_to_evm')
        ->and($routes)->not->toHaveKey('evm_to_btc');

    // And with no index at all, neither: a deposit nobody can see and a fee
    // nobody can price are both refusals.
    config()->set('bridge.chains.bitcoin.relayer_wif', BTC_RELAYER_WIF);
    config()->set('bridge.chains.bitcoin.esplora_url', '');

    $routes = app(BridgeConfigService::class)->availableRoutes();

    expect($routes)->not->toHaveKey('btc_to_evm')
        ->and($routes)->not->toHaveKey('evm_to_btc');
});

test('a deposit deep enough is credited for exactly what landed, and mints the wrapper', function () {
    Process::fake(['*relay-mint*' => Process::result(output: json_encode(['txHash' => '0xbtcmint']))]);

    $prepared = prepareBitcoin();

    // 0.25 BTC, four blocks deep against a three-block rule.
    esploraUtxos([$prepared['deposit_address'] => [utxoAt(25_000_000, 4)]]);

    $this->postJson('/bridge/claim', ['id' => $prepared['id']])->assertOk();

    $request = BridgeRequest::find($prepared['id']);

    expect($request->status)->toBe('completed')
        ->and((float) $request->amount)->toBe(0.25)
        ->and($request->recipient_address)->toBe(BTC_EVM_RECIPIENT)
        ->and($request->destination_tx_hash)->toBe('0xbtcmint');
});

test('a deposit one block short is not credited, and says how deep it must go', function () {
    $prepared = prepareBitcoin();

    esploraUtxos([$prepared['deposit_address'] => [utxoAt(25_000_000, 2)]]);

    $response = $this->postJson('/bridge/claim', ['id' => $prepared['id']]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('3 Bitcoin confirmations')
        ->and($response->json('retryable'))->toBeTrue()
        ->and(BridgeRequest::find($prepared['id'])->status)->toBe('awaiting_deposit');
});

test('an index that goes quiet never closes a deposit window and never reads as empty', function () {
    $prepared = prepareBitcoin();

    // The window is long past and the index cannot be asked what is on the
    // address. Expiring here would abandon coins that may already be sitting
    // on it.
    BridgeRequest::whereKey($prepared['id'])->update(['created_at' => now()->subDays(10)]);
    esploraStatus(429);

    $response = $this->postJson('/bridge/claim', ['id' => $prepared['id']]);

    $response->assertStatus(422);
    expect($response->json('expired'))->toBeNull()
        ->and(BridgeRequest::find($prepared['id'])->status)->toBe('awaiting_deposit');
});

test('capacity is what the pool holds minus what the miner will want', function () {
    // 0.01 BTC confirmed on the central address, plus an unconfirmed output
    // the relay would refuse to build on.
    esploraUtxos([BTC_CENTRAL => [utxoAt(1_000_000, 6), utxoAt(500_000, 0)]]);

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_btc', 'BTC');

    expect($capacity->state)->toBe('available')
        // 0.01 − 0.0002 reserve, and not a satoshi of the unconfirmed half.
        ->and($capacity->toArray()['available_raw'])->toBe('980000');
});

test('an unreadable index is unavailable, which blocks — it is never zero', function () {
    esploraStatus(502);

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_btc', 'BTC');

    // The difference matters: `available 0` would refuse this transfer and
    // every later one silently, while `unavailable` is a corridor saying it
    // cannot tell and must not be signed against.
    expect($capacity->state)->toBe('unavailable');
});

test('with no index configured the reserve is unmeasured rather than zero', function () {
    config()->set('bridge.chains.bitcoin.esplora_url', '');

    $capacity = app(BridgeInventoryService::class)->capacity('evm_to_btc', 'BTC');

    expect($capacity->state)->toBe('unmeasured');
});

test('an outbound payout is signed by the relay and recorded by its hash', function () {
    $txid = str_repeat('b', 64);

    esploraUtxos([BTC_CENTRAL => [utxoAt(1_000_000, 6)]]);
    Process::fake([
        '*relay*' => Process::result(output: json_encode([
            'txHash' => $txid,
            'chain' => 'bitcoin',
            'amount' => '9995000',
            'fee' => '374',
            'spentAddresses' => [BTC_CENTRAL],
        ])),
    ]);

    $request = BridgeRequest::create([
        'direction' => 'evm_to_btc',
        'source_chain' => 'cyberia',
        'token' => 'BTC',
        'amount' => '0.1',
        'sender_address' => BTC_EVM_RECIPIENT,
        'recipient_address' => BTC_PAYOUT_RECIPIENT,
        'source_tx_hash' => '0x'.str_repeat('c', 64),
        'source_nonce' => 0,
        'status' => 'pending',
    ]);

    // The payout half only: source verification is an ordinary EVM receipt
    // read, which every other corridor already covers.
    $paid = (fn () => $this->payoutDestination(
        $request,
        config('bridge.chains.bitcoin'),
        config('bridge.tokens.BTC.chains.bitcoin'),
        config('bridge.tokens.BTC'),
        '0.09995',
    ))->call(app(BridgeService::class));

    expect($paid)->toBeTrue()
        ->and($request->fresh()->destination_tx_hash)->toBe($txid);
});

test('the flat fee is what pays the miner, and the recipient gets the rest exactly', function () {
    $quote = app(BridgeAdmissionService::class)->quote('evm_to_btc', 'BTC', '0.1');

    // 0.00005 BTC retained; the relay then pays a fee out of the pool and the
    // recipient receives the net figure to the satoshi.
    expect($quote['fee'])->toBe('0.00005')
        ->and($quote['net'])->toBe('0.099950000000000000')
        ->and($quote['net_raw'])->toBe('9995000');
});
