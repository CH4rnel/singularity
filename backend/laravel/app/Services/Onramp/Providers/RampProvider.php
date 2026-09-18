<?php

namespace App\Services\Onramp\Providers;

use App\Services\Onramp\OnrampProvider;
use App\Services\Onramp\OnrampRequest;
use Throwable;

/**
 * Ramp Network.
 *
 * Kept for the bank transfers: free SEPA and open-banking rails where the
 * others charge card rates, which for somebody buying a hundred euros is the
 * difference between 1% and 4%. Its quote answers every payment method at once
 * — one call returns a card price and a transfer price side by side — so the
 * asked-for method is picked out of the answer rather than requested.
 *
 * Its webhooks are signed with an ECDSA key, which this app does not verify,
 * so `tracking` is declared `none` in config and an order here never advances
 * on its own. That is stated on the screen. The alternative — believing an
 * unverified POST — is how a stranger writes "delivered" on somebody's order.
 */
class RampProvider extends OnrampProvider
{
    private const METHODS = [
        'card' => 'CARD_PAYMENT',
        'bank' => 'MANUAL_BANK_TRANSFER',
        'apple_pay' => 'APPLE_PAY',
        'google_pay' => 'GOOGLE_PAY',
    ];

    public function quote(OnrampRequest $request): array
    {
        try {
            $response = $this->http()->post($this->api().'/api/host-api/v3/onramp/quote', [
                'cryptoAssetSymbol' => $request->code('ramp'),
                'fiatCurrency' => strtoupper($request->fiat),
                'fiatValue' => (float) $request->amount,
                'hostApiKey' => $this->key(),
            ]);
        } catch (Throwable) {
            return $this->refuse('provider_unreachable');
        }

        $body = (array) $response->json();
        $wanted = self::METHODS[$request->method] ?? 'CARD_PAYMENT';
        $leg = (array) ($body[$wanted] ?? []);

        // A method Ramp does not serve in this country is simply absent from
        // the answer — which is a different refusal from a broken quote, and
        // reads as one.
        if (! $response->successful()) {
            return $this->refuse('no_quote');
        }

        if ($leg === []) {
            return $this->refuse('method_unsupported');
        }

        // `cryptoAmount` comes back in the asset's own smallest units, as a
        // string, because Ramp is one of the few that refuses to put a token
        // amount in a JSON number. Scaled with bcmath rather than divided.
        $decimals = (int) ($leg['asset']['decimals'] ?? 18);
        $raw = (string) ($leg['cryptoAmount'] ?? '');

        if ($raw === '' || ! preg_match('/^[0-9]+$/', $raw)) {
            return $this->refuse('no_quote');
        }

        $crypto = bcdiv($raw, bcpow('10', (string) $decimals, 0), min($decimals, 8));

        return [
            'ok' => true,
            'crypto_amount' => rtrim(rtrim($crypto, '0'), '.') ?: '0',
            'fiat_amount' => $this->amount($leg['fiatValue'] ?? $request->amount, 2),
            'rate' => $this->amount($leg['assetExchangeRate'] ?? null),
            'fee' => $this->amount($leg['appliedFee'] ?? null, 2),
            'method' => $request->method,
            'reference' => null,
        ];
    }

    public function checkout(OnrampRequest $request, string $reference): array
    {
        $query = [
            'hostApiKey' => $this->key(),
            'hostAppName' => (string) config('app.name', 'Cyberia'),
            'hostLogoUrl' => rtrim((string) config('app.url'), '/').'/favicon.ico',
            'swapAsset' => $request->code('ramp'),
            'fiatCurrency' => strtoupper($request->fiat),
            'fiatValue' => $request->amount,
            'userAddress' => $request->address,
            'defaultFlow' => 'ONRAMP',
            'finalUrl' => route('wallet').'?section=buy&ref='.$reference,
        ];

        return ['url' => $this->widget().'/?'.http_build_query($query)];
    }
}
