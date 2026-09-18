<?php

namespace App\Services\Onramp;

use App\Models\OnrampOrder;
use App\Services\Onramp\Providers\MoonPayProvider;
use App\Services\Onramp\Providers\OnramperProvider;
use App\Services\Onramp\Providers\RampProvider;
use App\Services\Onramp\Providers\TransakProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * The door into this wallet for somebody who holds a card and nothing else.
 *
 * One class in front of four providers, and the whole of what it adds over a
 * browser opening a provider's widget itself is four things:
 *
 *  - **Everyone is asked at once.** A purchase is priced by every provider
 *    that can serve it and the answers are ranked by what actually arrives —
 *    the payout, not the advertised fee, because a low fee on a bad rate is
 *    the oldest trick in this business. The losers stay on the screen with
 *    their prices, like the swap screen's beaten routes.
 *  - **A refusal keeps its reason.** `unconfigured`, `country_restricted`,
 *    `fiat_unsupported`, `no_quote` are four different sentences and the
 *    person reading them can act on three. A provider that simply vanished
 *    from the list would teach them nothing.
 *  - **The handoff is signed.** MoonPay refuses an unsigned URL carrying a
 *    wallet address, and the secret that signs it cannot be in a bundle.
 *  - **There is a row afterwards.** A purchase that ends on somebody else's
 *    page is one this app would otherwise never hear about again; the
 *    reference it mints travels into the provider's own partner-order field
 *    and comes back on a verified webhook.
 *
 * What it is not, and will not become: a custodian, a merchant of record, or
 * anything that sees a card. The buyer's money goes to the provider and the
 * crypto is delivered to an address derived in their own browser. If this host
 * disappeared mid-purchase the delivery would still land.
 */
class OnrampRouter
{
    /** @var array<string, class-string<OnrampProvider>> */
    private const DRIVERS = [
        'transak' => TransakProvider::class,
        'moonpay' => MoonPayProvider::class,
        'ramp' => RampProvider::class,
        'onramper' => OnramperProvider::class,
    ];

    public function enabled(): bool
    {
        return (bool) config('onramp.enabled', true);
    }

    /** @return array<int, OnrampProvider> */
    public function providers(): array
    {
        $made = [];

        foreach ((array) config('onramp.providers', []) as $name => $settings) {
            $driver = self::DRIVERS[$name] ?? null;

            if ($driver !== null && is_array($settings)) {
                $made[] = new $driver($name, $settings);
            }
        }

        return $made;
    }

