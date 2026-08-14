<?php

namespace App\Services\Ai;

use App\Exceptions\AiProviderException;
use App\Services\Ai\Providers\AiProviderRegistry;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * One completion, from a catalogue model to an answer.
 *
 * The gateway is where "which model" stops being the caller's word and becomes
 * a provider and an upstream id, and where a failure that another model could
 * survive turns into a second attempt instead of an error. It is followed at
 * most once: a chain of fallbacks would make a slow failure look like a hang.
 *
 * Whatever answers, the response says the model the caller asked for — with
 * `served_by` naming what actually replied when a fallback did. Rewriting the
 * id keeps a pinned model id meaningful; hiding the substitution entirely
 * would not.
 */
class AiGateway
{
    public function __construct(
        private AiModelCatalog $catalog,
        private AiProviderRegistry $providers,
    ) {}

    /**
     * @param  array{id: string, provider: string, upstream: string, fallback: ?string}  $model
     * @return array{body: array<string, mixed>, model: string, served: string, provider: string}
     */
    public function complete(array $model, AiChatPayload $payload): array
    {
        $attempts = $this->attempts($model);
        $requested = $model['id'];

        foreach ($attempts as $index => $attempt) {
            try {
                $body = $this->providers->get($attempt['provider'])->chat($attempt['upstream'], $payload->body);

                return [
                    'body' => $this->rewrite($body, $requested, $attempt),
                    'model' => $requested,
                    'served' => $attempt['id'],
                    'provider' => $attempt['provider'],
                ];
            } catch (AiProviderException $e) {
                $this->logFailure($e, $requested, $attempt['id']);

                if (! $e->allowsFallback || $index === array_key_last($attempts)) {
                    throw $e;
                }
            }
        }

        // Unreachable: the loop either returns or rethrows on the last attempt.
        throw new \LogicException('No inference attempt was made.');
    }

    /**
     * The same call as a stream of chunks, already rewritten.
     *
     * A fallback here is only possible before the first chunk reaches the
     * caller. Once one has, the answer has started and the only honest thing
     * to do with a mid-stream failure is to end the stream with an error.
     *
     * @param  array{id: string, provider: string, upstream: string, fallback: ?string}  $model
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(array $model, AiChatPayload $payload): Generator
    {
        $attempts = $this->attempts($model);
        $requested = $model['id'];

        foreach ($attempts as $index => $attempt) {
            $delivered = false;

            try {
                foreach ($this->providers->get($attempt['provider'])->stream($attempt['upstream'], $payload->body) as $chunk) {
                    $delivered = true;

                    yield $this->rewrite($chunk, $requested, $attempt);
                }

                return;
            } catch (AiProviderException $e) {
                $this->logFailure($e, $requested, $attempt['id']);

                if ($delivered || ! $e->allowsFallback || $index === array_key_last($attempts)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The model to try, then the one to try if it fails.
     *
     * @param  array{id: string, provider: string, upstream: string, fallback: ?string}  $model
     * @return list<array{id: string, provider: string, upstream: string, fallback: ?string}>
     */
    private function attempts(array $model): array
    {
        $fallback = $this->catalog->fallbackFor($model);

        return $fallback === null ? [$model] : [$model, $fallback];
    }

    /**
     * The provider's body, wearing this API's model id.
     *
     * @param  array<string, mixed>  $body
     * @param  array{id: string, provider: string, upstream: string}  $attempt
     * @return array<string, mixed>
     */
    private function rewrite(array $body, string $requested, array $attempt): array
    {
        $body['model'] = $requested;
        $body['provider'] = $attempt['provider'];

        if ($attempt['id'] !== $requested) {
            $body['served_by'] = $attempt['id'];
        }

        return $body;
    }

    private function logFailure(AiProviderException $e, string $requested, string $served): void
    {
        Log::warning('AI API upstream failure', [
            'requested_model' => $requested,
            'attempted_model' => $served,
            'provider' => $e->provider,
            'upstream_model' => $e->upstreamModel,
            'upstream_status' => $e->upstreamStatus,
            'code' => $e->errorCode,
        ]);
    }
}
