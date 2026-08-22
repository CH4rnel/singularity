<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Who may open the CRM (/crm and everything under it).
    |--------------------------------------------------------------------------
    | A closed room, and deliberately a small one: the console shows other
    | people's money, addresses and records, so everyone who is not on this
    | list gets a 404 rather than a 403 — including signed-in users, so the
    | console is not discoverable by trying the address.
    |
    | Two operators, named two ways. The wallet is the key that proves the
    | session; the account id is the person, and it survives that key being
    | re-attached. Ids are deliberately env-only and empty by default: an id
    | means nothing in a database it was not written for, and a fresh copy
    | must not hand the console to whoever the factories numbered eighth.
    */

    'admin_user_ids' => array_values(array_filter(array_map(
        fn (string $id) => (int) trim($id),
        explode(',', (string) env('CRM_ADMIN_USER_IDS', '')),
    ))),

    /*
    | Comma-separated 0x addresses. Matching is case-insensitive; the users
    | table stores wallet_address lowercased.
    */

    'admin_wallets' => array_values(array_filter(array_map(
        fn (string $address) => strtolower(trim($address)),
        explode(',', (string) env('CRM_ADMIN_WALLETS', implode(',', [
            '0xafF26832db3557daF540B0B09DeE06C24B8A38BB',
            '0x6f4AFc4F18Bd72a92D1c0087ea5fB79754652405',
        ]))),
    ))),

    /*
    |--------------------------------------------------------------------------
    | The console ("Мостик") — one queue instead of five dashboards.
    |--------------------------------------------------------------------------
    | Every threshold that decides whether something reaches a person lives
    | here rather than inside a query, because these are judgements about how
    | busy a duty operator should be, and they get argued about.
    */

    'console' => [
        // "Отложить до утра" wakes items at this hour, in this timezone.
        'morning_hour' => (int) env('CRM_CONSOLE_MORNING_HOUR', 9),
        'timezone' => (string) env('CRM_CONSOLE_TIMEZONE', 'Europe/Moscow'),

        // A whale that showed up (or crossed the threshold) within this many
        // days is still news worth putting in front of somebody.
        'whale_window_days' => 7,

        // A customer with nothing on their record for this long has gone
        // quiet — the segment, and the reason it is a segment rather than a
        // filter, is that this number is the whole definition.
        'silence_days' => 30,

        // Bridge requests still waiting after this long are worth watching.
        // Not an alert: the relayer being slow is not the relayer being dead.
        'bridge_wait_minutes' => 20,

        // Below this the gas station stops being a background fact. The tank
        // does not break the wallet when it empties — it quietly switches off
        // the first payment of every newcomer, which is worse.
        'gas_tank_floor' => (float) env('CRM_CONSOLE_GAS_FLOOR', 60),

        // How long one computed queue is reused. The badge, the banner and
        // the list are the same answer rendered three times, and they must
        // never disagree; a snooze drops it immediately.
        'cache_seconds' => 30,

        // A D7 retention drop of this many points against the previous mature
        // cohort is a finding rather than noise.
        'retention_drop_points' => 3.0,

        // Where the design of this console still lives and can be edited.
        // The artboards themselves are in resources/console-mockup/, which is
        // what /crm/mockup serves; this is only the link back to the canvas.
        'mockup_url' => (string) env(
            'CRM_CONSOLE_MOCKUP_URL',
            'https://claude.ai/code/artifact/97d94821-0cac-490a-89cb-59083edd6701',
        ),
    ],

];
