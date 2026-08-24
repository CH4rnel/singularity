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
    private const SPECIAL_PROVIDERS = [
        'openrouter' => OpenRouterProvider::class,
        'groq' => GroqProvider::class,
    ];

    public function get(string $name): AiProvider
    {
        $provider = $this->instances[$name] ?? null;

        if ($provider === null) {
            $class = self::SPECIAL_PROVIDERS[$name] ?? null;
            $config = config("ai.providers.{$name}");

            if ($class !== null) {
                $provider = new $class;
            } elseif (is_array($config)) {
                $provider = new ConfiguredOpenAiProvider($name, $config);
            } else {
                throw AiApiException::upstream(
                    sprintf('Unknown inference provider "%s".', $name),
                    'unknown_provider',
                    500,
                );
            }

            $this->instances[$name] = $provider;
        }

        return $provider;
    }

    /** Whether a provider exists and holds a key on this server. */
    public function configured(string $name): bool
    {
        return array_key_exists($name, (array) config('ai.providers', []))
            && $this->get($name)->configured();
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_filter(
            array_keys((array) config('ai.providers', [])),
            fn (string $name): bool => $this->configured($name),
        ));
    }
}
