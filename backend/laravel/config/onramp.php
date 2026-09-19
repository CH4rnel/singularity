<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Buying crypto with a bank card
    |--------------------------------------------------------------------------
    |
    | Every other way into this wallet assumes somebody already holds crypto:
    | the bridge moves what you have, the swap trades what you have, the gas
    | station hands out a drip so what you have can move. A person with a card
    | and nothing else has no door at all, and that is the gap this fills.
    |
    | What is deliberately NOT built here is an acquirer. Taking a card number
    | means a licence, a merchant of record, chargeback liability and a PCI
    | scope, and none of that belongs on a host that also runs a chain. So an
    | on-ramp provider is the merchant: it prices the purchase, takes the card,
    | performs the KYC and settles the crypto to an address. This app composes
    | the handoff, states the terms before anybody is redirected, and writes
    | down what came back. It never sees a card, never holds the money and
    | never takes custody of the crypto — the destination is the user's own
    | address, derived in their own browser.
    |
    | Several providers rather than one, for the same reason `config/bridge.php`
    | lists corridors: coverage is a patchwork. A country one provider refuses
    | is a country another serves, a card that fails at one clears at the next,
    | and a provider that goes down must not take the whole door with it. So
    | this is a registry, every entry answers the same questions, and adding a
    | fifth is an edit to this file plus one class.
    |
    | THE THING THIS CANNOT DO, said here because it is the first question
    | anybody asks: no provider in this file accepts a МИР card, and none will.
    | Мир runs on Russia's own NSPK rails, which stop at the borders of the
    | countries wired into them, and NSPK itself is under sanctions that make
    | any regulated western processor refuse the network outright. See the
    | `mir` block at the bottom, which states that as a corridor with its
    | reason rather than quietly omitting the option.
    |
    */

    /** Off means the wallet says buying is unavailable, not that it fails. */
    'enabled' => (bool) env('ONRAMP_ENABLED', true),

    /** Seconds any one call to a provider may take before it is a failure. */
    'timeout' => (int) env('ONRAMP_TIMEOUT', 15),

    /** Catalogues are the same answer for every visitor; a quote never is. */
    'cache_seconds' => (int) env('ONRAMP_CACHE_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Where a purchase lands
    |--------------------------------------------------------------------------
    |
    | Not on Cyberia. No on-ramp knows chain 49406 — they onboard chains one
    | compliance review at a time, and a chain with one bridge and one DEX is
    | not on that list — so a purchase is delivered on a chain the provider
    | already settles to, at the *same address* this wallet derives there, and
    | the wallet's own cross-chain swap is the second step onto Cyberia.
    |
    | That second step is named on the screen instead of being smoothed over.
    | One purchase that silently becomes two actions is how somebody ends up
    | holding USDC on Base wondering where their CYBER went.
    |
    | `chain` is the wallet's own id for the network (see `lib/wallet/chains.ts`
    | and the catalogue), so a row here is drawable without a second lookup.
    |
    */

    'delivery' => [
        [
            'chain' => 'base',
            'asset' => 'USDC',
            'kind' => 'token',
            /** What the provider calls this pair. One string per dialect. */
            'codes' => ['transak' => 'USDC', 'moonpay' => 'usdc_base', 'ramp' => 'BASE_USDC', 'onramper' => 'usdc_base'],
            'networks' => ['transak' => 'base'],
        ],
        [
            'chain' => 'base',
            'asset' => 'ETH',
            'kind' => 'coin',
            'codes' => ['transak' => 'ETH', 'moonpay' => 'eth_base', 'ramp' => 'BASE_ETH', 'onramper' => 'eth_base'],
            'networks' => ['transak' => 'base'],
        ],
        [
            'chain' => 'solana',
            'asset' => 'SOL',
            'kind' => 'coin',
            'codes' => ['transak' => 'SOL', 'moonpay' => 'sol', 'ramp' => 'SOLANA_SOL', 'onramper' => 'sol'],
            'networks' => ['transak' => 'solana'],
        ],
        [
            'chain' => 'solana',
            'asset' => 'USDC',
            'kind' => 'token',
            'codes' => ['transak' => 'USDC', 'moonpay' => 'usdc_sol', 'ramp' => 'SOLANA_USDC', 'onramper' => 'usdc_solana'],
            'networks' => ['transak' => 'solana'],
        ],
        [
            'chain' => 'bnb',
            'asset' => 'BNB',
            'kind' => 'coin',
            'codes' => ['transak' => 'BNB', 'moonpay' => 'bnb_bsc', 'ramp' => 'BSC_BNB', 'onramper' => 'bnb_bsc'],
            'networks' => ['transak' => 'bsc'],
        ],
    ],

    /** Which delivery row a first-time buyer is offered. */
    'default_delivery' => (string) env('ONRAMP_DEFAULT_DELIVERY', 'base:USDC'),

    /*
    |--------------------------------------------------------------------------
    | The providers
    |--------------------------------------------------------------------------
    |
    | Every entry answers the same five questions, and a screen can be drawn
    | from them before anybody types an amount:
    |
    |   kind        — `widget` is a hosted checkout on the provider's own
    |                 origin (the only kind this app ships: a card form inside
    |                 our page would put this host in PCI scope), `aggregator`
    |                 is one key in front of many of the others.
    |   credentials — the env names that switch it on. Unset is not an error:
    |                 the route is listed as `unconfigured` with that reason,
    |                 which is how an operator sees what is left to do.
    |   restricted  — the jurisdictions we already know it refuses, so the
    |                 wallet can say so instead of redirecting somebody into a
    |                 refusal page. The provider's own API is still the
    |                 authority; this is only what we can say early.
    |   fee         — see below. It is a *disclosure*, never an enforcement.
    |   signing     — some providers require the handoff URL to be signed with
    |                 a secret (MoonPay refuses an unsigned URL carrying a
    |                 wallet address in production). That is the second reason
    |                 this lives on the server and not in the bundle.
    |
    | THE FEE IS NOT LIKE THE CROSS-CHAIN ONE. Relay takes Cyberia's cut as a
    | field in the quote request, so `CrosschainRouter` composes it server-side
    | and a browser cannot delete it. On-ramps do not work that way: a partner
    | fee is a setting inside the provider's own dashboard, applied by them,
    | invisible to this code. So `declared_bps` here is what the operator says
    | they set over there — printed as a disclosure so the user is told — and
    | the authoritative number is always the fee breakdown inside the quote
    | that comes back. This app never adds a number of its own to a price.
    |
    */

    'providers' => [

        'transak' => [
            'label' => 'Transak',
            'enabled' => (bool) env('ONRAMP_TRANSAK_ENABLED', true),
            'kind' => 'widget',
            'api' => (string) env('ONRAMP_TRANSAK_API', 'https://api.transak.com'),
            'widget' => (string) env('ONRAMP_TRANSAK_WIDGET', 'https://global.transak.com'),
            'key' => (string) env('ONRAMP_TRANSAK_KEY', ''),
            /** Webhook payloads are JWT-signed with this; nothing else uses it. */
            'secret' => (string) env('ONRAMP_TRANSAK_SECRET', ''),
            'signing' => null,
            /** Its webhook is a JWT signed with the secret above: verifiable, so believed. */
            'tracking' => 'webhook',
            'methods' => ['card', 'bank', 'apple_pay', 'google_pay'],
            'fiat' => ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'INR', 'BRL', 'TRY', 'JPY', 'KRW'],
            'restricted' => ['RU', 'BY', 'IR', 'KP', 'SY', 'CU'],
            'fee' => ['channel' => 'dashboard', 'declared_bps' => (int) env('ONRAMP_TRANSAK_PARTNER_BPS', 0), 'max_bps' => 500],
            /** Local rails are why this one is first: UPI, PIX, SEPA Instant. */
            'note' => 'local_rails',
        ],

        'moonpay' => [
            'label' => 'MoonPay',
            'enabled' => (bool) env('ONRAMP_MOONPAY_ENABLED', true),
            'kind' => 'widget',
            'api' => (string) env('ONRAMP_MOONPAY_API', 'https://api.moonpay.com'),
            'widget' => (string) env('ONRAMP_MOONPAY_WIDGET', 'https://buy.moonpay.com'),
            /** The publishable key (pk_live_…), which is public by design. */
            'key' => (string) env('ONRAMP_MOONPAY_KEY', ''),
            'secret' => (string) env('ONRAMP_MOONPAY_SECRET', ''),
            /*
             * Without this the handoff is refused in production the moment it
             * carries a wallet address — which is every handoff this app
             * makes, since the whole point is delivering to the user's own
             * address rather than to an account somewhere.
             */
            'signing' => 'hmac_sha256_query',
            /** `Moonpay-Signature-V2`, HMAC over timestamp and body. */
            'tracking' => 'webhook',
            'methods' => ['card', 'bank', 'apple_pay', 'google_pay', 'paypal'],
            'fiat' => ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'BRL', 'TRY', 'JPY'],
            'restricted' => ['RU', 'BY', 'IR', 'KP', 'SY', 'CU'],
            'fee' => ['channel' => 'dashboard', 'declared_bps' => (int) env('ONRAMP_MOONPAY_PARTNER_BPS', 0), 'max_bps' => 500],
            'note' => 'widest_cards',
        ],

        'ramp' => [
            'label' => 'Ramp Network',
            'enabled' => (bool) env('ONRAMP_RAMP_ENABLED', true),
            'kind' => 'widget',
            'api' => (string) env('ONRAMP_RAMP_API', 'https://api.ramp.network'),
            'widget' => (string) env('ONRAMP_RAMP_WIDGET', 'https://app.ramp.network'),
            'key' => (string) env('ONRAMP_RAMP_KEY', ''),
            'secret' => (string) env('ONRAMP_RAMP_SECRET', ''),
            'signing' => null,
            /*
             * No tracking, said out loud. Ramp signs its webhooks with an
             * ECDSA key rather than a shared secret, and a verification this
             * app cannot perform is one it must not pretend to: an order here
             * stays `pending` and the screen says delivery will simply appear
             * in the wallet, which is true and is what a person watches
             * anyway.
             */
            'tracking' => 'none',
            'methods' => ['card', 'bank', 'apple_pay', 'google_pay'],
            'fiat' => ['USD', 'EUR', 'GBP', 'PLN', 'CZK', 'RON', 'MXN'],
            'restricted' => ['RU', 'BY', 'IR', 'KP', 'SY', 'CU'],
            'fee' => ['channel' => 'dashboard', 'declared_bps' => (int) env('ONRAMP_RAMP_PARTNER_BPS', 0), 'max_bps' => 500],
            'note' => 'cheap_bank_transfer',
        ],

        /*
         * The aggregator. One key in front of thirty on-ramps, which makes it
         * both the fastest way to have coverage and the reason this registry
         * is not redundant: an aggregator is a single point of failure and a
         * second set of terms on top of the first, so it belongs *in* the list
         * rather than instead of it. Where it is configured it is quoted
         * beside the direct integrations and the better price wins.
         */
        'onramper' => [
            'label' => 'Onramper',
            'enabled' => (bool) env('ONRAMP_ONRAMPER_ENABLED', true),
            'kind' => 'aggregator',
            'api' => (string) env('ONRAMP_ONRAMPER_API', 'https://api.onramper.com'),
            'widget' => (string) env('ONRAMP_ONRAMPER_WIDGET', 'https://buy.onramper.com'),
            'key' => (string) env('ONRAMP_ONRAMPER_KEY', ''),
            'secret' => (string) env('ONRAMP_ONRAMPER_SECRET', ''),
            'signing' => null,
            /** Its status lives with whichever on-ramp it routed to, not with it. */
            'tracking' => 'none',
            'methods' => ['card', 'bank', 'apple_pay', 'google_pay'],
            'fiat' => ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'BRL', 'TRY', 'INR', 'JPY', 'MXN'],
            'restricted' => ['RU', 'BY', 'IR', 'KP', 'SY', 'CU'],
            'fee' => ['channel' => 'dashboard', 'declared_bps' => (int) env('ONRAMP_ONRAMPER_PARTNER_BPS', 0), 'max_bps' => 500],
            'note' => 'aggregates_many',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Мир, and the corridor that is not there
    |--------------------------------------------------------------------------
    |
    | Stated as a corridor with a reason, exactly like a bridge route that is
    | switched off, because the alternative — leaving the option off the screen
    | — reads as "we never thought about it" to the half of this project's
    | audience that holds exactly this card.
    |
    | The facts, so nobody has to rediscover them:
    |
    |   - Мир is NSPK's own network and works only where NSPK is wired in:
    |     Russia, Belarus, and partially a handful of neighbours. A Мир card
    |     presented to a processor outside those rails is not declined for
    |     policy reasons; there is no route for the authorisation at all.
    |   - NSPK is sanctioned. Every provider above is licensed somewhere that
    |     enforces those sanctions, and the ones that tried serving Russian
    |     users from Europe have been designated for it. This is not a gap a
    |     partnership closes.
    |   - The EU's 2026 package additionally bans EU persons from crypto-asset
    |     transactions with Russian and Belarusian providers, which closes the
    |     obvious workaround of fronting a Russian processor from here.
    |   - Inside Russia the direction itself is now prohibited: since September
    |     2026 a Russian company or tax resident may not accept digital
    |     currency as consideration for goods or services, and a card-acquiring
    |     contract for exchanging crypto is refused by acquirers regardless.
    |
    | What is left is a grey segment — processors that route Мир through
    | card-to-card and QR rails with no merchant contract, at seven per cent
    | and up, with account freezes and chargebacks as the normal case, and now
    | with criminal exposure for the people whose cards are used. This project
    | will not integrate one behind a button that looks like the others.
    |
    | So the slot is a *link*, not a driver. If the operator chooses a rail —
    | an exchanger, a P2P desk, a Telegram flow — `ONRAMP_MIR_URL` points at
    | it, and the wallet shows it for what it is: somebody else's service,
    | somebody else's custody, opened in a new tab. Unset, the wallet says Мир
    | is not served and why.
    |
    */

    'mir' => [
        'label' => 'МИР',
        /** Somebody else's page. Empty = the corridor is stated as closed. */
        'url' => (string) env('ONRAMP_MIR_URL', ''),
        /** Whose it is, printed beside the link so the handoff is not implied to be ours. */
        'operator' => (string) env('ONRAMP_MIR_OPERATOR', ''),
        'reason' => 'mir_unserved',
    ],

];
