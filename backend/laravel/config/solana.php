<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Solana RPC proxy
    |--------------------------------------------------------------------------
    |
    | Solana's public endpoint answers this server and refuses the browser: the
    | same request that returns a balance from curl comes back
    | `403 Access forbidden` the moment it carries an `Origin` header. That is
    | one refusal with a wide blast radius — the wallet's Solana account, the
    | bridge's SPL balances and staking all read the chain from the browser, so
    | they all went dark together.
    |
    | The paid endpoints answer browsers, but only with a key in the URL, and a
    | key in a bundle is a key anyone may spend. So the browser talks to this
    | app and this app talks to Solana: the key stays on the server, the origin
    | that Solana refuses is never sent, and the endpoint the browser gets is
    | our own domain.
    |
    | Nothing here holds a Solana key or signs anything. A transaction is
    | signed in the browser or in Phantom and arrives here already signed —
    | this is a relay for JSON-RPC, not a wallet.
    |
    */

    'rpc' => [

        /**
         * Off hands every caller its own configured endpoint again, exactly as
         * before this proxy existed. Nothing here is load-bearing for signing,
         * so turning it off degrades reads rather than breaking a flow.
         */
        'enabled' => filter_var(env('SOLANA_RPC_PROXY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        /**
         * Upstreams per cluster, tried in order until one answers.
         *
         * Order is deliberate: a keyed endpoint first (it is faster and has no
         * per-IP ceiling), the public cluster last (it answers this server for
         * free and is the reason a dead or exhausted key is an inconvenience
         * rather than an outage). A key that has run out of credits answers
         * with a refusal, not with data, and the next upstream gets the call.
         *
         * Duplicates are collapsed so that pointing two variables at the same
         * host does not double a timeout.
         */
        'upstreams' => [
            'mainnet' => array_values(array_unique(array_filter(array_merge(
                [trim((string) env('SOLANA_RPC_URL'))],
                // Comma-separated, because one free tier is a single point of
                // failure and stacking three costs nothing: a provider that
                // rate-limits us hands the call to the next in the same
                // second. Every one of them is a key, and a key here never
                // reaches a browser — that is the whole point of the relay.
                array_map('trim', explode(',', (string) env('SOLANA_RPC_FALLBACK_URL', ''))),
                [
                    trim((string) env('BRIDGE_SOLANA_RPC_URL')),
                    // Keyless and last: it answers a server perfectly well
                    // (only browsers get the 403), so it is the floor under
                    // every key above it rather than a plan.
                    'https://api.mainnet-beta.solana.com',
                ],
            )))),
            'devnet' => array_values(array_unique(array_filter(array_merge(
                array_map('trim', explode(',', (string) env('SOLANA_DEVNET_RPC_URL', ''))),
                ['https://api.devnet.solana.com'],
            )))),
        ],

        /** Seconds one upstream is given before the next is tried. */
        'timeout' => (int) env('SOLANA_RPC_PROXY_TIMEOUT', 20),

        /**
         * Methods this relay will forward.
         *
         * An allowlist rather than a denylist because the cost of a method is
         * not visible from its name: `getProgramAccounts` scans an entire
         * program's state and would spend a month of credits in an afternoon.
         * Everything the wallet, the bridge, staking and the slot machine
         * actually call is here; adding one is a line of config, which is the
         * point of it being config.
         *
         * Subscriptions are absent because they are a WebSocket protocol and
         * this is HTTP — the clients poll `getSignatureStatuses` instead.
         */
        'methods' => [
            'getAccountInfo',
            'getBalance',
            'getBlockHeight',
            'getBlockTime',
            'getEpochInfo',
            'getFeeForMessage',
            'getGenesisHash',
            'getHealth',
            'getLatestBlockhash',
            'getMinimumBalanceForRentExemption',
            'getMultipleAccounts',
            'getRecentPrioritizationFees',
            'getSignatureStatuses',
            'getSignaturesForAddress',
            'getSlot',
            'getTokenAccountBalance',
            'getTokenAccountsByOwner',
            'getTokenSupply',
            'getTransaction',
            'getVersion',
            'isBlockhashValid',
            'sendTransaction',
            'simulateTransaction',
        ],

        /**
         * Calls accepted in one batch.
         *
         * `getParsedTransactions` sends one request per signature, and the
         * wallet's history asks for ten at a time; the cap is what stops a
         * batch from being a way to spend a hundred calls under one throttle
         * hit.
         */
        'max_batch' => (int) env('SOLANA_RPC_PROXY_MAX_BATCH', 25),

        /** Largest request body accepted, in bytes. A signed transaction is ~1.5 KB. */
        'max_bytes' => (int) env('SOLANA_RPC_PROXY_MAX_BYTES', 131072),

        /**
         * Seconds an answer is reused, per method.
         *
         * Only chain-wide reads are listed: the answer to `getLatestBlockhash`
         * is the same for everyone who asks in the same second, and a blockhash
         * stays valid for about a minute after it is handed out. Nothing that
         * names an account is cached — a balance a second out of date is a bug
         * report — and nothing that changes state is, ever.
         *
         * This exists because the public cluster's limits are per IP, and
         * behind a proxy that IP is this server for every visitor at once.
         */
        'cache' => [
            'getBlockHeight' => 2,
            'getEpochInfo' => 5,
            'getGenesisHash' => 3600,
            'getHealth' => 5,
            'getLatestBlockhash' => 2,
            'getMinimumBalanceForRentExemption' => 300,
            'getRecentPrioritizationFees' => 5,
            'getSlot' => 2,
            'getVersion' => 3600,
        ],

        /**
         * Browser origins allowed to use the relay. Empty means any.
         *
         * A request with no `Origin` at all is always allowed — that is curl,
         * a script or another server, none of which are the abuse this guards
         * against. Set it (comma-separated, `*.example.com` accepted) when the
         * first upstream is a metered key worth protecting; leaving it empty
         * leaves the per-IP throttle as the only limit, which is the right
         * trade while the public cluster is the one serving.
         */
        'origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SOLANA_RPC_ALLOWED_ORIGINS', '')),
        ))),
    ],

];
