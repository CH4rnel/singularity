<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pioneer Season — five checkpoints a server can vouch for
    |--------------------------------------------------------------------------
    |
    | The season is a list of things a person did on chain, checked against the
    | records this platform already keeps: the wallet bound by a signature, the
    | bridge's own request table and the indexer's `activity_events`. Nothing
    | the browser says moves a checkpoint, which is the whole difference between
    | this page and the "Coming soon" one it replaces.
    |
    | Rewards are status and nothing else: XP in the existing ledger (source
    | `pioneer`, reference `<season>:<checkpoint>`, so a rerun pays nothing) and
    | the on-chain Pioneer badge. No token, no points that convert later, and
    | nothing paid for buying, volume, follows or reposts.
    |
    | Only actions inside [starts_at, ends_at] count. Outside the window the
    | verifier records nothing at all, so the dates are also the switch: the
    | season opens when the operator sets them and not when code is deployed.
    |
    */

    'season' => env('PIONEER_SEASON', 's1'),

    'title' => env('PIONEER_SEASON_TITLE', 'Pioneer Season 1'),

    'starts_at' => env('PIONEER_STARTS_AT', '2026-11-04 00:00:00'),

    'ends_at' => env('PIONEER_ENDS_AT', '2026-12-01 23:59:59'),

    /*
    | Minimums in USD as the indexer priced the action. A row the price walker
    | could not price (`usd` NULL) never counts — an unknown amount is not a
    | passing one. High enough against dust, low enough not to push anybody
    | into buying; set them after looking at the distribution.
    */

    'min_swap_usd' => (float) env('PIONEER_MIN_SWAP_USD', 5),

    'min_liquidity_usd' => (float) env('PIONEER_MIN_LIQUIDITY_USD', 10),

    /*
    | Checkpoint 05: a qualifying action on a later UTC date than the first
    | one *and* at least this many hours after it, so 23:59 → 00:01 is not a
    | return.
    */

    'return_after_hours' => (int) env('PIONEER_RETURN_AFTER_HOURS', 24),

    /*
    | A swap and its reverse by the same address within this window are a
    | round trip, and neither leg counts. A routed swap whose input and output
    | are the same asset is a circle and never counts.
    */

    'round_trip_minutes' => (int) env('PIONEER_ROUND_TRIP_MINUTES', 60),

    /*
    | The bridge checkpoint can be switched off for a season. While the bridge
    | is not to be promoted (refactoring plan B03), requiring it would mean
    | sending people into it; switched off, it is shown as not required and
    | completion needs the other four.
    */

    'bridge_required' => (bool) env('PIONEER_BRIDGE_REQUIRED', true),

    /*
    | The first N finishers are looked at by a person before their completion
    | is approved (`pioneer:review`). Checkpoint XP is paid at once — it is
    | cheap — but the completion bonus and the badge wait for approval.
    */

    'review_first' => (int) env('PIONEER_REVIEW_FIRST', 50),

    /*
    | XP per checkpoint, and for an approved completion.
    */

    'xp' => [
        1 => 25,
        2 => 100,
        3 => 50,
        4 => 75,
        5 => 100,
        'complete' => 250,
    ],

    /*
    | Team and treasury addresses take no part. The console's admin wallets
    | and admin accounts are always excluded on top of this list.
    */

    'excluded_addresses' => array_values(array_filter(array_map(
        fn (string $address) => strtolower(trim($address)),
        explode(',', (string) env('PIONEER_EXCLUDED_ADDRESSES', '')),
    ))),

    'leaderboard_size' => 50,

];
