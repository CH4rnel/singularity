<?php

$provider = static fn (string $key, string $baseUrl, array $options = []): array => $options + [
    'api_key' => env($key),
    'base_url' => $baseUrl,
];

$cloudflareAccount = trim((string) env('CLOUDFLARE_ACCOUNT_ID', ''));
$vertexProject = trim((string) env('VERTEX_PROJECT_ID', ''));
$vertexLocation = trim((string) env('VERTEX_LOCATION', 'global')) ?: 'global';

$providers = [
    // Catalog and defaults follow free-claude-code's provider catalog. All of
    // these expose OpenAI Chat Completions; providers without their required
    // credential (or explicit local enable flag) are inert.
    'nvidia_nim' => $provider('NVIDIA_NIM_API_KEY', env('NVIDIA_NIM_BASE_URL', 'https://integrate.api.nvidia.com/v1')),
    'openrouter' => $provider('OPENROUTER_API_KEY', env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1')),
    'groq' => $provider('GROQ_API_KEY', env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1')),
    'cline_pass' => $provider('CLINE_API_KEY', env('CLINE_BASE_URL', 'https://api.cline.bot/api/v1')),
    'openai' => $provider('OPENAI_API_KEY', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')),
    'xai' => $provider('XAI_API_KEY', env('XAI_BASE_URL', 'https://api.x.ai/v1')),
    'qwencloud' => $provider('QWENCLOUD_API_KEY', env('QWENCLOUD_BASE_URL', 'https://token-plan.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1')),
    'qwencloud_coding' => $provider('QWENCLOUD_CODING_API_KEY', env('QWENCLOUD_CODING_BASE_URL', 'https://coding-intl.dashscope.aliyuncs.com/v1')),
    'together' => $provider('TOGETHER_API_KEY', env('TOGETHER_BASE_URL', 'https://api.together.ai/v1')),
    'deepinfra' => $provider('DEEPINFRA_API_KEY', env('DEEPINFRA_BASE_URL', 'https://api.deepinfra.com/v1/openai')),
    'siliconflow' => $provider('SILICONFLOW_API_KEY', env('SILICONFLOW_BASE_URL', 'https://api.siliconflow.com/v1')),
    'nebius' => $provider('NEBIUS_API_KEY', env('NEBIUS_BASE_URL', 'https://api.tokenfactory.nebius.com/v1')),
    'chutes' => $provider('CHUTES_API_KEY', env('CHUTES_BASE_URL', 'https://llm.chutes.ai/v1')),
    'featherless' => $provider('FEATHERLESS_API_KEY', env('FEATHERLESS_BASE_URL', 'https://api.featherless.ai/v1')),
    'agnes' => $provider('AGNES_API_KEY', env('AGNES_BASE_URL', 'https://apihub.agnes-ai.com/v1')),
    'zenmux' => $provider('ZENMUX_API_KEY', env('ZENMUX_BASE_URL', 'https://zenmux.ai/api/v1'), ['max_tokens_field' => 'max_completion_tokens']),
    'wandb' => $provider('WANDB_API_KEY', env('WANDB_BASE_URL', 'https://api.inference.wandb.ai/v1'), ['max_tokens_field' => 'max_completion_tokens']),
    'azure_openai' => $provider('AZURE_OPENAI_API_KEY', env('AZURE_OPENAI_BASE_URL', ''), ['max_tokens_field' => 'max_completion_tokens']),
    'gemini' => $provider('GEMINI_API_KEY', env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai')),
    'vertex' => $provider('VERTEX_ACCESS_TOKEN', $vertexProject === '' ? '' : sprintf(
        '%s/v1/projects/%s/locations/%s/endpoints/openapi',
        $vertexLocation === 'global' ? 'https://aiplatform.googleapis.com' : "https://{$vertexLocation}-aiplatform.googleapis.com",
        rawurlencode($vertexProject),
        rawurlencode($vertexLocation),
    ), ['headers' => ['x-goog-user-project' => $vertexProject]]),
    'deepseek' => $provider('DEEPSEEK_API_KEY', env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com')),
    'mistral' => $provider('MISTRAL_API_KEY', env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1')),
    'mistral_codestral' => $provider('CODESTRAL_API_KEY', env('CODESTRAL_BASE_URL', 'https://codestral.mistral.ai/v1')),
    'opencode_zen' => $provider('OPENCODE_API_KEY', env('OPENCODE_ZEN_BASE_URL', 'https://opencode.ai/zen/v1'), ['headers' => ['User-Agent' => 'opencode']]),
    'opencode_go' => $provider('OPENCODE_API_KEY', env('OPENCODE_GO_BASE_URL', 'https://opencode.ai/zen/go/v1'), ['headers' => ['User-Agent' => 'opencode']]),
    'vercel' => $provider('AI_GATEWAY_API_KEY', env('AI_GATEWAY_BASE_URL', 'https://ai-gateway.vercel.sh/v1')),
    'bedrock' => $provider('AWS_BEARER_TOKEN_BEDROCK', env('BEDROCK_BASE_URL', 'https://bedrock-mantle.us-east-1.api.aws/v1')),
    'huggingface' => $provider('HUGGINGFACE_API_KEY', env('HUGGINGFACE_BASE_URL', 'https://router.huggingface.co/v1')),
    'cohere' => $provider('COHERE_API_KEY', env('COHERE_BASE_URL', 'https://api.cohere.ai/compatibility/v1'), [
        'strip_message_names' => true,
        'drop_fields' => ['n', 'parallel_tool_calls', 'top_logprobs'],
    ]),
    'github_models' => $provider('GITHUB_MODELS_TOKEN', env('GITHUB_MODELS_BASE_URL', 'https://models.github.ai/inference'), [
        'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2026-03-10'],
    ]),
    'wafer' => $provider('WAFER_API_KEY', env('WAFER_BASE_URL', 'https://pass.wafer.ai/v1')),
    'kimi' => $provider('KIMI_API_KEY', env('KIMI_BASE_URL', 'https://api.moonshot.ai/v1')),
    'kimi_code' => $provider('KIMI_CODE_API_KEY', env('KIMI_CODE_BASE_URL', 'https://api.kimi.com/coding/v1'), [
        'headers' => ['User-Agent' => 'free-claude-code'],
        'max_tokens_field' => 'max_completion_tokens',
    ]),
    'kilo' => $provider('KILO_API_KEY', env('KILO_BASE_URL', 'https://api.kilo.ai/api/gateway')),
    'minimax' => $provider('MINIMAX_API_KEY', env('MINIMAX_BASE_URL', 'https://api.minimax.io/v1'), ['max_tokens_field' => 'max_completion_tokens']),
    'cerebras' => $provider('CEREBRAS_API_KEY', env('CEREBRAS_BASE_URL', 'https://api.cerebras.ai/v1'), ['max_tokens_field' => 'max_completion_tokens']),
    'sambanova' => $provider('SAMBANOVA_API_KEY', env('SAMBANOVA_BASE_URL', 'https://api.sambanova.ai/v1')),
    'fireworks' => $provider('FIREWORKS_API_KEY', env('FIREWORKS_BASE_URL', 'https://api.fireworks.ai/inference/v1')),
    'novita' => $provider('NOVITA_API_KEY', env('NOVITA_BASE_URL', 'https://api.novita.ai/openai/v1')),
    'cloudflare' => $provider('CLOUDFLARE_API_TOKEN', $cloudflareAccount === '' ? '' : sprintf(
        '%s/accounts/%s/ai/v1',
        rtrim((string) env('CLOUDFLARE_API_ROOT', 'https://api.cloudflare.com/client/v4'), '/'),
        rawurlencode($cloudflareAccount),
    ), ['max_tokens_field' => 'max_completion_tokens']),
    'zai' => $provider('ZAI_API_KEY', env('ZAI_BASE_URL', 'https://api.z.ai/api/coding/paas/v4')),
    'zai_api' => $provider('ZAI_API_KEY', env('ZAI_API_BASE_URL', 'https://api.z.ai/api/paas/v4')),
    'tokenrouter' => $provider('TOKENROUTER_API_KEY', env('TOKENROUTER_BASE_URL', 'https://api.tokenrouter.com/v1')),
    'nararoute' => $provider('NARAROUTE_API_KEY', env('NARAROUTE_BASE_URL', 'https://router.bynara.id/v1')),
    'poolside' => $provider('POOLSIDE_API_KEY', env('POOLSIDE_BASE_URL', 'https://inference.poolside.ai/v1')),
    'ollama_cloud' => $provider('OLLAMA_API_KEY', env('OLLAMA_CLOUD_BASE_URL', 'https://ollama.com/v1')),
    'lmstudio' => [
        'api_key' => null,
        'base_url' => env('LM_STUDIO_BASE_URL', 'http://127.0.0.1:1234/v1'),
        'enabled' => (bool) env('AI_ENABLE_LMSTUDIO', false),
        'requires_api_key' => false,
    ],
    'llamacpp' => [
        'api_key' => null,
        'base_url' => env('LLAMACPP_BASE_URL', 'http://127.0.0.1:8080/v1'),
        'enabled' => (bool) env('AI_ENABLE_LLAMACPP', false),
        'requires_api_key' => false,
    ],
    'ollama' => [
        'api_key' => null,
        'base_url' => rtrim((string) env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'), '/').'/v1',
        'enabled' => (bool) env('AI_ENABLE_OLLAMA', false),
        'requires_api_key' => false,
    ],
];

$providerModelDefaults = [
    'nvidia_nim' => 'nvidia/nemotron-3-super-120b-a12b',
    'cline_pass' => 'cline-pass/kimi-k3',
    'xai' => 'grok-4.5',
    'qwencloud' => 'qwen3.7-plus',
    'qwencloud_coding' => 'qwen3.7-plus',
    'together' => 'zai-org/GLM-5.2',
    'deepinfra' => 'deepseek-ai/DeepSeek-V4-Flash',
    'siliconflow' => 'Qwen/Qwen3-32B',
    'nebius' => 'Qwen/Qwen3-30B-A3B',
    'chutes' => 'Qwen/Qwen3-32B-TEE',
    'featherless' => 'Qwen/Qwen3-32B',
    'agnes' => 'agnes-2.0-flash',
    'zenmux' => 'deepseek/deepseek-v4-flash-free',
    'wandb' => 'openai/gpt-oss-20b',
    'azure_openai' => '',
    'openai' => '',
    'gemini' => 'models/gemini-3.1-flash-lite',
    'vertex' => 'google/gemini-3.5-flash',
    'deepseek' => 'deepseek-chat',
    'mistral' => 'devstral-small-latest',
    'mistral_codestral' => 'codestral-latest',
    'opencode_zen' => 'gpt-5.3-codex',
    'opencode_go' => 'minimax-m2.7',
    'vercel' => 'openai/gpt-5.5',
    'bedrock' => 'openai.gpt-oss-120b',
    'huggingface' => 'Qwen/Qwen3-Coder-480B-A35B-Instruct:fastest',
    'cohere' => 'command-a-plus-05-2026',
    'github_models' => 'openai/gpt-4.1',
    'wafer' => 'DeepSeek-V4-Pro',
    'kimi' => 'kimi-k2.5',
    'kimi_code' => 'k3',
    'kilo' => 'kilo-auto/free',
    'minimax' => 'MiniMax-M3',
    'cerebras' => 'gpt-oss-120b',
    'sambanova' => 'Meta-Llama-3.3-70B-Instruct',
    'fireworks' => 'accounts/fireworks/models/llama-v3p3-70b-instruct',
    'novita' => 'deepseek/deepseek-v4-flash-0731',
    'cloudflare' => '@cf/moonshotai/kimi-k2.6',
    'zai' => 'glm-5.2',
    'zai_api' => 'glm-4.7-flash',
    'tokenrouter' => 'moonshotai/kimi-k3-free',
    'nararoute' => 'kimi-k3-free',
    'poolside' => 'poolside/laguna-s-2.1',
    'ollama_cloud' => 'qwen3-coder:480b',
    'lmstudio' => '',
    'llamacpp' => '',
    'ollama' => '',
];

$providerModels = [];

foreach ($providerModelDefaults as $providerName => $defaultUpstream) {
    $envName = 'AI_MODEL_'.strtoupper($providerName);
    $upstream = trim((string) env($envName, $defaultUpstream));

    if ($upstream === '') {
        continue;
    }

    $id = 'lain-'.str_replace('_', '-', $providerName);
    $providerModels[] = [
        'id' => $id,
        'label' => 'Lain '.ucwords(str_replace('_', ' ', $providerName)),
        'provider' => $providerName,
        'upstream' => $upstream,
        'context' => 0,
        'fallback' => 'lain-free',
    ];
}

$extraModels = json_decode((string) env('AI_EXTRA_MODELS_JSON', '[]'), true);

if (! is_array($extraModels)) {
    $extraModels = [];
}

return [

    /*
    |--------------------------------------------------------------------------
    | The Cyberia inference API
    |--------------------------------------------------------------------------
    |
    | An OpenAI-compatible endpoint (/api/ai/v1) in front of providers whose
    | keys live on this server and nowhere else. Callers hold a Cyberia key,
    | not a provider key: the upstream account is ours, which is why access is
    | gated (see `gate`) and metered (see `limits`) rather than open.
    |
    */

    // Public base path is fixed by the router; this is only what the API calls
    // itself in its own responses and errors.
    'name' => env('AI_API_NAME', 'cyberia'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | These are the same provider families catalogued by free-claude-code at
    | commit 23071a6. A provider with no credential is not "broken" — it is
    | absent, so its models disappear instead of failing at request time.
    |
    */

    'providers' => $providers,

    /*
    |--------------------------------------------------------------------------
    | Model catalogue
    |--------------------------------------------------------------------------
    |
    | An allowlist, not a passthrough. The caller spends our upstream account,
    | so "any model id you like" would mean "any bill you like"; every entry
    | here names the provider that serves it and the id to send upstream.
    |
    | `id` is what the public API answers to and reports back, so it stays
    | stable even when the upstream name behind it is repointed.
    |
    | `fallback` names another catalogue id to retry on when this one errors
    | in a way that a different model could survive (rate limits, upstream
    | 5xx, a model that vanished). It is followed at most once.
    |
    */

    'models' => array_merge([

        [
            'id' => 'lain-fast',
            'label' => 'Lain Fast (Llama 3.1 8B)',
            'provider' => 'groq',
            'upstream' => 'llama-3.1-8b-instant',
            'context' => 131072,
            'fallback' => 'lain-free',
        ],
        [
            'id' => 'lain-large',
            'label' => 'Lain Large (Llama 3.3 70B)',
            'provider' => 'groq',
            'upstream' => 'llama-3.3-70b-versatile',
            'context' => 131072,
            'fallback' => 'lain-fast',
        ],
        [
            'id' => 'lain-reason',
            'label' => 'Lain Reason (GPT-OSS 120B)',
            'provider' => 'groq',
            'upstream' => 'openai/gpt-oss-120b',
            'context' => 131072,
            'fallback' => 'lain-large',
        ],
        [
            'id' => 'lain-reason-mini',
            'label' => 'Lain Reason Mini (GPT-OSS 20B)',
            'provider' => 'groq',
            'upstream' => 'openai/gpt-oss-20b',
            'context' => 131072,
            'fallback' => 'lain-fast',
        ],
        // OpenRouter's $0 router. Whatever is free and up right now answers,
        // so it is the floor everything else falls back to, never a promise
        // about which model replies.
        [
            'id' => 'lain-free',
            'label' => 'Lain Free (OpenRouter free router)',
            'provider' => 'openrouter',
            'upstream' => env('AI_FREE_MODEL', 'openrouter/free'),
            'context' => 32768,
            'fallback' => null,
        ],

    ], $providerModels, $extraModels),

    // Answered when the request names no model at all.
    'default_model' => env('AI_DEFAULT_MODEL', 'lain-fast'),

    /*
    |--------------------------------------------------------------------------
    | Holder gate
    |--------------------------------------------------------------------------
    |
    | Who may hold a key: an address holding at least `minimum_share_bps` of
    | the live supply of `token_address` on Cyberia. The share is re-read on
    | every request (cached briefly), so selling the position closes the API
    | the same way it closes the $LAIN room — a key is a pointer to a holding,
    | not a permanent grant.
    |
    | Defaults to $LAIN at 0.5% — deliberately far below the 10% that opens
    | the holders' room, because a room is for a handful of people and an API
    | is not. Point it at CYBER (or any ERC-20) by changing the address.
    |
    */

    'gate' => [
        'token_address' => env('AI_GATE_TOKEN_ADDRESS', env('LAIN_TOKEN_ADDRESS', '0x05cd1afd5b2df3cca6ceab80cbc21168ec981e8b')),
        'token_symbol' => env('AI_GATE_TOKEN_SYMBOL', 'LAIN'),
        'minimum_share_bps' => (int) env('AI_GATE_MINIMUM_SHARE_BPS', 50),
        'cache_seconds' => (int) env('AI_GATE_CACHE_SECONDS', 60),
        // A gate that cannot read the chain fails closed: an unreadable
        // balance is not a passing balance.
        'rpc_url' => env('AI_GATE_RPC_URL', env('CYBERIA_RPC_URL', 'https://rpc.cyberia.church')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Every one of these is a bound on what one key can spend of a shared
    | upstream account. `max_output_tokens` is a cap, not a default: a request
    | asking for more is clamped down to it rather than rejected.
    |
    */

    'limits' => [
        'requests_per_minute' => (int) env('AI_LIMIT_RPM', 20),
        'requests_per_day' => (int) env('AI_LIMIT_RPD', 2000),
        'max_output_tokens' => (int) env('AI_LIMIT_MAX_OUTPUT_TOKENS', 4096),
        'max_input_chars' => (int) env('AI_LIMIT_MAX_INPUT_CHARS', 120000),
        'max_messages' => (int) env('AI_LIMIT_MAX_MESSAGES', 200),
        // Keys one address may hold at once (revoked ones do not count).
        'keys_per_address' => (int) env('AI_LIMIT_KEYS_PER_ADDRESS', 5),
    ],

    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 120),

    // How long a signed key-issuance challenge stays answerable.
    'challenge_ttl_seconds' => (int) env('AI_CHALLENGE_TTL_SECONDS', 300),

    // Retention for the per-request usage log (`ai:prune-usage`).
    'usage_retention_days' => (int) env('AI_USAGE_RETENTION_DAYS', 90),

];
