<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\AiProviderException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Everything shared by providers that speak OpenAI's chat-completions dialect.
 *
 * Providers in this family differ in their base URL, authentication header
 * and a small request policy. A provider that needs a different wire format
 * implements AiProvider directly rather than bending this one.
 */
abstract class OpenAiCompatibleProvider implements AiProvider
{
    /** Short waits for the failures that are usually over by the next attempt. */
    private const RETRY_DELAYS_MS = [250, 750];

    public function __construct(
        private readonly string $name,
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly bool $enabled = true,
        private readonly bool $requiresApiKey = true,
        private readonly string $authHeader = 'Authorization',
        private readonly string $authPrefix = 'Bearer ',
        private readonly array $providerHeaders = [],
        private readonly string $maxTokensField = 'max_tokens',
        private readonly array $dropFields = [],
        private readonly bool $stripMessageNames = false,
    ) {}

    public function key(): string
    {
        return $this->name;
    }

    public function configured(): bool
    {
        return $this->enabled
            && trim($this->baseUrl) !== ''
            && (! $this->requiresApiKey || (is_string($this->apiKey) && trim($this->apiKey) !== ''));
    }

    /** Attribution and other per-provider headers. */
    protected function headers(): array
    {
        return $this->providerHeaders;
    }

    public function chat(string $upstreamModel, array $payload): array
    {
        $response = $this->request()
            ->retry(
                self::RETRY_DELAYS_MS,
                when: fn (Throwable $e): bool => $this->transient($e),
                throw: false,
            )
            // `+` keeps the left-hand keys, so neither the model nor the
            // streaming decision can be overridden by what the caller sent.
            ->post($this->endpoint(), ['model' => $upstreamModel, 'stream' => false] + $this->payload($payload));

        if (! $response->successful()) {
            throw $this->failure($response, $upstreamModel);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw $this->malformed($upstreamModel, 'The provider returned a body that is not JSON.');
        }

        // OpenRouter reports some provider-side failures inside a 200.
        if (is_string(data_get($body, 'error.message'))) {
            throw new AiProviderException(
                provider: $this->name,
                upstreamModel: $upstreamModel,
                upstreamStatus: 200,
                allowsFallback: true,
                status: 502,
                type: 'api_error',
                errorCode: 'provider_error',
                message: (string) data_get($body, 'error.message'),
            );
        }

        if (! is_array($body['choices'] ?? null)) {
            throw $this->malformed($upstreamModel, 'The provider returned no choices.');
        }

        return $body;
    }

    public function stream(string $upstreamModel, array $payload): Generator
    {
        // No retry here on purpose: a stream that failed halfway has already
        // handed the caller part of an answer, and a second attempt would
        // continue it with the beginning of a different one.
        try {
            $response = $this->request()
                ->withOptions(['stream' => true])
                ->post($this->endpoint(), ['model' => $upstreamModel, 'stream' => true] + $this->payload($payload));
        } catch (ConnectionException $e) {
            throw $this->unreachable($upstreamModel, $e);
        }

        if (! $response->successful()) {
            throw $this->failure($response, $upstreamModel);
        }

        foreach ($this->frames($response) as $frame) {
            if (is_string(data_get($frame, 'error.message'))) {
                throw new AiProviderException(
                    provider: $this->name,
                    upstreamModel: $upstreamModel,
                    upstreamStatus: 200,
                    // Mid-stream: bytes may already be on the wire, so the
                    // gateway must not silently start a second answer.
                    allowsFallback: false,
                    status: 502,
                    type: 'api_error',
                    errorCode: 'provider_error',
                    message: (string) data_get($frame, 'error.message'),
                );
            }

            yield $frame;
        }
    }

