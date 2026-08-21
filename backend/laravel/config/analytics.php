<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product analytics for the wallet
    |--------------------------------------------------------------------------
    |
    | Off means the ingest endpoint answers 202 and writes nothing, and the
    | client stops sending. It never means an operation fails: analytics is not
    | a dependency of sending, swapping or bridging, and a wallet whose swap
    | broke because a metrics endpoint was down would be a worse product than
    | one with no metrics at all.
    |
    */

    'enabled' => (bool) env('ANALYTICS_ENABLED', true),

    /*
    | Do Not Track / Global Privacy Control.
    |
    | This is a non-custodial wallet: a person who has said "do not track me"
    | in their browser has said it about exactly this. Honouring it costs us
    | some users in the denominator; ignoring it would make every privacy claim
    | on the onboarding screen a little less true.
    */

    'respect_dnt' => (bool) env('ANALYTICS_RESPECT_DNT', true),

    /*
    | How long a device may be quiet before the next event starts a new session.
    | Thirty minutes is the convention, and the client is the side that applies
    | it — it is the only side that knows the app was idle rather than offline.
    */

    'session_timeout_minutes' => (int) env('ANALYTICS_SESSION_TIMEOUT', 30),

    /*
    | Rows accepted in one ingest call. The client batches to keep the wallet
    | from making one request per tap; this bounds what one request can cost.
    */

    'max_batch' => 20,

    /*
    |--------------------------------------------------------------------------
    | Funding verification
    |--------------------------------------------------------------------------
    |
    | `wallet_funded` is a milestone the client cannot be trusted with alone: a
    | browser can claim it was funded, and a balance that ticks up and down
    | would otherwise re-fire it. So the client reports a *candidate* and this
    | server confirms it by reading the chain, exactly once per user.
    |
    | Only chains readable from here without an API key are listed, and an
    | address is only ever stored for a chain on this list — holding an address
    | we cannot check buys nothing and costs the user a linkage. Etherscan-
    | family networks (BNB, Base) are deliberately absent for that reason; a
    | funding claim from them is recorded as `client` and counted separately.
    |
    */

    'verifiable_chains' => [

        /*
         * The same endpoints the rest of this app reads these chains through,
         * by the same env vars — on prod `BRIDGE_EVM_RPC_URL` points at the
         * internal node, which is the right thing for a server-side balance
         * read and is what GasSponsorService already uses. Deploying this
         * needs no new environment.
         */
        'cyberia' => [
            'type' => 'evm',
            'rpc' => env('ANALYTICS_CYBERIA_RPC')
                ?: env('BRIDGE_EVM_RPC_URL')
                ?: env('CYBERIA_RPC_URL', 'https://rpc.cyberia.church'),
            // The keyless index the gas station reads holdings from.
            'explorer_api' => env('WALLET_GAS_EXPLORER_API', 'https://explorer.cyberia.church/api'),
        ],

        'robinhood' => [
            'type' => 'evm',
            'rpc' => env('ANALYTICS_ROBINHOOD_RPC')
                ?: env('BRIDGE_ROBINHOOD_RPC_URL', 'https://rpc.mainnet.chain.robinhood.com'),
            'explorer_api' => env(
                'ANALYTICS_ROBINHOOD_EXPLORER_API',
                'https://robinhoodchain.blockscout.com/api',
            ),
        ],

        // Read through this app's own relay, because Solana's public cluster
        // refuses a browser but answers this server. See SolanaRpcProxy.
        'solana' => [
            'type' => 'solana',
        ],
    ],

    /*
    | Minutes an address's "is it funded yet" answer is reused. Funding happens
    | once per wallet, so re-reading a chain for the same empty address on
    | every heartbeat would be the endpoint's whole cost.
    */

    'funding_cache_minutes' => (int) env('ANALYTICS_FUNDING_CACHE', 10),

    /*
    | Users the scheduled sweep re-checks per run, and how far back it looks
    | for someone worth re-checking. Bounded so a growing table cannot turn the
    | scheduler into a chain-scanning job.
    */

    'funding_sweep_limit' => (int) env('ANALYTICS_FUNDING_SWEEP_LIMIT', 200),
    'funding_sweep_days' => (int) env('ANALYTICS_FUNDING_SWEEP_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Day offsets reported as retention buckets, and the window a cohort is
    | measured in. A bucket is reported as null until the youngest member of a
    | cohort has had time to reach it, so "0% D30" never means "too early".
    |
    */

    'retention_buckets' => [1, 7, 30],

    /*
    |--------------------------------------------------------------------------
    | North Star
    |--------------------------------------------------------------------------
    |
    | Weekly Active Funded Users: distinct analytics users who are funded and
    | performed at least one meaningful action in the last N days. Never
    | counted by address — one person with five addresses is one user.
    |
    */

    'north_star_days' => 7,

];
