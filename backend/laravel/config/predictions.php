<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PredictionMarket oracle
    |--------------------------------------------------------------------------
    |
    | The contract takes bets from anyone and lets exactly one address report
    | the outcome. Nothing on the chain forces that address to show up, and for
    | the first two markets nothing did: both closed, sat unanswered for thirty
    | days, and became permanently unresolvable. These settings are the thing
    | that shows up — `predictions:resolve`, on a schedule.
    |
    */

    'contract_address' => env(
        'PREDICTIONS_CONTRACT_ADDRESS',
        '0xb88063Cb2db16473Fb6deB71BaE364aFd09fdE54',
    ),

    'chain_id' => (int) env('PREDICTIONS_CHAIN_ID', 49406),

    'rpc_url' => env('PREDICTIONS_RPC_URL', env('CYBERIA_RPC_URL', 'https://rpc.cyberia.church')),

    'rpc_timeout' => (int) env('PREDICTIONS_RPC_TIMEOUT', 15),

    /**
     * Fallback for PredictionMarket.RESOLVE_WINDOW, used only when the
     * contract cannot be read. The live value is read from the chain, because
     * the auto-cancel below is timed against it.
     */
    'resolve_window_seconds' => (int) env('PREDICTIONS_RESOLVE_WINDOW', 30 * 86400),

    /**
     * Cancel a market nobody could answer, shortly before the contract's
     * refund window closes.
     *
     * Cancelling refunds every bettor in full and takes no fee, so this costs
     * the participants nothing they were owed — it only replaces a market that
     * silently rots with one that visibly gives the money back. Turn it off
     * and unanswered markets go back to lapsing on their own, which refunds
     * too, but only for whoever thinks to come back and look.
     */
    'auto_cancel' => (bool) env('PREDICTIONS_AUTO_CANCEL', true),

    /** Days before the refund window closes at which that cancel fires. */
    'cancel_grace_days' => (int) env('PREDICTIONS_CANCEL_GRACE_DAYS', 3),

    /**
     * Markets resolved in one run. Transactions go out sequentially on a key
     * shared with the bridge relayer, so a run stays short enough not to sit
     * across the next scheduled one.
     */
    'max_per_run' => (int) env('PREDICTIONS_MAX_PER_RUN', 20),

    /*
    |--------------------------------------------------------------------------
    | Operator alerts
    |--------------------------------------------------------------------------
    |
    | A free-form market can only be settled by a person, so the one thing the
    | scheduler must never do is stay quiet about it. Anything actually sent to
    | the chain is announced once; markets waiting on a human are announced
    | again each day, with the time left, until they are answered or cancelled.
    |
    */

    'alerts' => [
        'enabled' => (bool) env('PREDICTIONS_ALERTS', true),

        /** Re-announce a pending market at most this often (hours). */
        'repeat_hours' => (int) env('PREDICTIONS_ALERT_REPEAT_HOURS', 24),
    ],

];
