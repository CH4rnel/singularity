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

    'cyberia' => [
        'explorer_url' => env('CYBERIA_EXPLORER_URL', 'https://explorer.cyberia.church'),
        // Analytics token-price walker (AnalyticsController). $1-pegged anchor
        // tokens the walk starts from (comma-separated addresses; default USDC +
        // USDT) and the minimum USD depth a pool's priced side must hold to be
        // trusted as a route — keeps 1-wei dust pools from poisoning prices.
        'usd_anchors' => env(
            'ANALYTICS_USD_ANCHORS',
            '0xdc25597B19799010047F17e9591EFE08EFd40077,0x94845aF24a3E431593A2b941b2b31836dE45185D',
        ),
        'price_min_pool_usd' => (float) env('ANALYTICS_PRICE_MIN_POOL_USD', 0.01),
    ],

    // X (Twitter) OAuth 2.0 login — Socialite's twitter-oauth-2 driver.
    // Create the app at developer.x.com with the callback
    // {APP_URL}/auth/twitter/callback ("users.read tweet.read" scopes).
    'twitter-oauth-2' => [
        'client_id' => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect' => env('TWITTER_REDIRECT_URI', '/auth/twitter/callback'),
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
        // TON payout relayer (crypto/ton/scripts/relay-jetton-transfer.ts).
        'ton_relayer_mnemonic' => env('TON_RELAYER_MNEMONIC'),
        'toncenter_api_key' => env('TONCENTER_API_KEY'),
        'solana_bridge_program' => env('BRIDGE_SOLANA_PROGRAM_ID'),
    ],

    // CYBER.sol balance reads + Telegram "whales chat" gate. Mint mirrors
    // config/bridge.php (tokens.CYBER.sol). RPC prefers Helius (SOLANA_RPC_URL),
    // falling back to the bridge RPC, then the public mainnet endpoint.
    'cyber_sol' => [
        'rpc_url' => env('SOLANA_RPC_URL') ?: env('BRIDGE_SOLANA_RPC_URL', 'https://api.mainnet-beta.solana.com'),
        'mint' => env('CYBER_SOL_MINT', 'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump'),
        'decimals' => (int) env('CYBER_SOL_DECIMALS', 6),
        'price_usd' => env('CYBER_SOL_PRICE_USD', '0.00009235'),
        // Minimum whole CYBER.sol to qualify for the whales chat.
        'whale_threshold' => env('WHALE_MIN_CYBER_SOL', 10000000),
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
