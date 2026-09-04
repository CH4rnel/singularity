<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reserved Public Profile Handles
    |--------------------------------------------------------------------------
    |
    | Public profiles live at the site root. These handles are already used by
    | application or framework routes and therefore cannot become profile URLs.
    |
    */
    'reserved_handles' => [
        'admin',
        'analytics',
        // The BitTorrent tracker's own endpoints. They are registered outside
        // every middleware group and therefore *after* this route, so the
        // reservation is what keeps them reachable as well as unclaimable —
        // see ProfileHandle::routePattern().
        'announce',
        'api',
        'auth',
        'bridge',
        'build',
        'categories',
        'changelog',
        'comments',
        'convert',
        'crm',
        'dao',
        'dashboard',
        'email',
        'farm',
        'fediverse',
        'feed',
        'invitations',
        'lain',
        'launchpad',
        'leaderboard',
        'lending',
        'links',
        'liquidity',
        'login',
        'logout',
        'market',
        'notifications',
        'partners',
        'pixels',
        'posts',
        'predictions',
        'profile',
        'proposals',
        'reactions',
        'register',
        'sanctum',
        'scrape',
        'settings',
        'slots',
        'staking',
        'storage',
        'swap',
        'tg',
        'thesis',
        'token',
        'tokens',
        'tracker',
        'u',
        'up',
        'user',
        'wallets',
    ],
];
