<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The tracker
    |--------------------------------------------------------------------------
    |
    | An index of releases and the announce endpoint their swarms report to.
    | The rule that shapes everything below is that a release exists because a
    | token exists: nothing is listed here that was not minted, and the mint is
    | what the server verifies rather than anything the uploader typed.
    |
    */

    /**
     * The announce URL written into every torrent created here.
     *
     * It has to be the address a stranger's client can reach, which is why it
     * is absolute and configured rather than derived from the request: the
     * torrent outlives this deploy, and a relative URL in a `.torrent` is not
     * a tracker.
     */
    'announce_url' => env('TRACKER_ANNOUNCE_URL', 'https://cyberia.church/announce'),

    /**
     * Seconds a client is asked to wait between announces, and the floor it is
     * told never to go under.
     *
     * The interval is also what a peer's row is kept alive by: a peer that has
     * not announced in `interval * 2` is gone, because that is a client that
     * either stopped or crashed, and a swarm listing dead peers sends every
     * new joiner to nobody.
     */
    'interval' => (int) env('TRACKER_INTERVAL', 900),
    'min_interval' => (int) env('TRACKER_MIN_INTERVAL', 300),

    /** Peers returned in one announce when the client does not say. */
    'numwant' => (int) env('TRACKER_NUMWANT', 50),

    /** Ceiling on `numwant`, so one client cannot ask for the whole swarm. */
    'max_numwant' => (int) env('TRACKER_MAX_NUMWANT', 200),

    /*
    |--------------------------------------------------------------------------
    | Where a release is minted
    |--------------------------------------------------------------------------
    |
    | The collections a token may live in, by chain id. A registration names a
    | chain and a token; the server reads `ownerOf` and `tokenURI` from that
    | chain itself, so nothing about the token comes from the browser.
    |
    | Cyberia only, and deliberately: the point of the check is that this host
    | can perform it, and a chain whose RPC we cannot read is a chain where
    | "this was minted" would be something a stranger asserts.
    |
    */
    'chains' => [
        49406 => [
            'name' => 'Cyberia',
            'rpc_url' => env('TRACKER_RPC_URL', env('CYBERIA_RPC_URL', 'https://rpc.cyberia.church')),
            'collection' => env('TRACKER_COLLECTION', '0x546462FAbf30734E63b64f32B30EC8ADD9B6EBa7'),
            'explorer_url' => env('TRACKER_EXPLORER_URL', 'https://explorer.cyberia.church'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reading the token's metadata
    |--------------------------------------------------------------------------
    |
    | A tokenURI points at a document this host has to fetch to know what the
    | release is. It is a stranger's URL, so it is fetched with a timeout, a
    | size cap and a scheme allowlist — an `ipfs://` through the configured
    | gateway, or plain https. Nothing else is followed.
    |
    */
    'metadata' => [
        'timeout' => (int) env('TRACKER_METADATA_TIMEOUT', 12),
        'max_bytes' => (int) env('TRACKER_METADATA_MAX_BYTES', 262144),
        'max_files' => (int) env('TRACKER_METADATA_MAX_FILES', 2000),
    ],

    /**
     * What a release may be filed under.
     *
     * A short closed list rather than free tags: a category is a filter on the
     * index, and a filter over a thousand spellings of "music" filters
     * nothing. Anything unrecognised becomes `other` rather than being refused
     * — the token is already minted by then, and losing the release over a
     * word would be the worst possible answer.
     */
    'categories' => ['video', 'audio', 'image', 'software', 'text', 'other'],

    /** Releases per page in the index. */
    'per_page' => (int) env('TRACKER_PER_PAGE', 30),
];
