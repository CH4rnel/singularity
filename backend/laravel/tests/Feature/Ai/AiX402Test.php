<?php

use App\Models\AiApiRequest;
use App\Models\X402Payment;
use App\Services\Ai\AiKeyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * The paid door to the inference API.
 *
 * Everything here runs against a faked facilitator and a faked provider,
 * because what is worth pinning is ours: that an unpaid call is quoted terms
 * we built, that money moves only around an answer that exists, and that the
 * facilitator is never handed the caller's own idea of the price.
 */
const X402_FACILITATOR = 'https://facilitator.test';
const X402_GROQ = 'https://api.groq.com/openai/v1/chat/completions';
const X402_PAYER = '0x857b06519e91e3a54538791bdbb0e22373e36b66';

beforeEach(function () {
    config()->set('ai.providers.groq.api_key', 'test-groq-key');
    config()->set('x402.enabled', true);
    config()->set('x402.facilitator.url', X402_FACILITATOR);
    config()->set('x402.pay_to', '0x00000000000000000000000000000000000000ab');
    config()->set('x402.network', 'eip155:8453');
    config()->set('x402.scheme', 'exact');
    config()->set('x402.asset.address', '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913');
    config()->set('x402.asset.symbol', 'USDC');
    config()->set('x402.asset.decimals', 6);
    config()->set('x402.asset.name', 'USD Coin');
    config()->set('x402.asset.version', '2');
    config()->set('x402.ai.price', '0.01');
    config()->set('x402.ai.models', []);
    config()->set('x402.requests_per_minute', 60);
});

/** One completion, as the upstream would answer it. */
function x402Answer(): array
{
    return [
        'id' => 'chatcmpl-x402',
        'object' => 'chat.completion',
        'model' => 'llama-3.1-8b-instant',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'paid for'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 4, 'total_tokens' => 13],
    ];
}

/**
 * What a client puts in PAYMENT-SIGNATURE.
 *
 * The `accepted` block is the caller's copy of the terms, which this server
 * must never be tempted to use — tests below hand it a lie on purpose.
 */
function x402Header(array $accepted = []): string
{
    return base64_encode((string) json_encode([
        'x402Version' => 2,
        'accepted' => $accepted + [
            'scheme' => 'exact',
            'network' => 'eip155:8453',
            'amount' => '10000',
            'asset' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
            'payTo' => '0x00000000000000000000000000000000000000ab',
            'maxTimeoutSeconds' => 120,
        ],
        'payload' => [
            'signature' => '0x'.str_repeat('ab', 65),
            'authorization' => [
                'from' => X402_PAYER,
                'to' => '0x00000000000000000000000000000000000000ab',
                'value' => '10000',
                'validAfter' => '0',
                'validBefore' => (string) (time() + 600),
                'nonce' => '0x'.str_repeat('11', 32),
            ],
        ],
    ]));
}

/** The facilitator, agreeing unless a test says otherwise. */
function x402Facilitator(array $verify = [], array $settle = [], mixed $upstream = null): void
{
    Http::fake([
        X402_FACILITATOR.'/verify' => Http::response($verify + ['isValid' => true, 'payer' => X402_PAYER]),
        X402_FACILITATOR.'/settle' => Http::response($settle + [
            'success' => true,
            'payer' => X402_PAYER,
            'transaction' => '0xdeadbeef',
            'network' => 'eip155:8453',
        ]),
        X402_GROQ => $upstream ?? Http::response(x402Answer()),
    ]);
}

function x402Ask(array $headers = [], array $body = []): TestResponse
{
    return test()->postJson('/api/ai/v1/chat/completions', $body + [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => 'speak']],
    ], $headers);
}

it('stands aside entirely when the paywall is not configured', function () {
    config()->set('x402.pay_to', '');
    x402Facilitator();

    // The key door answers, exactly as it did before there was a second one.
    x402Ask()->assertStatus(401)->assertJsonPath('error.type', 'authentication_error');

    Http::assertNothingSent();
});

