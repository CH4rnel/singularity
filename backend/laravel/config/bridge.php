<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin emails allowed to access /admin/bridge-analytics.
    |--------------------------------------------------------------------------
    | Comma-separated list, e.g. "alice@example.com,bob@example.com".
    */

    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BRIDGE_ADMIN_EMAILS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Feature flag: serve the new wizard-style bridge UI.
    |--------------------------------------------------------------------------
    | When false, the legacy single-page form is rendered instead.
    */

    'new_ux' => filter_var(env('BRIDGE_NEW_UX', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Bridge fee — denominated in USD, paid in the source token.
    |--------------------------------------------------------------------------
    | flat_usd is a fixed dollar fee per transaction. rate_bps adds a percentage
    | on top of the USD amount. The actual fee charged is max(flat, rate*amount).
    */

    'fee' => [
        'flat_usd' => env('BRIDGE_FEE_FLAT_USD', '0.10'),
        'rate_bps' => (int) env('BRIDGE_FEE_RATE_BPS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gas drop — top up empty EVM recipients with a small amount of native CYBER
    | so they can pay for their first transaction on Cyberia.
    |--------------------------------------------------------------------------
    */

    'gas_drop' => [
        'enabled' => filter_var(env('BRIDGE_GAS_DROP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'amount_cyber' => env('BRIDGE_GAS_DROP_AMOUNT', '0.01'),
        // Recipients with native balance at or below this threshold (wei) qualify.
        'threshold_wei' => env('BRIDGE_GAS_DROP_THRESHOLD_WEI', '0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported tokens and their per-chain identifiers.
    |--------------------------------------------------------------------------
    | model: 'native' goes through the CyberBridge contract (CYBER only);
    |        'direct' uses a plain ERC20/SPL transfer to the relayer hot wallet.
    */

    'tokens' => [
        // CYBER.sol is a wrapped token — mint()/burn() on EVM are gated to the
        // CyberBridge contract, so we cannot use the 'direct' (hot-wallet
        // transfer) flow. Bridging goes through CyberBridge's releaseCyberSol
        // / redeemCyberSol functions.
        'CYBER.sol' => [
            'symbol' => 'CYBER.sol',
            'evm_address' => '0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA',
            'solana_mint' => 'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump',
            'evm_decimals' => 18,
            'solana_decimals' => 6,
            'model' => 'native',
            'solana_token_program' => 'token-2022',
        ],
        'USDC' => [
            'symbol' => 'USDC',
            'evm_address' => '0xdc25597B19799010047F17e9591EFE08EFd40077',
            'solana_mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
            'evm_decimals' => 6,
            'solana_decimals' => 6,
            'model' => 'mint',
            'solana_token_program' => 'token',
        ],
        'USDT' => [
            'symbol' => 'USDT',
            'evm_address' => '0x94845aF24a3E431593A2b941b2b31836dE45185D',
            'solana_mint' => 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB',
            'evm_decimals' => 6,
            'solana_decimals' => 6,
            'model' => 'mint',
            'solana_token_program' => 'token',
        ],
    ],

];