    public function provider(string $name): ?OnrampProvider
    {
        foreach ($this->providers() as $provider) {
            if ($provider->name() === $name) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * What can be bought, where it lands, and who might sell it.
     *
     * Drawable before anybody types anything, and deliberately including the
     * providers that cannot serve at all — with the reason — because an
     * operator reading this endpoint is asking "what is left to switch on" and
     * a buyer is asking "why is there nothing here".
     */
    public function catalogue(?string $country = null): array
    {
        $country = $this->country($country);

        return [
            'enabled' => $this->enabled(),
            'country' => $country,
            'delivery' => $this->delivery(),
            'default_delivery' => (string) config('onramp.default_delivery', ''),
            'providers' => array_map(fn (OnrampProvider $provider) => [
                'key' => $provider->name(),
                'label' => $provider->label(),
                'kind' => $provider->kind(),
                'methods' => $provider->methods(),
                'fiat' => $provider->fiats(),
                'available' => $provider->configured()
                    && ! in_array($country ?? '', (array) config("onramp.providers.{$provider->name()}.restricted", []), true),
                'reason' => $this->catalogueReason($provider, $country),
                'tracking' => (string) config("onramp.providers.{$provider->name()}.tracking", 'none'),
                /*
                 * The partner fee as the operator declared it, marked as a
                 * declaration. It is applied inside the provider's own
                 * dashboard and this app cannot enforce it, so presenting it
                 * as ours would be claiming a number we do not control.
                 */
                'declared_fee_bps' => $provider->declaredFeeBps(),
            ], $this->providers()),
            // Мир, stated as a corridor rather than left off the screen.
            'mir' => [
                'url' => (string) config('onramp.mir.url', ''),
                'operator' => (string) config('onramp.mir.operator', ''),
                'available' => trim((string) config('onramp.mir.url', '')) !== '',
                'reason' => (string) config('onramp.mir.reason', 'mir_unserved'),
            ],
        ];
    }

    private function catalogueReason(OnrampProvider $provider, ?string $country): ?string
    {
        if (! $provider->configured()) {
            return (bool) config("onramp.providers.{$provider->name()}.enabled", false)
                ? 'unconfigured'
                : 'provider_off';
        }

        $restricted = (array) config("onramp.providers.{$provider->name()}.restricted", []);

        return $country !== null && in_array($country, $restricted, true)
            ? 'country_restricted'
            : null;
    }

    /**
     * Where a purchase may land, as the wallet's own chains and tickers.
     *
     * Not Cyberia — no on-ramp settles there — which is why every row here is
     * a chain this wallet can already read a balance on and the screen names
     * the second step onto Cyberia explicitly.
     *
     * @return array<int, array<string, mixed>>
     */
    public function delivery(): array
    {
        return array_values(array_map(fn (array $row) => [
            'key' => $row['chain'].':'.$row['asset'],
            'chain' => $row['chain'],
            'asset' => $row['asset'],
            'kind' => $row['kind'] ?? 'coin',
        ], (array) config('onramp.delivery', [])));
    }

    /** The delivery row for `base:USDC`, or null when nothing matches. */
    public function deliveryRow(string $key): ?array
    {
        foreach ((array) config('onramp.delivery', []) as $row) {
            if (($row['chain'] ?? '').':'.($row['asset'] ?? '') === $key) {
                return $row;
            }
        }

        return null;
    }

    public function request(array $input): ?OnrampRequest
    {
        $row = $this->deliveryRow((string) ($input['delivery'] ?? ''));

        if ($row === null) {
            return null;
        }

        return new OnrampRequest(
            fiat: strtoupper((string) $input['fiat']),
            amount: (string) $input['amount'],
            country: $this->country($input['country'] ?? null),
            method: (string) ($input['method'] ?? 'card'),
            chain: (string) $row['chain'],
            asset: (string) $row['asset'],
            address: (string) $input['address'],
            codes: (array) ($row['codes'] ?? []),
            networks: (array) ($row['networks'] ?? []),
        );
    }

    /**
     * Every provider's price for one purchase, best first.
     *
     * Ranked on `crypto_amount` — what actually arrives — and never on the fee
     * line, because a fee is only half of a price and the other half is the
     * rate. A provider that refused keeps its row and its reason.
     */
    public function quotes(OnrampRequest $request): array
    {
        $offers = [];

        foreach ($this->providers() as $provider) {
            $reason = $provider->unavailable($request);

            if ($reason !== null) {
                $offers[] = $this->offer($provider, ['ok' => false, 'reason' => $reason]);

                continue;
            }

            try {
                $quote = $provider->quote($request);
            } catch (Throwable) {
                // One provider's outage is not the screen's outage.
                $quote = ['ok' => false, 'reason' => 'provider_unreachable'];
            }

            $offers[] = $this->offer($provider, $quote);
        }

        usort($offers, function (array $a, array $b) {
            if ($a['available'] !== $b['available']) {
                return $a['available'] ? -1 : 1;
            }

            return bccomp((string) ($b['crypto_amount'] ?? '0'), (string) ($a['crypto_amount'] ?? '0'), 12);
        });

        if ($offers !== [] && $offers[0]['available']) {
            $offers[0]['best'] = true;
        }

        return $offers;
    }

    private function offer(OnrampProvider $provider, array $quote): array
    {
        return [
            'provider' => $provider->name(),
            'label' => $provider->label(),
            'kind' => $provider->kind(),
            'available' => (bool) ($quote['ok'] ?? false),
            'reason' => $quote['ok'] ?? false ? null : (string) ($quote['reason'] ?? 'no_quote'),
            'crypto_amount' => $quote['crypto_amount'] ?? null,
            'fiat_amount' => $quote['fiat_amount'] ?? null,
            'rate' => $quote['rate'] ?? null,
            'fee' => $quote['fee'] ?? null,
            'via' => $quote['via'] ?? null,
            'tracking' => (string) config("onramp.providers.{$provider->name()}.tracking", 'none'),
            'best' => false,
        ];
    }

    /**
     * Hand somebody over to a provider, and write the row that remembers it.
     *
     * The reference is minted here rather than taken from the browser: it is
     * the only thing connecting a webhook that arrives an hour later to this
     * purchase, and a caller-supplied id is a caller-supplied way to attach a
     * stranger's webhook to somebody else's order.
     */
    public function checkout(OnrampProvider $provider, OnrampRequest $request, ?int $userId = null): array
    {
        $reason = $provider->unavailable($request);

        if ($reason !== null) {
            return ['ok' => false, 'reason' => $reason];
        }

        $reference = (string) Str::uuid();
        $handoff = $provider->checkout($request, $reference);

        $order = OnrampOrder::query()->create([
            'reference' => $reference,
            'provider' => $provider->name(),
            'user_id' => $userId,
            'status' => 'pending',
            'fiat' => $request->fiat,
            'fiat_amount' => $request->amount,
            'chain' => $request->chain,
            'asset' => $request->asset,
            'address' => $request->address,
            'method' => $request->method,
        ]);

        return [
            'ok' => true,
            'reference' => $reference,
            'url' => (string) $handoff['url'],
            'provider' => $provider->name(),
            'tracking' => (string) config("onramp.providers.{$provider->name()}.tracking", 'none'),
            'order' => $order->present(),
        ];
    }

    /**
     * File what a provider said about an order it is running.
     *
     * Two rules. The row is found by *our* reference first and by the
     * provider's own id second, because the first is the one nothing else can
     * guess — and a webhook naming neither is dropped rather than creating a
     * row, since an order nobody started is not an order. And a delivered
     * order never goes back: providers re-send old events, and a "completed"
     * that becomes "pending" again on a replay is worse than a missed update.
     */
    public function record(OnrampProvider $provider, array $event): ?OnrampOrder
    {
        $order = OnrampOrder::query()
            ->where('provider', $provider->name())
            ->where(function ($query) use ($event) {
                $query->when(
                    is_string($event['reference'] ?? null),
                    fn ($q) => $q->orWhere('reference', $event['reference'])
                )->orWhere('provider_order_id', $event['order_id']);
            })
            ->first();

        if ($order === null) {
            return null;
        }

        if ($order->status === 'delivered' && $event['status'] !== 'delivered') {
            return $order;
        }

        $order->fill(array_filter([
            'provider_order_id' => $event['order_id'],
            'status' => $event['status'],
            'crypto_amount' => $event['crypto_amount'] ?? null,
            'tx_hash' => $event['tx'] ?? null,
        ], fn ($value) => $value !== null));

        if ($event['status'] === 'delivered' && $order->delivered_at === null) {
            $order->delivered_at = now();
        }

        $order->save();

        return $order;
    }

    /**
     * The country as two upper-case letters, or null.
     *
     * Null is a real answer and not a default: this app does not geolocate
     * anybody, so where the browser did not say, every provider is offered and
     * the provider's own check is the one that decides.
     */
    private function country(mixed $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }
}
