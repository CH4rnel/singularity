<?php

namespace App\Services\Onramp\Providers;

use App\Services\Onramp\OnrampProvider;
use App\Services\Onramp\OnrampRequest;
use Throwable;

/**
 * Onramper — an on-ramp made of the other on-ramps.
 *
 * One key in front of about thirty of them, which makes it the fastest way to
 * have coverage and the reason this registry does not collapse into it: an
 * aggregator is a single point of failure, a second set of terms stacked on
 * the first, and an answer that cannot say *why* a particular provider
 * refused. So it is quoted beside the direct integrations and wins on price
 * when it wins, exactly like the rest.
 *
 * Its quote endpoint answers with a list — one entry per underlying on-ramp —
 * and the best payout is taken, which is the same comparison this app does one
 * level up. Two layers of the same ranking is not duplication: theirs covers
 * providers we have no account with, ours covers the ones we do.
 */
class OnramperProvider extends OnrampProvider
{
    private const METHODS = [
        'card' => 'creditcard',
        'bank' => 'banktransfer',
        'apple_pay' => 'applepay',
        'google_pay' => 'googlepay',
    ];

    public function quote(OnrampRequest $request): array
    {
        try {
            $response = $this->http()
                ->withHeaders(['Authorization' => $this->key()])
                ->get($this->api().'/quotes/'.strtolower($request->fiat).'/'.$request->code('onramper'), [
                    'amount' => $request->amount,
                    'paymentMethod' => self::METHODS[$request->method] ?? 'creditcard',
                    'type' => 'buy',
                    'country' => $request->country !== null ? strtolower($request->country) : null,
                ]);
        } catch (Throwable) {
            return $this->refuse('provider_unreachable');
        }

        $rows = (array) $response->json();

        if (! $response->successful() || $rows === []) {
            return $this->refuse('no_quote');
        }

        $best = null;

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row['payout'] ?? null)) {
                // An entry with no payout is an error object explaining why
                // that one on-ramp said no. It is not a quote and must not be
                // ranked as one.
                continue;
            }

            if ($best === null || (float) $row['payout'] > (float) $best['payout']) {
                $best = $row;
            }
        }

        if ($best === null) {
            return $this->refuse('no_quote');
        }

        $fee = (float) ($best['networkFee'] ?? 0) + (float) ($best['transactionFee'] ?? 0);

        return [
            'ok' => true,
            'crypto_amount' => $this->amount($best['payout']),
            'fiat_amount' => $this->amount($request->amount, 2),
            'rate' => $this->amount($best['rate'] ?? null),
            'fee' => $this->amount($fee, 2),
            'method' => $request->method,
            // Which on-ramp underneath actually won, printed on the row: "via
            // Onramper" tells a buyer nothing about whose terms they accepted.
            'via' => is_string($best['ramp'] ?? null) ? $best['ramp'] : null,
            'reference' => null,
        ];
    }

    public function checkout(OnrampRequest $request, string $reference): array
    {
        $code = $request->code('onramper');

        $query = [
            'apiKey' => $this->key(),
            'mode' => 'buy',
            'defaultCrypto' => $code,
            'onlyCryptos' => $code,
            'defaultFiat' => strtoupper($request->fiat),
            'defaultAmount' => $request->amount,
            // The address travels as `wallets`, keyed by asset: the widget
            // supports several at once and takes the one it needs.
            'wallets' => $code.':'.$request->address,
            'partnerContext' => $reference,
            'successRedirectUrl' => route('wallet').'?section=buy&ref='.$reference,
        ];

        return ['url' => $this->widget().'/?'.http_build_query($query)];
    }
}
