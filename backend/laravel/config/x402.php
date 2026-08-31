<?php

/*
 * x402 — payment over HTTP, for callers who have no key and no account.
 *
 * The protocol is one exchange: an unpaid request is answered `402` with the
 * terms in the `PAYMENT-REQUIRED` header, the caller signs an authorization
 * for exactly those terms and repeats the request with `PAYMENT-SIGNATURE`,
 * and the server hands both to a facilitator — `/verify` before the work,
 * `/settle` after it — then reports the transaction in `PAYMENT-RESPONSE`.
 *
 * Nothing here holds a key or touches a chain. Settlement is the facilitator's
 * job by design: it is the party that pays gas and broadcasts, which is why
 * accepting payment on Base costs this server no wallet, no RPC and no nonce.
 * That also means the facilitator is chosen, not implied — see `facilitator`.
 */
return [
    // Off until an operator has named a payee and a facilitator. A paywall
    // that is half-configured must refuse to exist rather than quote terms
    // nobody can settle.
    'enabled' => (bool) env('X402_ENABLED', false),

    /*
     * Who verifies and settles.
     *
     * The testnet default is deliberately not a mainnet address: x402.org's
     * facilitator serves Base Sepolia only, so a deployment that flips
     * `X402_ENABLED` without choosing a facilitator quotes testnet terms
     * instead of quietly failing to collect real money. `x402:check` is the
     * command that says whether the pairing actually works.
     *
     * Mainnet options are Coinbase's CDP facilitator (needs credentials, and
     * charges per settlement above a free tier) or one of the free third-party
     * facilitators; either is a URL plus, if it authenticates, one header.
     */
    'facilitator' => [
        'url' => rtrim((string) env('X402_FACILITATOR_URL', 'https://x402.org/facilitator'), '/'),
        // Sent on both calls when set, e.g. `Bearer …`.
        'authorization' => trim((string) env('X402_FACILITATOR_AUTHORIZATION', '')),
        // Verification is a read and may be impatient; settlement waits for a
        // chain and may not.
        'verify_timeout' => (int) env('X402_VERIFY_TIMEOUT', 15),
        'settle_timeout' => (int) env('X402_SETTLE_TIMEOUT', 60),
    ],

    // Where the money lands. An address we control on `network`, and the one
    // field whose mistake is unrecoverable.
    'pay_to' => trim((string) env('X402_PAY_TO', '')),

    // CAIP-2, as x402 v2 requires: eip155:8453 is Base, eip155:84532 its
    // Sepolia. The v1 spelling ("base") is not accepted by v2 facilitators.
    'network' => trim((string) env('X402_NETWORK', 'eip155:8453')),

    'scheme' => trim((string) env('X402_SCHEME', 'exact')),

    /*
     * What is accepted, in atomic units of one ERC-20.
     *
     * `name` and `version` are the token's **EIP-712 domain**, not its display
     * name, and the client signs against them: native USDC on Base answers
     * `name()` with "USD Coin" and its domain agrees, while some deployments
     * of the same contract use "USDC" — a wrong pair here produces signatures
     * that recover to the wrong address and a paywall that rejects everyone.
     * Verified against the contract's own DOMAIN_SEPARATOR before it was
     * written down here.
     */
    'asset' => [
        'address' => trim((string) env('X402_ASSET_ADDRESS', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913')),
        'symbol' => trim((string) env('X402_ASSET_SYMBOL', 'USDC')),
        'decimals' => (int) env('X402_ASSET_DECIMALS', 6),
        'name' => trim((string) env('X402_ASSET_EIP712_NAME', 'USD Coin')),
        'version' => trim((string) env('X402_ASSET_EIP712_VERSION', '2')),
    ],

    // How long the caller has to pay before the terms are stale. The facilitator
    // enforces it; we only state it.
    'max_timeout_seconds' => (int) env('X402_MAX_TIMEOUT_SECONDS', 120),

    // What a discovery crawler learns about this resource. Optional to the
    // protocol, and the difference between being findable and not.
    'resource' => [
        'service_name' => trim((string) env('X402_SERVICE_NAME', 'Cyberia')),
        'tags' => ['ai', 'inference', 'llm'],
        'icon_url' => trim((string) env('X402_ICON_URL', '')),
    ],

    /*
     * Per-payer burst limit.
     *
     * A paying caller is not quota'd the way a key is — they are paying — but
     * one payer must still not be able to crowd out the rest between
     * settlements. Replay is not what this stops: the authorization carries a
     * nonce the chain refuses twice.
     */
    'requests_per_minute' => (int) env('X402_REQUESTS_PER_MINUTE', 60),

    /*
     * The price of one inference call, in whole units of the asset.
     *
     * Flat per call, because `exact` charges before the answer exists and this
     * server cannot know the token count in advance. Metered pricing is the
     * `upto` scheme's job, which authorises a maximum and settles the actual;
     * it is deliberately not implemented yet.
     *
     * `models` overrides the default for individual catalogue ids.
     */
    'ai' => [
        'price' => trim((string) env('X402_AI_PRICE', '0.01')),
        'models' => [],
    ],
];
