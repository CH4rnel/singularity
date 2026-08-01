<?php

return [
    'sites_domain' => env('LAUNCHPAD_SITES_DOMAIN', 'cyberia.church'),

    /*
     * Chains a token can be launched on. One launch may target several of
     * them; every deployment gets its own metadata row keyed by
     * (chain_id, address). The browser-side contract addresses live in
     * resources/js/lib/launchpadChains.ts — keep the two lists in sync.
     */
    'default_chain_id' => (int) env('LAUNCHPAD_DEFAULT_CHAIN_ID', 49406),

    'chains' => [
        49406 => 'Cyberia',
        4663 => 'Robinhood Chain',
    ],

    'reserved_subdomains' => [
        'admin',
        'api',
        'app',
        'assets',
        'blog',
        'bridge',
        'cdn',
        'docs',
        'explorer',
        'ftp',
        'ipfs',
        'mail',
        'mx',
        'node',
        'ns1',
        'ns2',
        'rpc',
        'static',
        'status',
        'swap',
        'www',
    ],
];
