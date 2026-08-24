<?php

namespace App\Services\Ai\Providers;

/**
 * One declaratively configured OpenAI-compatible upstream.
 *
 * Most inference vendors intentionally expose the same Chat Completions wire
 * format. Keeping their transport facts in config makes adding or retiring a
 * vendor an allowlist change instead of another client implementation.
 */
class ConfiguredOpenAiProvider extends OpenAiCompatibleProvider
{
    /** @param  array<string, mixed>  $config */
    public function __construct(string $name, array $config)
    {
        parent::__construct(
            name: $name,
            apiKey: is_string($config['api_key'] ?? null) ? $config['api_key'] : null,
            baseUrl: (string) ($config['base_url'] ?? ''),
            enabled: (bool) ($config['enabled'] ?? true),
            requiresApiKey: (bool) ($config['requires_api_key'] ?? true),
            authHeader: (string) ($config['auth_header'] ?? 'Authorization'),
            authPrefix: (string) ($config['auth_prefix'] ?? 'Bearer '),
            providerHeaders: is_array($config['headers'] ?? null) ? $config['headers'] : [],
            maxTokensField: (string) ($config['max_tokens_field'] ?? 'max_tokens'),
            dropFields: is_array($config['drop_fields'] ?? null) ? $config['drop_fields'] : [],
            stripMessageNames: (bool) ($config['strip_message_names'] ?? false),
        );
    }
}
