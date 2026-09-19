<?php

use App\Models\OnrampOrder;
use App\Services\Onramp\OnrampRouter;
use Illuminate\Support\Facades\Http;

/**
 * Buying crypto with a card.
 *
 * The parts worth pinning are the ones that would fail quietly. A provider
 * that cannot serve somebody has to say *which* refusal it is, or the screen
 * teaches nobody anything. A handoff to MoonPay has to be signed, or it is
 * refused after the redirect, where the person is already gone. A webhook has
 * to be verified, or a stranger writes "delivered" on an order. And the
 * reference tying the two halves together has to be minted here, or a caller
 * can attach their own webhook to somebody else's purchase.
 */
const ONRAMP_ADDRESS = '0x00000000000000000000000000000000000000a1';

function onrampProviders(array $overrides = []): void
{
    config()->set('onramp.enabled', true);
    config()->set('onramp.providers', array_replace_recursive([
        'transak' => [
            'label' => 'Transak',
            'enabled' => true,
            'kind' => 'widget',
            'api' => 'https://transak.test',
            'widget' => 'https://buy.transak.test',
            'key' => 'partner-key',
            'secret' => 'transak-secret',
            'signing' => null,
            'tracking' => 'webhook',
            'methods' => ['card', 'bank'],
            'fiat' => ['USD', 'EUR'],
            'restricted' => ['RU', 'BY'],
            'fee' => ['channel' => 'dashboard', 'declared_bps' => 100, 'max_bps' => 500],
        ],
        'moonpay' => [
            'label' => 'MoonPay',
            'enabled' => true,
            'kind' => 'widget',
            'api' => 'https://moonpay.test',
            'widget' => 'https://buy.moonpay.test',
            'key' => 'pk_test',
            'secret' => 'moonpay-secret',
            'signing' => 'hmac_sha256_query',
            'tracking' => 'webhook',
            'methods' => ['card'],
            'fiat' => ['USD'],
            'restricted' => ['RU'],
            'fee' => ['channel' => 'dashboard', 'declared_bps' => 0, 'max_bps' => 500],
        ],
        'ramp' => [
            'label' => 'Ramp Network',
            'enabled' => true,
            'kind' => 'widget',
            'api' => 'https://ramp.test',
            'widget' => 'https://app.ramp.test',
            // No key: the one provider in this fixture that is switched on
            // and not usable, which is the state every provider starts in.
            'key' => '',
            'secret' => '',
            'signing' => null,
            'tracking' => 'none',
            'methods' => ['card', 'bank'],
            'fiat' => ['USD', 'EUR'],
            'restricted' => ['RU'],
            'fee' => ['channel' => 'dashboard', 'declared_bps' => 0, 'max_bps' => 500],
        ],
    ], $overrides));
}

function onrampPurchase(array $overrides = []): array
{
    return array_merge([
        'fiat' => 'USD',
        'amount' => '100',
        'country' => 'US',
        'method' => 'card',
        'delivery' => 'base:USDC',
        'address' => ONRAMP_ADDRESS,
    ], $overrides);
}

beforeEach(function () {
    onrampProviders();
    config()->set('onramp.mir.url', '');
});

it('lists every provider, including the ones that cannot serve, with the reason', function () {
    $response = $this->getJson('/api/wallet/onramp?country=US')->assertOk();

    $providers = collect($response->json('providers'))->keyBy('key');

    expect($providers->get('transak')['available'])->toBeTrue()
        ->and($providers->get('transak')['reason'])->toBeNull()
        // Switched on, no key: an operator reading this sees what is left to do.
        ->and($providers->get('ramp')['available'])->toBeFalse()
        ->and($providers->get('ramp')['reason'])->toBe('unconfigured')
        // The partner fee is reported as a declaration, because it is applied
        // inside the provider's dashboard and this app cannot enforce it.
        ->and($providers->get('transak')['declared_fee_bps'])->toBe(100);
});

