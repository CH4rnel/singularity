<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\AiApiException;

/**
 * The providers this server can reach, by name.
 *
 * Instances are built once per registry so a request that falls back from one
 * model to another on the same provider does not rebuild the client. Providers
 * read their configuration in the constructor, which is why the registry is
 * resolved per request rather than held as a singleton — a test that rewrites
 * `config('ai.providers.*')` must be able to see its own change.
 */
class AiProviderRegistry
{
    /** @var array<string, AiProvider> */
    private array $instances = [];

    /** @var array<string, class-string<AiProvider>> */
    private const PROVIDERS = [
        'openrouter' => OpenRouterProvider::class,
        'groq' => GroqProvider::class,
    ];

    public function get(string $name): AiProvider
    {
        $provider = $this->instances[$name] ?? null;

        if ($provider === null) {
            $class = self::PROVIDERS[$name] ?? null;

            if ($class === null) {
                throw AiApiException::upstream(
                    sprintf('Unknown inference provider "%s".', $name),
                    'unknown_provider',
                    500,
                );
            }

            $provider = $this->instances[$name] = new $class;
        }

        return $provider;
    }

    /** Whether a provider exists and holds a key on this server. */
    public function configured(string $name): bool
    {
        return isset(self::PROVIDERS[$name]) && $this->get($name)->configured();
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_filter(
            array_keys(self::PROVIDERS),
            fn (string $name): bool => $this->configured($name),
        ));
    }
}