it('quotes its terms to an unpaid caller, in the header and the body', function () {
    x402Facilitator();

    $response = x402Ask()->assertStatus(402);

    $document = json_decode((string) base64_decode((string) $response->headers->get('PAYMENT-REQUIRED'), true), true);

    expect($document['x402Version'])->toBe(2)
        ->and($document['error'])->toBeString()
        ->and($document['resource']['url'])->toContain('/api/ai/v1/chat/completions')
        ->and($document['accepts'])->toHaveCount(1);

    $terms = $document['accepts'][0];

    expect($terms['scheme'])->toBe('exact')
        ->and($terms['network'])->toBe('eip155:8453')
        // One cent of a six-decimal token.
        ->and($terms['amount'])->toBe('10000')
        ->and($terms['payTo'])->toBe('0x00000000000000000000000000000000000000ab')
        // The token's EIP-712 domain, which is what the client signs against.
        ->and($terms['extra'])->toBe(['name' => 'USD Coin', 'version' => '2']);

    // The body repeats the terms and carries the error in the envelope the
    // rest of this API answers in.
    $response->assertJsonPath('accepts.0.amount', '10000')
        ->assertJsonPath('error.code', 'payment_required');

    expect(X402Payment::count())->toBe(0);
});

it('serves a paid call, settles it afterwards and reports the transaction', function () {
    x402Facilitator();

    $response = x402Ask(['PAYMENT-SIGNATURE' => x402Header()])
        ->assertOk()
        ->assertJsonPath('choices.0.message.content', 'paid for');

    $receipt = json_decode((string) base64_decode((string) $response->headers->get('PAYMENT-RESPONSE'), true), true);

    expect($receipt['success'])->toBeTrue()
        ->and($receipt['transaction'])->toBe('0xdeadbeef')
        ->and($receipt['network'])->toBe('eip155:8453');

    $payment = X402Payment::sole();

    expect($payment->payer)->toBe(X402_PAYER)
        ->and($payment->amount)->toBe('10000')
        ->and($payment->resource)->toBe('/api/ai/v1/chat/completions')
        ->and($payment->transaction)->toBe('0xdeadbeef')
        ->and($payment->settled())->toBeTrue();

    // Metered like any other call, naming what paid for it instead of a key.
    $call = AiApiRequest::sole();

    expect($call->ai_api_key_id)->toBeNull()
        ->and($call->x402_payment_id)->toBe($payment->id)
        ->and($call->completion_tokens)->toBe(4);
});

it('verifies before the work and settles only after it', function () {
    x402Facilitator();

    x402Ask(['PAYMENT-SIGNATURE' => x402Header()])->assertOk();

    $sent = [];

    Http::recorded(function (Request $request) use (&$sent): bool {
        $sent[] = $request->url();

        return false;
    });

    // Verification is free and comes first, so a bad authorization costs
    // nothing upstream; settlement comes last, when there is an answer to
    // charge for.
    expect($sent)->toBe([X402_FACILITATOR.'/verify', X402_GROQ, X402_FACILITATOR.'/settle']);
});

it('hands the facilitator its own terms, never the caller’s copy of them', function () {
    x402Facilitator();

    // A caller claiming the price is one atomic unit, on another network.
    x402Ask(['PAYMENT-SIGNATURE' => x402Header([
        'amount' => '1',
        'network' => 'eip155:84532',
        'payTo' => '0x00000000000000000000000000000000000baaad',
    ])])->assertOk();

    foreach (['verify', 'settle'] as $call) {
        Http::assertSent(function (Request $request) use ($call): bool {
            if ($request->url() !== X402_FACILITATOR.'/'.$call) {
                return false;
            }

            $requirements = $request->data()['paymentRequirements'];

            return $requirements['amount'] === '10000'
                && $requirements['network'] === 'eip155:8453'
                && $requirements['payTo'] === '0x00000000000000000000000000000000000000ab';
        });
    }
});

it('refuses an authorization the facilitator will not verify, and spends nothing', function () {
    x402Facilitator(verify: ['isValid' => false, 'invalidReason' => 'insufficient_funds']);

    x402Ask(['PAYMENT-SIGNATURE' => x402Header()])
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'payment_required');

    expect(X402Payment::count())->toBe(0)
        ->and(AiApiRequest::count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => $request->url() === X402_GROQ);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === X402_FACILITATOR.'/settle');
});

