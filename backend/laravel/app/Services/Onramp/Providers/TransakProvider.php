<?php

namespace App\Services\Onramp\Providers;

use App\Services\Onramp\OnrampProvider;
use App\Services\Onramp\OnrampRequest;
use Illuminate\Http\Request;
use Throwable;

/**
 * Transak.
 *
 * Here first because of the rails rather than the rate: UPI in India, PIX in
 * Brazil, SEPA Instant in Europe — the payment methods people in those places
 * actually use, which a card-only on-ramp answers with a 3% card fee or a
 * decline. Its quote endpoint is also the plainest of the four: one GET, one
 * object, a fee breakdown that itemises itself.
 *
 * Its webhook is a JWT signed with the partner secret, which is the reason
 * this app is willing to write "delivered" on a row it did not watch: the
 * claim is verifiable, and one that is not gets dropped.
 */
class TransakProvider extends OnrampProvider
{
    private const METHODS = [
        'card' => 'credit_debit_card',
        'bank' => 'sepa_bank_transfer',
        'apple_pay' => 'apple_pay',
        'google_pay' => 'google_pay',
    ];

    /** Transak's own words for where an order has got to, in ours. */
    private const STATUS = [
        'AWAITING_PAYMENT_FROM_USER' => 'pending',
        'PAYMENT_DONE_MARKED_BY_USER' => 'paid',
        'PROCESSING' => 'paid',
        'PENDING_DELIVERY_FROM_TRANSAK' => 'delivering',
        'ON_HOLD_PENDING_DELIVERY_FROM_TRANSAK' => 'delivering',
        'COMPLETED' => 'delivered',
        'CANCELLED' => 'failed',
        'FAILED' => 'failed',
        'REFUNDED' => 'failed',
        'EXPIRED' => 'expired',
    ];

    public function quote(OnrampRequest $request): array
    {
        try {
            $response = $this->http()->get($this->api().'/api/v1/pricing/public/quotes', [
                'partnerApiKey' => $this->key(),
                'fiatCurrency' => strtoupper($request->fiat),
                'cryptoCurrency' => $request->code('transak'),
                'network' => $request->network('transak'),
                'paymentMethod' => self::METHODS[$request->method] ?? 'credit_debit_card',
                'fiatAmount' => $request->amount,
                'isBuyOrSell' => 'BUY',
            ]);
        } catch (Throwable) {
            return $this->refuse('provider_unreachable');
        }

        $data = (array) ($response->json('response') ?? []);
        $crypto = $this->amount($data['cryptoAmount'] ?? null);

        if (! $response->successful() || $crypto === null) {
            return $this->refuse('no_quote');
        }

        return [
            'ok' => true,
            'crypto_amount' => $crypto,
            'fiat_amount' => $this->amount($data['fiatAmount'] ?? $request->amount, 2),
            'rate' => $this->amount($data['conversionPrice'] ?? null),
            // `totalFee` is everything Transak charges, our dashboard cut
            // included — which is why nothing is added to it here.
            'fee' => $this->amount($data['totalFee'] ?? null, 2),
            'method' => $request->method,
            'reference' => is_string($data['quoteId'] ?? null) ? $data['quoteId'] : null,
        ];
    }

    public function checkout(OnrampRequest $request, string $reference): array
    {
        $query = [
            'apiKey' => $this->key(),
            'fiatCurrency' => strtoupper($request->fiat),
            'fiatAmount' => $request->amount,
            'cryptoCurrencyCode' => $request->code('transak'),
            'network' => $request->network('transak'),
            'paymentMethod' => self::METHODS[$request->method] ?? 'credit_debit_card',
            'walletAddress' => $request->address,
            // Ours, and the only thing tying a webhook an hour from now to a
            // row here. Never read back from the browser.
            'partnerOrderId' => $reference,
            'disableWalletAddressForm' => 'true',
            'redirectURL' => route('wallet').'?section=buy&ref='.$reference,
        ];

        return ['url' => $this->widget().'/?'.http_build_query($query)];
    }

    /**
     * A JWT signed with the partner secret, carrying the order.
     *
     * Verified here rather than through a library: the one algorithm this
     * accepts is HS256 with our own secret, which is four lines, and a JWT
     * decoder that takes the algorithm from the token it is verifying is the
     * oldest hole in this format. `alg: none` and a swap to RS256 both end at
     * the same `return null` as a wrong signature does.
     *
     * A payload that fails verification is simply not a payload: no row moves,
     * and the caller is told nothing about which part failed.
     */
    public function webhook(Request $http): ?array
    {
        $claims = $this->verifiedClaims(trim((string) $http->getContent()));

        if ($claims === null) {
            return null;
        }

        $data = (array) ($claims['webhookData'] ?? []);
        $id = (string) ($data['id'] ?? '');
        $status = self::STATUS[(string) ($data['status'] ?? '')] ?? null;

        if ($id === '' || $status === null) {
            return null;
        }

        return [
            'reference' => is_string($data['partnerOrderId'] ?? null) ? $data['partnerOrderId'] : null,
            'order_id' => $id,
            'status' => $status,
            'crypto_amount' => $this->amount($data['cryptoAmount'] ?? null),
            'fiat_amount' => $this->amount($data['fiatAmount'] ?? null, 2),
            'tx' => is_string($data['transactionHash'] ?? null) ? $data['transactionHash'] : null,
        ];
    }

    /**
     * The claims of an HS256 token signed with our secret, or null.
     *
     * Constant-time comparison, and the header's `alg` is checked against the
     * one algorithm this accepts rather than used to choose one.
     */
    private function verifiedClaims(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $this->secret() === '') {
            return null;
        }

        [$head, $body, $signature] = $parts;

        $expected = $this->base64url(hash_hmac('sha256', $head.'.'.$body, $this->secret(), true));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $header = json_decode((string) $this->unbase64url($head), true);
        $claims = json_decode((string) $this->unbase64url($body), true);

        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        return $claims;
    }

    private function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unbase64url(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
