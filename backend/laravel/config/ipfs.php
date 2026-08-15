<?php

return [
    /*
     * Kubo HTTP API (services/ipfs/docker-compose.yml). It can run any node
     * command, so it stays bound to localhost and is never handed to a
     * browser — only this app talks to it.
     */
    'api_url' => env('IPFS_API_URL', 'http://127.0.0.1:5001'),

    'timeout' => (int) env('IPFS_TIMEOUT', 30),

    /*
     * Public read gateway used to turn a CID into an https link for people
     * whose browser cannot resolve ipfs:// itself. The CID is the address;
     * the gateway is one of many ways to fetch it, and anyone may swap it.
     */
    'gateway' => env('IPFS_GATEWAY', 'https://ipfs.io'),
];
