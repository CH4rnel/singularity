<?php

namespace App\Services\Onramp;

/**
 * One purchase, as every provider is asked about it.
 *
 * A value object rather than an array because this is the thing four different
 * dialects are translated from, and a typo in a key would otherwise be a
 * provider quietly quoting the wrong asset. The amount is a decimal *string*
 * for the usual reason: money in a float is money that rounds.
 *
 * `address` is the user's own, derived in their browser, and it is the only
 * part of this that is theirs — everything else is a description of what they
 * want. Nothing here identifies a person; the provider does its own KYC and
 * this app never sees the result.
 */
readonly class OnrampRequest
{
    public function __construct(
        /** ISO 4217, upper case. */
        public string $fiat,
        /** What they are spending, as typed. */
        public string $amount,
        /** ISO 3166-1 alpha-2, or null when the browser did not say. */
        public ?string $country,
        /** `card`, `bank`, `apple_pay`, `google_pay`, `paypal`. */
        public string $method,
        /** The wallet's own chain id, e.g. `base`. */
        public string $chain,
        /** The ticker as the wallet draws it, e.g. `USDC`. */
        public string $asset,
        /** Where it is delivered. The user's address on `chain`. */
        public string $address,
        /** Per-provider names for this pair, from `config('onramp.delivery')`. */
        public array $codes = [],
        /** Per-provider names for the *network*, where it is a separate field. */
        public array $networks = [],
    ) {}

    /** What this provider calls the asset, or the plain ticker if it has no dialect. */
    public function code(string $provider): string
    {
        $code = $this->codes[$provider] ?? null;

        return is_string($code) && $code !== '' ? $code : $this->asset;
    }

    /**
     * What this provider calls the network.
     *
     * Separate from `code()` because the four dialects disagree about where
     * the network lives: Transak takes it as its own parameter, MoonPay folds
     * it into the currency code (`usdc_base`), Ramp prefixes the asset
     * (`BASE_USDC`). A row that has no separate name for it falls back to the
     * wallet's own chain id, which is right for the chains named after
     * themselves and wrong silently for nothing.
     */
    public function network(string $provider): string
    {
        $network = $this->networks[$provider] ?? null;

        return is_string($network) && $network !== '' ? $network : $this->chain;
    }

    public function key(): string
    {
        return $this->chain.':'.$this->asset;
    }
}
