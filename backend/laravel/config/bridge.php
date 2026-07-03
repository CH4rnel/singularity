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
    | Home chain — where the bridge's wrapper tokens live. Mint-model tokens
    | are minted/burned here; on every other chain the relayer pays out of
    | inventory/reserves instead.
    |--------------------------------------------------------------------------
    */

    'home_chain' => 'cyberia',

    /*
    |--------------------------------------------------------------------------
    | Bridge fee — denominated in USD, paid in the source token.
    |--------------------------------------------------------------------------
    | flat_usd is a fixed dollar fee per transaction. rate_bps adds a percentage
    | on top of the USD amount. The actual fee charged is max(flat, rate*amount).
    | Only tokens with fee_bearing=true are charged (stables).
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
    | Supported chains.
    |--------------------------------------------------------------------------
    | type drives the verify/payout strategy: evm | solana | ton | yenten.
    | deposit_address is where users send source-chain deposits; null on EVM
    | chains means "the relayer EOA" (same key works on every EVM chain).
    | Adding a new EVM chain = add an entry here (+ routes + token chain maps
    | + an .env RPC var) — no code changes needed.
    */

    'chains' => [
        'cyberia' => [
            'key' => 'cyberia',
            'label' => 'Cyberia EVM',
            'type' => 'evm',
            'address_type' => 'evm',
            'wallet' => 'evm',
            'evm_chain_id' => 49406,
            'rpc_url' => env('BRIDGE_EVM_RPC_URL') ?: env('CYBERIA_RPC_URL', 'https://rpc.cyberia.church'),
            'explorer_tx' => 'https://explorer.cyberia.church/tx/{hash}',
            'native_currency' => ['name' => 'Cyber', 'symbol' => 'CYBER', 'decimals' => 18],
            'deposit_address' => null,
        ],
        'solana' => [
            'key' => 'solana',
            'label' => 'Solana',
            'type' => 'solana',
            'address_type' => 'solana',
            'wallet' => 'solana',
            'rpc_url' => env('BRIDGE_SOLANA_RPC_URL', 'https://mainnet.helius-rpc.com/?api-key=7e740762-a25d-4d37-b854-de4cec9815ed'),
            'explorer_tx' => 'https://solscan.io/tx/{hash}',
            'deposit_address' => env('BRIDGE_SOLANA_HOT_WALLET', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w'),
        ],
        'ton' => [
            'key' => 'ton',
            'label' => 'TON',
            'type' => 'ton',
            'address_type' => 'ton',
            'wallet' => 'manual',
            // tonapi.io — deposit verification + payout tx lookup.
            'api_url' => env('BRIDGE_TON_API_URL', 'https://tonapi.io'),
            'api_key' => env('TONAPI_KEY'),
            // toncenter JSON-RPC — used by the payout relay script.
            'toncenter_rpc_url' => env('BRIDGE_TONCENTER_RPC_URL', 'https://toncenter.com/api/v2/jsonRPC'),
            'explorer_tx' => 'https://tonviewer.com/transaction/{hash}',
            // Operator TON wallet receiving jetton deposits (friendly or raw
            // form). TON routes are hidden from the UI while unset. Should be
            // the wallet controlled by TON_RELAYER_MNEMONIC so the same hot
            // wallet serves both directions.
            'deposit_address' => env('BRIDGE_TON_DEPOSIT_ADDRESS'),
        ],
        'bnb' => [
            'key' => 'bnb',
            'label' => 'BNB Chain',
            'type' => 'evm',
            'address_type' => 'evm',
            'wallet' => 'evm',
            'evm_chain_id' => 56,
            'rpc_url' => env('BRIDGE_BSC_RPC_URL', 'https://bsc-dataseed.binance.org'),
            'explorer_tx' => 'https://bscscan.com/tx/{hash}',
            'native_currency' => ['name' => 'BNB', 'symbol' => 'BNB', 'decimals' => 18],
            'deposit_address' => null,
        ],
        'yenten' => [
            'key' => 'yenten',
            'label' => 'Yenten',
            'type' => 'yenten',
            'address_type' => 'yenten',
            'wallet' => 'manual',
            // Official light-wallet API: transaction/UTXO reads and raw-tx
            // broadcast. No local yentend or blockchain download is required.
            'api_url' => env('BRIDGE_YENTEN_API_URL', 'https://api.yentencoin.info'),
            'explorer_tx' => 'https://ytn.ccore.online/transaction/{hash}/',
            // Central wallet: receives swept deposits and pays out evm_to_yenten.
            'deposit_address' => env('BRIDGE_YENTEN_DEPOSIT_ADDRESS'),
            'relayer_wif' => env('BRIDGE_YENTEN_RELAYER_WIF'),
            // Master seed for per-request one-time deposit addresses. Each
            // request derives its own address (index = request id), binding a
            // deposit to exactly one request + committed recipient so a public
            // deposit tx can't be hijacked. Only the seed is secret.
            'hd_seed' => env('BRIDGE_YENTEN_HD_SEED'),
            'minimum_confirmations' => (int) env('BRIDGE_YENTEN_MIN_CONFIRMATIONS', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bridge routes. All auto-processed by the relayer.
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'sol_to_evm' => [
            'direction' => 'sol_to_evm',
            'source_chain' => 'solana',
            'destination_chain' => 'cyberia',
            'auto_process' => true,
        ],
        'evm_to_sol' => [
            'direction' => 'evm_to_sol',
            'source_chain' => 'cyberia',
            'destination_chain' => 'solana',
            'auto_process' => true,
        ],
        'ton_to_evm' => [
            'direction' => 'ton_to_evm',
            'source_chain' => 'ton',
            'destination_chain' => 'cyberia',
            'auto_process' => true,
        ],
        'evm_to_ton' => [
            'direction' => 'evm_to_ton',
            'source_chain' => 'cyberia',
            'destination_chain' => 'ton',
            'auto_process' => true,
        ],
        'bnb_to_evm' => [
            'direction' => 'bnb_to_evm',
            'source_chain' => 'bnb',
            'destination_chain' => 'cyberia',
            'auto_process' => true,
        ],
        'evm_to_bnb' => [
            'direction' => 'evm_to_bnb',
            'source_chain' => 'cyberia',
            'destination_chain' => 'bnb',
            'auto_process' => true,
        ],
        'yenten_to_evm' => [
            'direction' => 'yenten_to_evm',
            'source_chain' => 'yenten',
            'destination_chain' => 'cyberia',
            'auto_process' => true,
        ],
        'evm_to_yenten' => [
            'direction' => 'evm_to_yenten',
            'source_chain' => 'cyberia',
            'destination_chain' => 'yenten',
            'auto_process' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional CYBER.sol -> native CYBER auto-conversion (sol_to_evm only).
    |--------------------------------------------------------------------------
    | When the user opts in, the relayer mints the bridged CYBER.sol to itself
    | and redeems it through the CyberSolBurnSwap contract (fixed 1000:1 rate,
    | input burned to 0x...dEaD), then forwards the native CYBER payout to the
    | recipient. Off by default per request — plain CYBER.sol delivery stays
    | the standard flow.
    */

    'convert' => [
        'enabled' => filter_var(env('BRIDGE_CONVERT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'burn_swap_address' => env('BRIDGE_CYBERSOL_BURN_SWAP', '0xa5Ae36E5b1eDb24BCa2F96783d079B28e0BCfd71'),
        // CYBER.sol required per 1 native CYBER (matches CyberSolBurnSwap.RATE).
        'rate' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported tokens with per-chain identifiers.
    |--------------------------------------------------------------------------
    | model: 'native' goes through the CyberBridge contract (CYBER.sol only);
    |        'mint'   — relayer owns the Cyberia wrapper: mint() on bridge-IN,
    |                   burnFrom() on bridge-OUT;
    |        'direct' — plain transfers from relayer inventory.
    | A token is offered on a route iff it has entries for BOTH route chains.
    | chains.<key>: {address|mint|master|native, decimals, token_program?}.
    */

    'tokens' => [
        // CYBER.sol is a wrapped token — mint()/burn() on EVM are gated to the
        // CyberBridge contract, so we cannot use the 'direct' (hot-wallet
        // transfer) flow. Bridging goes through CyberBridge's releaseCyberSol
        // / redeemCyberSol functions.
        'CYBER.sol' => [
            'symbol' => 'CYBER.sol',
            'model' => 'native',
            'chains' => [
                'cyberia' => ['address' => '0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA', 'decimals' => 18],
                'solana' => ['mint' => 'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump', 'decimals' => 6, 'token_program' => 'token-2022'],
            ],
        ],
        'USDC' => [
            'symbol' => 'USDC',
            'model' => 'mint',
            'fee_bearing' => true,
            'chains' => [
                'cyberia' => ['address' => '0xdc25597B19799010047F17e9591EFE08EFd40077', 'decimals' => 6],
                'solana' => ['mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', 'decimals' => 6, 'token_program' => 'token'],
            ],
        ],
        // USDT (Solana reserve). Deliberately NOT bridged to BNB Chain — the
        // BSC-USDT reserve is a separate token (USDT.BNB) so the two reserves
        // never mix.
        'USDT' => [
            'symbol' => 'USDT',
            'model' => 'mint',
            'fee_bearing' => true,
            'chains' => [
                'cyberia' => ['address' => '0x94845aF24a3E431593A2b941b2b31836dE45185D', 'decimals' => 6],
                'solana' => ['mint' => 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB', 'decimals' => 6, 'token_program' => 'token'],
            ],
        ],
        // HATCHER — Solana-native token bridged to Cyberia. Mint model: the
        // relayer EOA owns the EVM wrapper and mint()s on bridge-IN / burnFrom()s
        // on bridge-OUT. Canonical SPL mint is Token-2022 / 6 decimals; the EVM
        // wrapper uses 9 (each side scales independently, like CYBER.sol 18/6).
        'HATCHER' => [
            'symbol' => 'HATCHER',
            'model' => 'mint',
            'chains' => [
                'cyberia' => ['address' => '0x621021F18b6404123f98b1395c418868418ACF36', 'decimals' => 9],
                'solana' => ['mint' => 'Cntmo5DJNQkB2vYyS4mUx2UoTW4mPrHgWefz8miZpump', 'decimals' => 6, 'token_program' => 'token-2022'],
            ],
        ],
        // KARASIQUE — TON jetton bridged to the existing Cyberia ERC20.
        'KRSQ' => [
            'symbol' => 'KRSQ',
            'model' => 'mint',
            'chains' => [
                'cyberia' => ['address' => '0x4945419ccEEF0Dc70B054700DE2750A056B03eE3', 'decimals' => 18],
                'ton' => ['master' => 'EQBcumfGKvl8jD1eAjRMggu7xf0JV7D1n5mj4zfYTOnuCXhp', 'decimals' => 9],
            ],
        ],
        // Goal Bear Coin — TON jetton bridged to the existing Cyberia ERC20.
        'GOAL' => [
            'symbol' => 'GOAL',
            'model' => 'mint',
            'chains' => [
                'cyberia' => ['address' => '0xEb91EC10462a249b9922D6D62FB2BE73Bd084ADe', 'decimals' => 18],
                'ton' => ['master' => 'EQBofbbpUhtSvnZxOsPmzAv84fq1bG0-Mf79OPB4FrEXsT0I', 'decimals' => 9],
            ],
        ],
        // Native Yenten is held by the Yenten relayer wallet; the Cyberia
        // representation is owner-minted and burned on bridge-out.
        'YTN' => [
            'symbol' => 'YTN',
            'model' => 'mint',
            'chains' => [
                'cyberia' => ['address' => '0x3a5820Be90c3fB9c5F3Fb47a4859544193B0f8C6', 'decimals' => 18],
                'yenten' => ['native' => true, 'decimals' => 8],
            ],
        ],
        // Native BNB bridged into a Cyberia wrapper (deployed via
        // crypto/hardhat/scripts/deploy-bnb.ts).
        'BNB' => [
            'symbol' => 'BNB',
            'model' => 'mint',
            'chains' => [
                'cyberia' => ['address' => env('BRIDGE_BNB_WRAPPER_ADDRESS', ''), 'decimals' => 18],
                'bnb' => ['native' => true, 'decimals' => 18],
            ],
        ],
        // Tether USD from BNB Chain — a SEPARATE token from USDT (Solana
        // reserve). Both wrapper and BSC token use 18 decimals (no scaling).
        'USDT.BNB' => [
            'symbol' => 'USDT.BNB',
            'model' => 'mint',
            'fee_bearing' => true,
            'chains' => [
                'cyberia' => ['address' => env('BRIDGE_USDT_BNB_WRAPPER_ADDRESS', ''), 'decimals' => 18],
                'bnb' => ['address' => '0x55d398326f99059fF775485246999027B3197955', 'decimals' => 18],
            ],
        ],
    ],

];
