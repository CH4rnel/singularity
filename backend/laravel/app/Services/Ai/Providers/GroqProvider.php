<?php

namespace App\Services\Ai\Providers;

/**
 * Groq: fast open-weight models on their own inference hardware.
 *
 * Its OpenAI-compatible endpoint is a strict subset — same request shape, same
 * response shape — so nothing here differs from the base class but the host
 * and the key.
 */
class GroqProvider extends OpenAiCompatibleProvider
{
    public function __construct()
    {
        parent::__construct(
            'groq',
            config('ai.providers.groq.api_key'),
            (string) config('ai.providers.groq.base_url', 'https://api.groq.com/openai/v1'),
        );
    }
}