it('does not settle for an answer it could not give', function () {
    x402Facilitator(upstream: Http::response(['error' => ['message' => 'no such model']], 400));

    x402Ask(['PAYMENT-SIGNATURE' => x402Header()])->assertStatus(400);

    Http::assertNotSent(fn (Request $request): bool => $request->url() === X402_FACILITATOR.'/settle');

    // The row stays, unsettled: this payer was quoted, verified and served nothing.
    expect(X402Payment::sole()->settled())->toBeFalse();
});

it('turns a failed settlement into a 402 rather than a free answer', function () {
    x402Facilitator(settle: ['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '']);

    x402Ask(['PAYMENT-SIGNATURE' => x402Header()])
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'payment_required');

    expect(X402Payment::sole()->settled())->toBeFalse();
});

it('treats an unreachable facilitator as an unpaid call', function () {
    Http::fake([
        X402_FACILITATOR.'/verify' => Http::response('gateway down', 502),
        X402_GROQ => Http::response(x402Answer()),
    ]);

    x402Ask(['PAYMENT-SIGNATURE' => x402Header()])->assertStatus(402);

    Http::assertNotSent(fn (Request $request): bool => $request->url() === X402_GROQ);
});

it('settles a stream before a single byte of it is sent', function () {
    $frames = implode('', [
        'data: '.json_encode(['id' => 'c1', 'model' => 'llama-3.1-8b-instant', 'choices' => [['delta' => ['content' => 'paid']]], 'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1]])."\n\n",
        "data: [DONE]\n\n",
    ]);

    x402Facilitator(upstream: Http::response($frames, 200, ['Content-Type' => 'text/event-stream']));

    $response = x402Ask(
        ['PAYMENT-SIGNATURE' => x402Header(), 'Accept' => 'text/event-stream'],
        ['stream' => true],
    )->assertOk();

    expect($response->headers->get('PAYMENT-RESPONSE'))->not->toBeNull();

    $payment = X402Payment::sole();
    expect($payment->settled())->toBeTrue();

    expect($response->streamedContent())->toContain('paid');

    $call = AiApiRequest::sole();
    expect($call->streamed)->toBeTrue()
        ->and($call->x402_payment_id)->toBe($payment->id);
});

it('lets a key holder past the paywall without touching a facilitator', function () {
    $token = app(AiKeyService::class)->issue('0x00000000000000000000000000000000000000cc', 'service', gateExempt: true)['token'];

    x402Facilitator();

    $this->withToken($token)->postJson('/api/ai/v1/chat/completions', [
        'model' => 'lain-fast',
        'messages' => [['role' => 'user', 'content' => 'speak']],
    ])->assertOk();

    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), X402_FACILITATOR));

    expect(X402Payment::count())->toBe(0)
        ->and(AiApiRequest::sole()->x402_payment_id)->toBeNull();
});

it('holds one payer to a burst limit', function () {
    config()->set('x402.requests_per_minute', 1);
    x402Facilitator();

    x402Ask(['PAYMENT-SIGNATURE' => x402Header()])->assertOk();

    $second = x402Ask(['PAYMENT-SIGNATURE' => x402Header()])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limit_exceeded');

    expect($second->headers->get('Retry-After'))->not->toBeNull();
});

it('publishes the price alongside the holding, so an agent can choose', function () {
    $payment = $this->getJson('/api/ai/v1')->assertOk()->json('payment');

    expect($payment['protocol'])->toBe('x402')
        ->and($payment['network'])->toBe('eip155:8453')
        ->and($payment['price_per_call'])->toBe('0.01')
        ->and($payment['price_per_call_atomic'])->toBe('10000')
        ->and($payment['asset']['symbol'])->toBe('USDC');

    config()->set('x402.enabled', false);

    expect($this->getJson('/api/ai/v1')->json('payment'))->toBeNull();
});
