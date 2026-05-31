<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ethereum' => [
        'rpc_url' => env('CYBERIA_RPC_URL'),
    ],

    'bridge' => [
        'evm_rpc_url' => env('BRIDGE_EVM_RPC_URL', env('CYBERIA_RPC_URL')),
        'evm_bridge_address' => env('BRIDGE_EVM_CONTRACT_ADDRESS'),
        // The relayer is the same EOA as the contract deployer / owner.
        // BRIDGE_RELAYER_PRIVATE_KEY exists for backwards compat; canonical
        // name is DEPLOYER_PK. `?:` (not the env() default) is used because
        // env('X', $fallback) only kicks in when X is unset, not when it's
        // set to an empty string.
        'relayer_private_key' => env('BRIDGE_RELAYER_PRIVATE_KEY') ?: env('DEPLOYER_PK'),
        // Optional override. If empty, BridgeRelayerService derives the address
        // from the private key automatically (EIP-55 checksum) and caches it.
        'relayer_address' => env('BRIDGE_RELAYER_ADDRESS'),
        'solana_rpc_url' => env('BRIDGE_SOLANA_RPC_URL', 'https://api.mainnet-beta.solana.com'),
        'solana_bridge_program' => env('BRIDGE_SOLANA_PROGRAM_ID'),
    ],

    'slots' => [
        'hot_wallet_address' => env('SLOT_HOT_WALLET_ADDRESS'),
        'hot_wallet_keypair_path' => env('SLOT_HOT_WALLET_KEYPAIR_PATH'),
        'cluster' => env('SLOT_CLUSTER', 'devnet'),
        'rpc_url' => env('SLOT_RPC_URL') ?: (env('SLOT_CLUSTER', 'devnet') === 'mainnet'
            ? 'https://api.mainnet-beta.solana.com'
            : 'https://api.devnet.solana.com'),
        'burn_bps' => env('SLOT_BURN_BPS', 200),
        'house_edge_bps' => env('SLOT_HOUSE_EDGE_BPS', 400),
        'jackpot_threshold_bps' => env('SLOT_JACKPOT_THRESHOLD_BPS', 10),
        'max_single_win_bps' => env('SLOT_MAX_SINGLE_WIN_BPS', 2000),
        'max_bet_usd' => env('SLOT_MAX_BET_USD', 50),
        'metadata_ttl_hours' => env('SLOT_METADATA_TTL_HOURS', 24),
        'prepare_ttl_minutes' => env('SLOT_PREPARE_TTL_MINUTES', 5),
        // Pump.fun auto-whitelist (Phase 2).
        'pumpfun_api_base' => env('SLOT_PUMPFUN_API_BASE', 'https://frontend-api-v3.pump.fun'),
        'pumpfun_auto_enable' => env('SLOT_PUMPFUN_AUTO_ENABLE', true),
        'pumpfun_min_mcap_usd' => env('SLOT_PUMPFUN_MIN_MCAP_USD', 10000),
        'pumpfun_lazy_enabled' => env('SLOT_PUMPFUN_LAZY_ENABLED', true),
        'pumpfun_bulk_top_n' => env('SLOT_PUMPFUN_BULK_TOP_N', 200),
    ],

];