    /**
     * The decoded object from every `data:` frame, in order, stopping at the
     * `[DONE]` sentinel.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function frames(Response $response): Generator
    {
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($break = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $break), "\r");
                $buffer = substr($buffer, $break + 1);

                if (! str_starts_with($line, 'data:')) {
                    // Comments (`: ping`) and event/id fields: keep-alive noise.
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '[DONE]') {
                    return;
                }

                $decoded = json_decode($data, true);

                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::withHeaders($this->headers())
            ->connectTimeout(10)
            ->timeout((int) config('ai.timeout_seconds', 120));

        if (is_string($this->apiKey) && trim($this->apiKey) !== '') {
            $request = $request->withHeader($this->authHeader, $this->authPrefix.$this->apiKey);
        }

        return $request;
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/').'/chat/completions';
    }

    /**
     * Apply the small transport differences exposed by OpenAI-compatible APIs.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payload(array $payload): array
    {
        foreach ($this->dropFields as $field) {
            unset($payload[$field]);
        }

        if ($this->stripMessageNames && is_array($payload['messages'] ?? null)) {
            $payload['messages'] = array_map(function ($message) {
                if (is_array($message)) {
                    unset($message['name']);
                }

                return $message;
            }, $payload['messages']);
        }

        if ($this->maxTokensField === 'max_completion_tokens' && array_key_exists('max_tokens', $payload)) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens']);
        }

        return $payload;
    }

    private function transient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && in_array($e->response->status(), [408, 429, 500, 502, 503, 504], true);
    }

    /**
     * An upstream refusal, translated.
     *
     * The caller is told what happened in their own vocabulary — their key is
     * fine, ours is the one upstream rejected — so a 401 from Groq surfaces as
     * a server-side problem (502) and never as "your key is invalid", which
     * would send them to rotate a key that works.
     */
    private function failure(Response $response, string $upstreamModel): AiProviderException
    {
        $status = $response->status();
        $upstreamMessage = data_get($response->json(), 'error.message');

        [$outStatus, $type, $code, $message] = match (true) {
            $status === 400 => [400, 'invalid_request_error', 'provider_rejected_request', is_string($upstreamMessage) ? $upstreamMessage : 'The provider rejected this request.'],
            $status === 401, $status === 403 => [502, 'api_error', 'provider_unauthorized', 'This server’s provider credentials were rejected upstream.'],
            $status === 404 => [502, 'api_error', 'model_unavailable', 'The provider no longer serves this model.'],
            $status === 408 => [504, 'api_error', 'upstream_timeout', 'The provider timed out.'],
            $status === 413 => [400, 'invalid_request_error', 'context_too_long', is_string($upstreamMessage) ? $upstreamMessage : 'This request is longer than the model accepts.'],
            $status === 422 => [400, 'invalid_request_error', 'provider_rejected_request', is_string($upstreamMessage) ? $upstreamMessage : 'The provider rejected this request.'],
            $status === 429 => [429, 'rate_limit_error', 'upstream_rate_limited', 'The provider is rate-limiting this server. Try again shortly.'],
            $status >= 500 => [502, 'api_error', 'upstream_error', 'The provider failed to answer.'],
            default => [502, 'api_error', 'upstream_error', 'The provider refused this request.'],
        };

        return new AiProviderException(
            provider: $this->name,
            upstreamModel: $upstreamModel,
            upstreamStatus: $status,
            allowsFallback: in_array($status, [401, 403, 404, 408, 429, 500, 502, 503, 504], true),
            status: $outStatus,
            type: $type,
            errorCode: $code,
            message: $message,
        );
    }

    private function unreachable(string $upstreamModel, Throwable $previous): AiProviderException
    {
        return new AiProviderException(
            provider: $this->name,
            upstreamModel: $upstreamModel,
            upstreamStatus: 0,
            allowsFallback: true,
            status: 502,
            type: 'api_error',
            errorCode: 'provider_unreachable',
            message: 'The provider could not be reached.',
            previous: $previous,
        );
    }

    private function malformed(string $upstreamModel, string $message): AiProviderException
    {
        return new AiProviderException(
            provider: $this->name,
            upstreamModel: $upstreamModel,
            upstreamStatus: 200,
            allowsFallback: true,
            status: 502,
            type: 'api_error',
            errorCode: 'provider_error',
            message: $message,
        );
    }
}
