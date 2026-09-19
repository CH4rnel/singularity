<?php

namespace App\Services\Onramp\Providers;

use App\Services\Onramp\OnrampProvider;
use App\Services\Onramp\OnrampRequest;
use Illuminate\Http\Request;
use Throwable;

/**
 * MoonPay.
 *
 * The widest card coverage of the four and the strictest handoff: a widget URL
 * carrying a wallet address is *refused in production unless it is signed*
 * with the partner secret. That single rule is most of the argument for this
 * whole server-side layer — a browser cannot sign it without holding the
 * secret, and a secret in a bundle is not a secret.
 *
 * The signature is an HMAC-SHA256 over the query string, leading `?` included,
 * base64, appended as one more parameter. Order matters, so the string that is
 * signed is the string that is sent, built once and never reassembled.
 */
class MoonPayProvider extends OnrampProvider
{
    private const METHODS = [
        'card' => 'credit_debit_card',
        'bank' => 'sepa_bank_transfer',
        'apple_pay' => 'apple_pay',
        'google_pay' => 'google_pay',
        'paypal' => 'paypal',
    ];

    private const STATUS = [
        'waitingPayment' => 'pending',
        'waitingAuthorization' => 'pending',
        'pending' => 'delivering',
        'completed' => 'delivered',
        'failed' => 'failed',
    ];

    public function quote(OnrampRequest $request): array
    {
        try {
            $response = $this->http()->get(
                $this->api().'/v3/currencies/'.rawurlencode($request->code('moonpay')).'/buy_quote',
                [
                    'apiKey' => $this->key(),
                    'baseCurrencyCode' => strtolower($request->fiat),
                    'baseCurrencyAmount' => $request->amount,
                    'paymentMethod' => self::METHODS[$request->method] ?? 'credit_debit_card',
                    'areFeesIncluded' => 'true',
                ]
            );
        } catch (Throwable) {
            return $this->refuse('provider_unreachable');
        }

        $data = (array) $response->json();
        $crypto = $this->amount($data['quoteCurrencyAmount'] ?? null);

        if (! $response->successful() || $crypto === null) {
            return $this->refuse('no_quote');
        }

        /*
         * Three fees and they are not interchangeable: `feeAmount` is
         * MoonPay's, `extraFeeAmount` is the partner cut set in the dashboard
         * — ours, when it is set — and `networkFeeAmount` is what the chain
         * charges to deliver. Added together because what a buyer wants to
         * compare is the total they hand over, and shown as one number for the
         * same reason.
         */
        $fee = (float) ($data['feeAmount'] ?? 0)
            + (float) ($data['extraFeeAmount'] ?? 0)
            + (float) ($data['networkFeeAmount'] ?? 0);

        return [
            'ok' => true,
            'crypto_amount' => $crypto,
            'fiat_amount' => $this->amount($data['totalAmount'] ?? $request->amount, 2),
            'rate' => $this->amount($data['quoteCurrencyPrice'] ?? null),
            'fee' => $this->amount($fee, 2),
            'method' => $request->method,
            'reference' => null,
        ];
    }

    public function checkout(OnrampRequest $request, string $reference): array
    {
        // Built in one place and signed as built: a query string reassembled
        // after signing is a signature over something else.
        $query = http_build_query([
            'apiKey' => $this->key(),
            'currencyCode' => $request->code('moonpay'),
            'walletAddress' => $request->address,
            'baseCurrencyCode' => strtolower($request->fiat),
            'baseCurrencyAmount' => $request->amount,
            'paymentMethod' => self::METHODS[$request->method] ?? 'credit_debit_card',
            'externalTransactionId' => $reference,
            'redirectURL' => route('wallet').'?section=buy&ref='.$reference,
        ]);

        $signature = base64_encode(hash_hmac('sha256', '?'.$query, $this->secret(), true));

        return ['url' => $this->widget().'/?'.$query.'&signature='.rawurlencode($signature)];
    }

    /**
     * `Moonpay-Signature-V2: t=<unix>,s=<hex>` over `t.body`.
     *
     * The timestamp is inside the signed string on purpose — without it a
     * captured payload replays forever — so it is checked as well as verified,
     * and anything older than five minutes is not a status update, it is a
     * recording of one.
     */
    public function webhook(Request $http): ?array
    {
        $header = (string) $http->header('Moonpay-Signature-V2', '');
        $body = (string) $http->getContent();

        if ($header === '' || $this->secret() === '') {
            return null;
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$name, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$name] = $value;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $given = (string) ($parts['s'] ?? '');
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $this->secret());

        if ($timestamp <= 0 || ! hash_equals($expected, $given) || abs(time() - $timestamp) > 300) {
            return null;
        }

        $payload = (array) json_decode($body, true);
        $data = (array) ($payload['data'] ?? []);
        $id = (string) ($data['id'] ?? '');
        $status = self::STATUS[(string) ($data['status'] ?? '')] ?? null;

        if ($id === '' || $status === null) {
            return null;
        }

        return [
            'reference' => is_string($data['externalTransactionId'] ?? null) ? $data['externalTransactionId'] : null,
            'order_id' => $id,
            'status' => $status,
            'crypto_amount' => $this->amount($data['quoteCurrencyAmount'] ?? null),
            'fiat_amount' => $this->amount($data['baseCurrencyAmount'] ?? null, 2),
            'tx' => is_string($data['cryptoTransactionId'] ?? null) ? $data['cryptoTransactionId'] : null,
        ];
    }
}