it('says Мир is not served, and says why, instead of leaving it off the screen', function () {
    $closed = $this->getJson('/api/wallet/onramp')->assertOk();

    expect($closed->json('mir.available'))->toBeFalse()
        ->and($closed->json('mir.reason'))->toBe('mir_unserved');

    // When an operator points the slot at a rail they chose, it is offered as
    // somebody else's service and named as such.
    config()->set('onramp.mir.url', 'https://exchanger.example/buy');
    config()->set('onramp.mir.operator', 'Some Exchanger');

    $open = $this->getJson('/api/wallet/onramp')->assertOk();

    expect($open->json('mir.available'))->toBeTrue()
        ->and($open->json('mir.operator'))->toBe('Some Exchanger');
});

it('refuses a restricted country before it asks anybody for a price', function () {
    Http::fake();

    $offers = collect($this->postJson('/api/wallet/onramp/quote', onrampPurchase(['country' => 'RU']))
        ->assertOk()
        ->json('offers'));

    expect($offers)->toHaveCount(3)
        ->and($offers->pluck('available')->unique()->all())->toBe([false])
        ->and($offers->firstWhere('provider', 'transak')['reason'])->toBe('country_restricted');

    // Nothing was asked of anybody: a refusal we can make from config costs
    // no round trip, and a provider must not be told who is being refused.
    Http::assertNothingSent();
});

it('ranks offers by what actually arrives, not by the fee', function () {
    Http::fake([
        'transak.test/*' => Http::response(['response' => [
            'quoteId' => 'q-1',
            'cryptoAmount' => 96.5,
            'fiatAmount' => 100,
            'conversionPrice' => 0.965,
            // The bigger fee, and still the better deal.
            'totalFee' => 3.5,
        ]]),
        'moonpay.test/*' => Http::response([
            'quoteCurrencyAmount' => 95.1,
            'totalAmount' => 100,
            'quoteCurrencyPrice' => 1.0,
            'feeAmount' => 1.5,
            'networkFeeAmount' => 0.4,
        ]),
    ]);

    $offers = collect($this->postJson('/api/wallet/onramp/quote', onrampPurchase())->assertOk()->json('offers'));

    expect($offers->first()['provider'])->toBe('transak')
        ->and($offers->first()['best'])->toBeTrue()
        ->and($offers->first()['crypto_amount'])->toBe('96.5')
        ->and($offers->get(1)['provider'])->toBe('moonpay')
        // MoonPay's three fee lines are one number by the time a buyer sees it.
        ->and($offers->get(1)['fee'])->toBe('1.9')
        // The unusable one keeps its row and its reason at the bottom.
        ->and($offers->last()['provider'])->toBe('ramp')
        ->and($offers->last()['reason'])->toBe('unconfigured');
});

it('keeps a provider outage off the rest of the screen', function () {
    Http::fake([
        'transak.test/*' => Http::response([], 503),
        'moonpay.test/*' => Http::response([
            'quoteCurrencyAmount' => 95.1,
            'totalAmount' => 100,
            'quoteCurrencyPrice' => 1.0,
            'feeAmount' => 1.5,
        ]),
    ]);

    $offers = collect($this->postJson('/api/wallet/onramp/quote', onrampPurchase())->assertOk()->json('offers'));

    expect($offers->firstWhere('provider', 'moonpay')['available'])->toBeTrue()
        ->and($offers->firstWhere('provider', 'transak')['available'])->toBeFalse()
        ->and($offers->firstWhere('provider', 'transak')['reason'])->toBe('no_quote');
});

it('signs the MoonPay handoff, because an unsigned one is refused at the far end', function () {
    $response = $this->postJson('/api/wallet/onramp/checkout', onrampPurchase(['provider' => 'moonpay']))
        ->assertCreated();

    $url = (string) $response->json('url');
    $query = parse_url($url, PHP_URL_QUERY);

    parse_str((string) $query, $parts);

    $signature = $parts['signature'] ?? '';
    // The signature covers the query string as sent, leading '?' included,
    // minus itself — reassembling it differently is signing something else.
    $signed = '?'.substr((string) $query, 0, strpos((string) $query, '&signature='));

    expect($url)->toStartWith('https://buy.moonpay.test/?')
        ->and($parts['walletAddress'])->toBe(ONRAMP_ADDRESS)
        ->and($signature)->toBe(base64_encode(hash_hmac('sha256', $signed, 'moonpay-secret', true)));
});

