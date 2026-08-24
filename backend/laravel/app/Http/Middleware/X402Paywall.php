<?php

namespace App\Http\Middleware;

use App\Models\X402Payment;
use App\Services\X402\FacilitatorClient;
use App\Services\X402\PaymentTerms;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The other door: pay for this call instead of holding a key.
 *
 * x402 in one middleware. A request with no payment is answered `402` with the
 * terms in `PAYMENT-REQUIRED`; a request carrying `PAYMENT-SIGNATURE` is
 * verified with the facilitator *before* the work and settled with it *after*,
 * and the settlement is reported back in `PAYMENT-RESPONSE`.
 *
 * The order is the whole design. Verification is free and happens first, so a
 * bad authorization costs nothing upstream. Settlement happens once the answer
 * exists but before a single byte of it has been sent — a StreamedResponse has
 * already pulled its first chunk by the time it reaches here, so both the
 * plain and the streaming case know the answer is real before money moves, and
 * neither can charge for a request that failed.
 *
 * What is never trusted is the caller's copy of the terms. The requirements
 * handed to the facilitator are always rebuilt from our own configuration; the
 * payload is the only thing taken from the request. Otherwise a payer could
 * verify a one-cent authorization against one-cent terms of their own making.
 *
 * This middleware stands aside twice: for a caller presenting an API key,
 * whose door is AuthenticateAiApiKey, and when the paywall is not configured,
 * because a half-configured paywall must not quote terms nobody can settle.
 */
class X402Paywall
{
    public function __construct(
        private PaymentTerms $terms,
        private FacilitatorClient $facilitator,
    ) {}

    public function handle(Request $request, Closure $next, ?string $price = null): Response
    {
        if ($this->presentsKey($request) || ! $this->terms->usable()) {
            return $next($request);
        }

        $price = $price ?? $this->terms->priceFor((string) $request->json('model', ''));
        $requirements = $this->terms->requirements($price);
        $payload = $this->terms->decode($request->header('PAYMENT-SIGNATURE'));

        if ($payload === null) {
            return $this->challenge($request, $price, 'This endpoint is paid. Send an x402 payment for these terms.');
        }

        $verified = $this->facilitator->verify($payload, $requirements);

        if (! $verified['isValid']) {
            return $this->challenge(
                $request,
                $price,
                'The payment was not accepted: '.($verified['invalidReason'] ?? 'invalid_payment').'.',
            );
        }

        $payer = $verified['payer'] ?? $this->payerFrom($payload);

        if (($limited = $this->burst($payer)) !== null) {
            return $limited;
        }

        // Written before the work so the metering row this call produces can
        // name what paid for it; stamped only when the chain confirms.
        $payment = X402Payment::create([
            'resource' => '/'.ltrim($request->path(), '/'),
            'payer' => $payer,
            'network' => $requirements['network'],
            'scheme' => $requirements['scheme'],
            'asset' => $requirements['asset'],
            'amount' => $requirements['amount'],
            'created_at' => Carbon::now(),
        ]);

        $request->attributes->set('x402_payment', $payment);

        $response = $next($request);

        // Never charge for an answer we did not give. The row stays, unsettled,
        // as the record that this payer was quoted and served nothing.
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        $settlement = $this->facilitator->settle($payload, $requirements);

        if (! $settlement['success']) {
            Log::warning('x402 settlement failed after the answer was produced', [
                'payer' => $payer,
                'resource' => $payment->resource,
                'reason' => $settlement['errorReason'],
            ]);

            return $this->challenge(
                $request,
                $price,
                'The payment could not be settled: '.($settlement['errorReason'] ?? 'settlement_failed').'.',
            );
        }

        $payment->forceFill([
            'transaction' => $settlement['transaction'],
            'settled_at' => Carbon::now(),
        ])->save();

        $response->headers->set('PAYMENT-RESPONSE', $this->terms->encode([
            'success' => true,
            'payer' => $settlement['payer'] ?? $payer,
            'transaction' => $settlement['transaction'] ?? '',
            'network' => $settlement['network'] ?? $requirements['network'],
            'amount' => $settlement['amount'] ?? $requirements['amount'],
        ]));

        return $response;
    }

    /**
     * The 402 itself: terms in the header, and the same terms plus a sentence
     * in the body.
     *
     * The header is canonical — that is where an x402 client reads them, and
     * its `error` is the protocol's plain string. The body repeats them for a
     * person with curl, and carries the error in the OpenAI envelope the rest
     * of this API answers in, so one client can branch on `error.code` whether
     * it was turned away by the gate, the quota or the price.
     */
    private function challenge(Request $request, string $price, string $message): JsonResponse
    {
        $document = $this->terms->required(
            $request->url(),
            $price,
            'Cyberia inference — one chat completion.',
            $message,
        );

        $body = $document;
        $body['error'] = [
            'message' => $message,
            'type' => 'payment_required',
            'code' => 'payment_required',
            'param' => null,
        ];

        return response()->json($body, 402)
            ->withHeaders(['PAYMENT-REQUIRED' => $this->terms->encode($document)]);
    }

    /**
     * One payer's burst limit.
     *
     * Not a quota — a payer who keeps paying is welcome — but one address must
     * not be able to hold the upstream open between settlements. Replay is not
     * what this stops: an authorization carries a nonce the chain refuses twice.
     */
    private function burst(string $payer): ?JsonResponse
    {
        $perMinute = (int) config('x402.requests_per_minute', 60);

        if ($perMinute <= 0) {
            return null;
        }

        $key = 'x402:'.strtolower($payer);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $seconds = max(1, RateLimiter::availableIn($key));

            return response()->json([
                'error' => [
                    'message' => "This payer is limited to {$perMinute} requests per minute.",
                    'type' => 'rate_limit_error',
                    'code' => 'rate_limit_exceeded',
                    'param' => null,
                ],
            ], 429)->withHeaders(['Retry-After' => (string) $seconds]);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    /** A key was presented, so this is not the door that request is using. */
    private function presentsKey(Request $request): bool
    {
        return trim((string) ($request->bearerToken() ?: $request->header('X-Api-Key', ''))) !== '';
    }

    /**
     * Who paid, when the facilitator did not say.
     *
     * A facilitator is allowed to omit `payer`, and a payment with no name
     * attached would be a receipt nobody can reconcile, so the authorization's
     * own `from` stands in. It is only ever a label here: what makes the
     * payment real is the facilitator's verdict, never this field.
     *
     * @param  array<string, mixed>  $payload
     */
    private function payerFrom(array $payload): string
    {
        $inner = (array) ($payload['payload'] ?? []);

        foreach (['authorization', 'permit2Authorization'] as $shape) {
            $authorization = $inner[$shape] ?? null;
            $from = is_array($authorization) ? ($authorization['from'] ?? null) : null;

            if (is_string($from) && trim($from) !== '') {
                return mb_substr(trim($from), 0, 64);
            }
        }

        return 'unknown';
    }
}
