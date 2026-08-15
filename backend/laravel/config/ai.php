<?php

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
    | Both speak the OpenAI chat-completions dialect, which is the only reason
    | one client class serves both. A provider with no key is not "broken" —
    | it is simply absent: its models disappear from /v1/models instead of
    | being offered and then failing at request time.
    |
    */

    'providers' => [

        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        ],

        'groq' => [
            'api_key' => env('GROQ_API_KEY'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        ],

    ],

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

    'models' => [

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

    ],

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
