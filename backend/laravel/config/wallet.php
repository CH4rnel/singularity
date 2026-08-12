<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Wallet-to-wallet encrypted chat
    |--------------------------------------------------------------------------
    |
    | The server is a relay for messages it cannot read, so nothing here is a
    | policy about content — there is no content to have a policy about. These
    | are the limits of the queue itself: how long an undelivered envelope is
    | held, how large one may be, and how many are handed over per poll.
    |
    */

    'chat' => [

        /**
         * Days an envelope is kept before `wallet:chat-prune` deletes it.
         *
         * The wallets at either end hold the conversation; this is only the
         * window in which a wallet that was closed can still collect its mail.
         * Shortening it costs the relay nothing and costs an offline recipient
         * their messages, which is the trade to weigh.
         */
        'retention_days' => (int) env('WALLET_CHAT_RETENTION_DAYS', 30),

        /**
         * Base64 ciphertext characters accepted in one message.
         *
         * The client pads plaintext to a 256-byte block and caps it at 4000
         * bytes, so a legitimate envelope is comfortably under this; the cap
         * exists so the relay cannot be used as free storage.
         */
        'max_body_chars' => (int) env('WALLET_CHAT_MAX_BODY', 8192),

        /** Envelopes handed over in one poll. */
        'page' => 200,

        /**
         * Minutes a signed proof keeps a mailbox open before it is re-signed.
         *
         * Matches the holders' room: long enough that a conversation is not
         * interrupted, short enough that a stolen session cookie stops working
         * without the key behind it.
         */
        'proof_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pinning from the wallet
    |--------------------------------------------------------------------------
    |
    | The wallet composes NFT metadata and pages in the browser, but the Kubo
    | API is bound to localhost and is never handed to a browser — it can run
    | any node command. So the bytes come through this app, which pins them and
    | hands back a CID and nothing else.
    |
    | Storage on the node is the cost of this, which is what the cap and the
    | throttle are about. Anything stricter than a cap — a holding gate, an
    | allowlist — goes in `WalletIpfsController::guard()`, the one place both
    | routes ask permission, so the shape of the endpoint does not change when
    | it arrives.
    |
    */

    'ipfs' => [

        /** Off means the wallet screens say pinning is unavailable, not that they fail. */
        'enabled' => (bool) env('WALLET_IPFS_ENABLED', true),

        /**
         * Largest object one call may pin.
         *
         * PHP's own `upload_max_filesize` and `post_max_size` have to be at
         * least this, or a browser gets Laravel's 413 before this cap is ever
         * consulted — which is a worse error message for the same limit.
         */
        'max_bytes' => (int) env('WALLET_IPFS_MAX_BYTES', 10 * 1024 * 1024),
    ],

];
