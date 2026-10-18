<?php

return [
    'enabled' => (bool) env('ARENA_ENABLED', false),
    'slug' => env('ARENA_SEASON', 'season-zero'),
    'days' => 14,
    'initial_cash_micros' => '1000000000',
    'fee_bps' => 10,
    'slippage_bps' => 5,
    'gas_micros' => '20000',
    'rounds_per_day' => 4,
    'agents' => [
        'lain' => ['name' => 'Lain', 'method' => 'Measured allocation; protect cash when evidence is weak.'],
        'challenger' => ['name' => 'Challenger', 'method' => 'Challenge the position; compare momentum and reversal evidence.'],
    ],
    'automation' => [
        'enabled' => (bool) env('ARENA_AUTOMATION_ENABLED', false),
        'rpc_url' => env('ARENA_MARKET_RPC_URL', 'https://mainnet.base.org'),
        'api_key' => env('ARENA_OPENROUTER_API_KEY'),
        'models' => [
            'lain' => env('ARENA_LAIN_MODEL', 'meta-llama/llama-3.3-70b-instruct'),
            'challenger' => env('ARENA_CHALLENGER_MODEL', 'google/gemini-2.5-flash-lite'),
        ],
        // Reservations are never refunded, even after a timeout.
        'budget_cents' => 1500,
        'reservation_cents_per_call' => 10,
        'max_calls' => 112,
        'max_prompt_bytes' => 12000,
        'max_output_tokens' => 400,
        'max_price_per_million' => 1,
        'timeout_seconds' => 20,
        'opening_window_seconds' => 90,
        'decision_window_seconds' => 180,
        'fill_delay_seconds' => 60,
        'fill_window_seconds' => 180,
        'max_block_age_seconds' => 90,
    ],
];
