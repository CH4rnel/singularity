<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cyberia Fuji faucet
    |--------------------------------------------------------------------------
    |
    | Test CYBER for anyone building on the Fuji L1 (49407), handed out from
    | its own hot wallet. The chain itself (RPC, id, coin) is described once,
    | in config/bridge.php under chains.cyberia_fuji, and read from there.
    |
    | Fees on this chain cost what an Ethereum mainnet transfer costs, counted
    | in CYBER (minBaseFee 7.2e15 wei: a plain transfer is ~151 CYBER), so the
    | drip is sized in transactions, not in coins: 10,000 CYBER is about sixty
    | transfers or one contract deployment.
    |
    */

    /**
     * The DAO treasury on 49407: the protocol's 2-of-3 Safe. RewardManager
     * sends every fee here; the page names it only while the chain says so.
     */
    'treasury' => (string) env('FUJI_DAO_TREASURY', '0x96cd117786EBC0fDbef56a6559FEB2bd2BaE3393'),

    'faucet' => [

        /** Off means the page says the faucet is closed, not that it errors. */
        'enabled' => (bool) env('FUJI_FAUCET_ENABLED', true),

        /**
         * The faucet's own EOA, holding only what it gives away.
         *
         * Never the bridge relayer's key: that address pays bridge payouts on
         * the same chain, and two senders on one nonce sequence lose races.
         */
        'private_key' => (string) env('FUJI_FAUCET_PRIVATE_KEY', ''),

        /** Whole CYBER per drip. */
        'drip_cyber' => (string) env('FUJI_FAUCET_DRIP_CYBER', '10000'),

        /** One drip per address per this many hours. */
        'cooldown_hours' => (int) env('FUJI_FAUCET_COOLDOWN_HOURS', 24),

        /**
         * Quotas over the last 24 hours, counted from `fuji_faucet_claims`
         * rather than the cache, so flushing the cache hands nobody a fresh
         * allowance.
         */
        'daily_per_ip' => (int) env('FUJI_FAUCET_DAILY_PER_IP', 3),
        'daily_total' => (int) env('FUJI_FAUCET_DAILY_TOTAL', 20),
    ],

];
