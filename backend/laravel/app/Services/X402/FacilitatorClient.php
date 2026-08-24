<?php

namespace App\Services\X402;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The facilitator, as this server sees it: two POSTs and a GET.
 *
 * `/verify` asks whether an authorization is good — a read, free, and the only
 * thing that happens before the work. `/settle` broadcasts it and waits for the
 * chain. `/supported` is what it will do at all, which is how `x402:check`
 * tells an operator that the network they configured is one this facilitator
 * actually serves.
 *
 * Every failure is reported in the protocol's own vocabulary rather than as an
 * exception: an unreachable facilitator is `isValid: false` with a reason, so
 * the paywall has exactly one shape to handle and a caller is never charged
 * for a request that the server then refuses to serve.
 */
class FacilitatorClient
{
    /**
     * @param  array<string, mixed>  $payload  the caller's PaymentPayload
     * @param  array<string, mixed>  $requirements  our own PaymentRequirements
     * @return array{isValid: bool, invalidReason: ?string, payer: ?string}
     */
    public function verify(array $payload, array $requirements): array
    {
        $response = $this->post('verify', $payload, $requirements, (int) config('x402.facilitator.verify_timeout', 15));

        if ($response === null) {
            return ['isValid' => false, 'invalidReason' => 'facilitator_unreachable', 'payer' => null];
        }

        return [
            'isValid' => (bool) ($response['isValid'] ?? false),
            'invalidReason' => $this->text($response['invalidReason'] ?? null),
            'payer' => $this->text($response['payer'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $requirements
     * @return array{success: bool, errorReason: ?string, payer: ?string, transaction: ?string, network: ?string, amount: ?string}
     */
    public function settle(array $payload, array $requirements): array
    {
        $response = $this->post('settle', $payload, $requirements, (int) config('x402.facilitator.settle_timeout', 60));

        if ($response === null) {
            return [
                'success' => false,
                'errorReason' => 'facilitator_unreachable',
                'payer' => null,
                'transaction' => null,
                'network' => $requirements['network'] ?? null,
                'amount' => null,
            ];
        }

        return [
            'success' => (bool) ($response['success'] ?? false),
            'errorReason' => $this->text($response['errorReason'] ?? null),
            'payer' => $this->text($response['payer'] ?? null),
            'transaction' => $this->text($response['transaction'] ?? null),
            'network' => $this->text($response['network'] ?? null) ?? ($requirements['network'] ?? null),
            'amount' => $this->text($response['amount'] ?? null),
        ];
    }

    /**
     * What this facilitator will accept, for the operator's check command.
     *
     * @return array{ok: bool, error: ?string, kinds: list<array<string, mixed>>, extensions: list<mixed>, signers: array<string, mixed>}
     */
    public function supported(): array
    {
        $empty = ['kinds' => [], 'extensions' => [], 'signers' => []];

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout((int) config('x402.facilitator.verify_timeout', 15))
                ->acceptJson()
                ->get($this->url('supported'));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()] + $empty;
        }

        if ($response->failed()) {
            return ['ok' => false, 'error' => "HTTP {$response->status()}"] + $empty;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ['ok' => false, 'error' => 'the facilitator did not answer with JSON'] + $empty;
        }

        return [
            'ok' => true,
            'error' => null,
            'kinds' => array_values(array_filter((array) ($body['kinds'] ?? []), 'is_array')),
            'extensions' => array_values((array) ($body['extensions'] ?? [])),
            'signers' => (array) ($body['signers'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $requirements
     * @return array<string, mixed>|null
     */
    private function post(string $path, array $payload, array $requirements, int $timeout): ?array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(max(1, $timeout))
                ->acceptJson()
                ->post($this->url($path), [
                    'x402Version' => PaymentTerms::VERSION,
                    'paymentPayload' => $payload,
                    'paymentRequirements' => $requirements,
                ]);
        } catch (Throwable $e) {
            Log::warning('x402 facilitator could not be reached', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            Log::warning('x402 facilitator answered with something other than JSON', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        }

        // A 4xx carrying a reason is an answer, not an outage: facilitators
        // report an invalid authorization that way, and the caller deserves
        // the reason rather than "unreachable".
        if ($response->failed() && ! isset($body['invalidReason'], $body['errorReason']) && ! isset($body['isValid']) && ! isset($body['success'])) {
            Log::warning('x402 facilitator refused the call', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $body;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $authorization = trim((string) config('x402.facilitator.authorization'));

        return $authorization === '' ? [] : ['Authorization' => $authorization];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('x402.facilitator.url'), '/').'/'.$path;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
