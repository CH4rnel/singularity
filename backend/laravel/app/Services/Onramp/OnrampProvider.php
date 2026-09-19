<?php

namespace App\Services\Onramp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * What every on-ramp has to be able to answer.
 *
 * Four providers, four dialects, and the differences between them are real:
 * one signs its handoff URL, one refuses to state a price without a key, one
 * is itself an aggregator of thirty others. This class is where those stop
 * mattering — above it there is one vocabulary, and a fifth provider is a
 * subclass plus a row in `config/onramp.php`.
 *
 * The three questions, in the order a screen asks them:
 *
 *   `unavailable()` — can this provider serve this purchase at all? Answered
 *   from config alone, without a network call, because the answer has to be
 *   drawable before anybody types an amount. It returns a *reason*, never a
 *   bare false: "we have not configured it" and "it refuses this country" are
 *   different sentences and a user deserves whichever one is true.
 *
 *   `quote()` — what would this cost? A real call, cached never.
 *
 *   `checkout()` — a URL to hand somebody, on the provider's own origin,
 *   carrying the destination address and our partner id, signed where the
 *   provider demands a signature.
 *
 * And afterwards `webhook()`, which is how an order that finished somewhere
 * else becomes a row here. A provider with no verifiable webhook is not a
 * provider this app will believe: an unsigned status update is an invitation
 * to write "delivered" on anything.
 */
abstract class OnrampProvider
{
    /** One vocabulary for four providers' worth of status names. */
    public const STATUSES = ['pending', 'paid', 'delivering', 'delivered', 'failed', 'expired'];

    public function __construct(
        protected readonly string $name,
        protected readonly array $config,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? $this->name);
    }

    public function kind(): string
    {
        return (string) ($this->config['kind'] ?? 'widget');
    }

    /** @return array<int, string> */
    public function methods(): array
    {
        return array_values((array) ($this->config['methods'] ?? []));
    }

    /** @return array<int, string> */
    public function fiats(): array
    {
        return array_values((array) ($this->config['fiat'] ?? []));
    }

    /**
     * Switched on, and holding what it needs to work.
     *
     * A signing provider without its secret counts as unconfigured rather than
     * as configured-and-broken: an unsigned handoff to MoonPay is refused at
     * the far end, and finding that out after a redirect is worse than not
     * offering the route.
     */
    public function configured(): bool
    {
        if (! (bool) ($this->config['enabled'] ?? false)) {
            return false;
        }

        if (trim((string) ($this->config['key'] ?? '')) === '') {
            return false;
        }

        return $this->config['signing'] === null
            || trim((string) ($this->config['secret'] ?? '')) !== '';
    }

    /**
     * Why this provider cannot serve this purchase, or null when it can.
     *
     * Config only — no network. The provider's own API remains the authority
     * at quote time; this is the part that can be said early, and saying it
     * early is the difference between a greyed row with a sentence under it
     * and a redirect into somebody else's refusal page.
     */
    public function unavailable(OnrampRequest $request): ?string
    {
        if (! (bool) ($this->config['enabled'] ?? false)) {
            return 'provider_off';
        }

        if (! $this->configured()) {
            return 'unconfigured';
        }

        $country = strtoupper((string) $request->country);

        if ($country !== '' && in_array($country, (array) ($this->config['restricted'] ?? []), true)) {
            return 'country_restricted';
        }

        if ($this->fiats() !== [] && ! in_array(strtoupper($request->fiat), $this->fiats(), true)) {
            return 'fiat_unsupported';
        }

        if ($this->methods() !== [] && ! in_array($request->method, $this->methods(), true)) {
            return 'method_unsupported';
        }

        return null;
    }

    /**
     * The fee the operator says they configured in this provider's dashboard.
     *
     * A disclosure and not an instruction: unlike the cross-chain router,
     * whose fee is a field this app writes into the request, an on-ramp
     * partner fee is applied inside the provider and cannot be composed from
     * here. Clamped anyway, because a number printed to a user is a promise
     * and an env typo is how a promise becomes 30%.
     */
    public function declaredFeeBps(): int
    {
        $asked = (int) ($this->config['fee']['declared_bps'] ?? 0);
        $ceiling = (int) ($this->config['fee']['max_bps'] ?? 500);

        return max(0, min($asked, $ceiling));
    }

    /** What this provider charges, priced for real. */
    abstract public function quote(OnrampRequest $request): array;

    /**
     * A URL to hand the buyer, and the reference this app will recognise it by.
     *
     * The reference is ours and travels into the provider's own "partner order
     * id" field, because a webhook that arrives an hour later has to find its
     * row without trusting anything the browser could have changed.
     */
    abstract public function checkout(OnrampRequest $request, string $reference): array;

    /**
     * What a provider just told us, verified, in this app's own vocabulary.
     *
     * Null means the signature did not check out or the payload was not one we
     * understand — and the caller answers 202 either way, because telling a
     * stranger which of those it was is telling them how to try again.
     *
     * @return array{reference: ?string, order_id: string, status: string, crypto_amount: ?string, fiat_amount: ?string, tx: ?string}|null
     */
    public function webhook(Request $http): ?array
    {
        return null;
    }

    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('onramp.timeout', 15))
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'cyberia-wallet']);
    }

    protected function api(): string
    {
        return rtrim((string) ($this->config['api'] ?? ''), '/');
    }

    protected function widget(): string
    {
        return rtrim((string) ($this->config['widget'] ?? ''), '/');
    }

    protected function key(): string
    {
        return trim((string) ($this->config['key'] ?? ''));
    }

    protected function secret(): string
    {
        return trim((string) ($this->config['secret'] ?? ''));
    }

    /**
     * A number as a string, with the places the asset actually has.
     *
     * Providers answer in JSON numbers — `0.1 + 0.2` numbers — and everything
     * downstream of this compares and prints strings.
     */
    protected function amount(mixed $value, int $places = 8): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, $places, '.', ''), '0'), '.') ?: '0';
    }

    /** @return array{ok: false, reason: string} */
    protected function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