it('mints the reference itself and writes the row', function () {
    $response = $this->postJson('/api/wallet/onramp/checkout', onrampPurchase([
        'provider' => 'transak',
        // A caller naming its own reference would be a caller able to attach a
        // stranger's webhook to this order. It is ignored.
        'reference' => 'chosen-by-the-caller',
    ]))->assertCreated();

    $reference = (string) $response->json('reference');
    $order = OnrampOrder::query()->where('reference', $reference)->firstOrFail();

    expect($reference)->not->toBe('chosen-by-the-caller')
        ->and($order->status)->toBe('pending')
        ->and($order->provider)->toBe('transak')
        ->and($order->address)->toBe(ONRAMP_ADDRESS)
        ->and($response->json('url'))->toContain('partnerOrderId='.$reference);

    $this->getJson('/api/wallet/onramp/order/'.$reference)
        ->assertOk()
        ->assertJsonPath('order.status', 'pending');
});

it('believes a signed webhook and ignores a forged one', function () {
    $order = OnrampOrder::query()->create([
        'reference' => (string) Str::uuid(),
        'provider' => 'transak',
        'status' => 'pending',
        'fiat' => 'USD',
        'fiat_amount' => '100',
        'chain' => 'base',
        'asset' => 'USDC',
        'address' => ONRAMP_ADDRESS,
        'method' => 'card',
    ]);

    $claims = [
        'webhookData' => [
            'id' => 'transak-order-1',
            'partnerOrderId' => $order->reference,
            'status' => 'COMPLETED',
            'cryptoAmount' => 96.5,
            'transactionHash' => '0xdead',
        ],
    ];

    $forged = onrampJwt($claims, 'not-the-secret');

    $this->call('POST', '/api/wallet/onramp/webhook/transak', [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
    ], $forged)->assertStatus(202);

    expect($order->fresh()->status)->toBe('pending');

    $this->call('POST', '/api/wallet/onramp/webhook/transak', [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
    ], onrampJwt($claims, 'transak-secret'))->assertStatus(202);

    expect($order->fresh()->status)->toBe('delivered')
        ->and($order->fresh()->crypto_amount)->toBe('96.5')
        ->and($order->fresh()->delivered_at)->not->toBeNull();
});

it('never walks a delivered order backwards on a replayed event', function () {
    $order = OnrampOrder::query()->create([
        'reference' => (string) Str::uuid(),
        'provider' => 'transak',
        'provider_order_id' => 'transak-order-2',
        'status' => 'delivered',
        'fiat' => 'USD',
        'fiat_amount' => '100',
        'chain' => 'base',
        'asset' => 'USDC',
        'address' => ONRAMP_ADDRESS,
        'method' => 'card',
        'delivered_at' => now(),
    ]);

    $replay = onrampJwt(['webhookData' => [
        'id' => 'transak-order-2',
        'partnerOrderId' => $order->reference,
        'status' => 'AWAITING_PAYMENT_FROM_USER',
    ]], 'transak-secret');

    $this->call('POST', '/api/wallet/onramp/webhook/transak', [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
    ], $replay)->assertStatus(202);

    expect($order->fresh()->status)->toBe('delivered');
});

it('drops a webhook for an order nobody here started', function () {
    $stray = onrampJwt(['webhookData' => [
        'id' => 'transak-order-3',
        'partnerOrderId' => (string) Str::uuid(),
        'status' => 'COMPLETED',
    ]], 'transak-secret');

    $this->call('POST', '/api/wallet/onramp/webhook/transak', [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
    ], $stray)->assertStatus(202);

    expect(OnrampOrder::query()->count())->toBe(0);
});

it('clamps a partner fee an env file got wrong', function () {
    onrampProviders(['transak' => ['fee' => ['declared_bps' => 4000]]]);

    $router = app(OnrampRouter::class);

    expect($router->provider('transak')->declaredFeeBps())->toBe(500);
});

/** A JWT of the shape Transak signs its webhooks with. */
function onrampJwt(array $claims, string $secret): string
{
    $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

    $head = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
    $body = $encode($claims);
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $head.'.'.$body, $secret, true)), '+/', '-_'), '=');

    return $head.'.'.$body.'.'.$signature;
}
