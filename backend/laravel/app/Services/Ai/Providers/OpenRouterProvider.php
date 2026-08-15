<?php

namespace App\Services\Ai\Providers;

/**
 * OpenRouter: a router over many providers, including free ones.
 *
 * The same key already answers the "Talk to Lain" chat (LainChatService), so
 * this API shares that account rather than opening a second one — which is
 * exactly why the gate and the per-key quotas exist.
 */
class OpenRouterProvider extends OpenAiCompatibleProvider
{
    public function __construct()
    {
        parent::__construct(
            'openrouter',
            config('ai.providers.openrouter.api_key'),
            (string) config('ai.providers.openrouter.base_url', 'https://openrouter.ai/api/v1'),
        );
    }

    /** OpenRouter attributes traffic to the site that sent it. */
    protected function headers(): array
    {
        return [
            'HTTP-Referer' => (string) config('app.url'),
            'X-Title' => 'Cyberia AI API',
        ];
    }
}
