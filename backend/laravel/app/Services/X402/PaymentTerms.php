<?php

namespace App\Services\X402;

use RuntimeException;

/**
 * The terms this server offers, and the encoding they travel in.
 *
 * Pure and free of HTTP: what a resource costs, in which asset, on which
 * network, to whom — plus the base64 that x402 v2 wraps its headers in. The
 * paywall composes; this states.
 *
 * One rule holds the whole design up: the requirements sent to the facilitator
 * are always **built here**, never read back from the caller. A payload
 * carries an `accepted` copy of the terms it was signed against, and trusting
 * that copy would let a payer verify their own one-cent authorization against
 * their own one-cent terms while asking for a resource priced at a dollar.
 */
class PaymentTerms
{
    /** x402 v2. v1 spelled its networks "base" and its headers X-PAYMENT. */
    public const VERSION = 2;

    /** Is the paywall switched on *and* able to name a payee and a facilitator. */
    public function usable(): bool
    {
        return (bool) config('x402.enabled') && $this->missing() === [];
    }

    /**
     * Which required settings are absent, for the operator rather than the caller.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        foreach (['x402.pay_to', 'x402.facilitator.url', 'x402.network', 'x402.asset.address'] as $key) {
            if (trim((string) config($key)) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * One entry of a 402's `accepts` array.
     *
     * @return array<string, mixed>
     */
    public function requirements(string $price): array
    {
        return [
            'scheme' => (string) config('x402.scheme', 'exact'),
            'network' => (string) config('x402.network'),
            'amount' => $this->atomic($price),
            'asset' => (string) config('x402.asset.address'),
            'payTo' => (string) config('x402.pay_to'),
            'maxTimeoutSeconds' => max(1, (int) config('x402.max_timeout_seconds', 120)),
            // The token's EIP-712 domain. The client signs against exactly
            // these two strings, so they are part of the offer, not decoration.
            'extra' => [
                'name' => (string) config('x402.asset.name'),
                'version' => (string) config('x402.asset.version'),
            ],
        ];
    }

    /**
     * The complete PaymentRequired document that goes into the header.
     *
     * @return array<string, mixed>
     */
    public function required(string $url, string $price, string $description, ?string $error = null): array
    {
        $resource = [
            'url' => $url,
            'description' => $description,
            'mimeType' => 'application/json',
        ];

        if (($name = trim((string) config('x402.resource.service_name'))) !== '') {
            $resource['serviceName'] = mb_substr($name, 0, 32);
        }

        if (($tags = array_values(array_filter((array) config('x402.resource.tags', [])))) !== []) {
            $resource['tags'] = array_slice($tags, 0, 5);
        }

        if (($icon = trim((string) config('x402.resource.icon_url'))) !== '') {
            $resource['iconUrl'] = $icon;
        }

        return array_filter([
            'x402Version' => self::VERSION,
            'error' => $error,
            'resource' => $resource,
            'accepts' => [$this->requirements($price)],
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Whole units to atomic units: "0.01" of a 6-decimal token is "10000".
     *
     * A leading "$" is accepted because that is how x402's own configuration
     * writes prices, and because our asset is a dollar stablecoin — it is a
     * spelling of the amount, never a currency conversion.
     */
    public function atomic(string $price): string
    {
        $price = trim(ltrim(trim($price), '$'));

        if (! preg_match('/^\d+(\.\d+)?$/', $price)) {
            throw new RuntimeException("x402 price [{$price}] is not a plain decimal amount.");
        }

        $decimals = max(0, (int) config('x402.asset.decimals', 6));
        $atomic = bcmul($price, bcpow('10', (string) $decimals), 0);

        if (bccomp($atomic, '0') <= 0) {
            throw new RuntimeException(
                "x402 price [{$price}] is below one atomic unit of a {$decimals}-decimal asset."
            );
        }

        return $atomic;
    }

    /** Atomic units back to a human amount, for messages and receipts. */
    public function human(string $atomic): string
    {
        $decimals = max(0, (int) config('x402.asset.decimals', 6));
        $whole = bcdiv($atomic, bcpow('10', (string) $decimals), $decimals);

        return $decimals === 0 ? $whole : rtrim(rtrim($whole, '0'), '.');
    }

    /** What a caller pays for one call of this resource. */
    public function priceFor(string $model): string
    {
        $overrides = (array) config('x402.ai.models', []);

        return trim((string) ($overrides[$model] ?? config('x402.ai.price', '0.01')));
    }

    /** @param array<string, mixed> $document */
    public function encode(array $document): string
    {
        return base64_encode((string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A header back into a document, or null if it is not one.
     *
     * Callers hand this arbitrary bytes, so every failure mode — not base64,
     * not JSON, not an object — has to be the same quiet null rather than an
     * exception that would render as a 500 for what is a client mistake.
     *
     * @return array<string, mixed>|null
     */
    public function decode(?string $header): ?array
    {
        $header = trim((string) $header);

        if ($header === '') {
            return null;
        }

        $json = base64_decode($header, true);

        if ($json === false) {
            return null;
        }

        $document = json_decode($json, true);

        return is_array($document) && $document !== [] ? $document : null;
    }
}
