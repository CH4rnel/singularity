<?php

namespace App\Services\Ai;

use App\Exceptions\AiApiException;
use App\Services\Ai\Providers\AiProviderRegistry;

/**
 * The models this API is willing to serve, and who serves each one.
 *
 * An allowlist rather than a passthrough to the providers' own catalogues:
 * the upstream account being spent is Cyberia's, so an unknown model id is an
 * error here instead of a bill there. Ids are ours (`lain-fast`), which means
 * an upstream model can be swapped or repointed without breaking a caller who
 * pinned one.
 *
 * A model whose provider has no key configured is not listed and cannot be
 * requested — offering a model that is certain to fail is worse than not
 * offering it.
 */
class AiModelCatalog
{
    public function __construct(private AiProviderRegistry $providers) {}

    /**
     * Every servable model, in configured order.
     *
     * @return list<array{id: string, label: string, provider: string, upstream: string, context: int, fallback: ?string}>
     */
    public function models(): array
    {
        $models = [];

        foreach ((array) config('ai.models', []) as $entry) {
            $model = $this->normalise(is_array($entry) ? $entry : []);

            if ($model !== null && $this->providers->configured($model['provider'])) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /** @return array{id: string, label: string, provider: string, upstream: string, context: int, fallback: ?string}|null */
    public function find(string $id): ?array
    {
        foreach ($this->models() as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
        }

        return null;
    }

    /**
     * The model a request asked for, or the configured default when it named
     * none. An unknown id names what is available, because a caller who
     * mistyped a model has no other way to discover the right one.
     *
     * @return array{id: string, label: string, provider: string, upstream: string, context: int, fallback: ?string}
     */
    public function resolve(?string $id): array
    {
        $models = $this->models();

        if ($models === []) {
            throw AiApiException::upstream(
                'No inference provider is configured on this server.',
                'no_provider',
                503,
            );
        }

        $requested = trim((string) $id);

        if ($requested === '') {
            $requested = (string) config('ai.default_model');
        }

        $model = $this->find($requested);

        if ($model !== null) {
            return $model;
        }

        // The default may itself point at a provider that has no key. Rather
        // than answering "model not found" for a model the caller never named,
        // fall through to whatever is actually servable.
        if ($requested === (string) config('ai.default_model')) {
            return $models[0];
        }

        throw AiApiException::invalidRequest(
            sprintf(
                'Unknown model "%s". Available: %s.',
                $requested,
                implode(', ', array_column($models, 'id')),
            ),
            'model',
            'model_not_found',
        );
    }

    /**
     * The model to retry on when $model fails in a survivable way, if any.
     *
     * @param  array{fallback: ?string}  $model
     * @return array{id: string, label: string, provider: string, upstream: string, context: int, fallback: ?string}|null
     */
    public function fallbackFor(array $model): ?array
    {
        return $model['fallback'] === null ? null : $this->find($model['fallback']);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{id: string, label: string, provider: string, upstream: string, context: int, fallback: ?string}|null
     */
    private function normalise(array $entry): ?array
    {
        $id = trim((string) ($entry['id'] ?? ''));
        $provider = trim((string) ($entry['provider'] ?? ''));
        $upstream = trim((string) ($entry['upstream'] ?? ''));

        if ($id === '' || $provider === '' || $upstream === '') {
            return null;
        }

        $fallback = $entry['fallback'] ?? null;

        return [
            'id' => $id,
            'label' => (string) ($entry['label'] ?? $id),
            'provider' => $provider,
            'upstream' => $upstream,
            'context' => (int) ($entry['context'] ?? 0),
            // A model that falls back to itself would retry the same failure.
            'fallback' => is_string($fallback) && $fallback !== '' && $fallback !== $id ? $fallback : null,
        ];
    }
}
