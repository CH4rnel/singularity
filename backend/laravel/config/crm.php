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
    | The console ("Пульт") — one queue instead of five dashboards.
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

        // The chat lens was drawn on its own canvas, after the first nine
        // artboards were already frozen. Two links rather than one edited
        // link: a design is a record of a decision, and the record of the
        // sixth lens is not the record of the first five.
        'chat_mockup_url' => (string) env(
            'CRM_CONSOLE_CHAT_MOCKUP_URL',
            'https://claude.ai/code/artifact/c8545ac1-fec6-4dbe-9da1-830b502fac68',
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | The room ("Чат") — one conversation, and the files that came with it.
    |--------------------------------------------------------------------------
    | Everything here is a limit on what an operator may drop into a shared
    | room and how long it stays. Files live on the private disk and are only
    | ever handed back through the console's own gate, so these numbers are
    | about disk and attention rather than about access.
    */

    'chat' => [
        // How many messages one page of the room carries. Older ones are
        // fetched by pressing for them, because a room that loads a year of
        // history to show today's three lines is a room nobody opens twice.
        'page_size' => (int) env('CRM_CHAT_PAGE_SIZE', 60),

        // Longest single message. Generous: people paste stack traces in.
        'max_chars' => (int) env('CRM_CHAT_MAX_CHARS', 8000),

        'files' => [
            'max_mb' => (int) env('CRM_CHAT_FILE_MAX_MB', 25),
            'max_per_message' => (int) env('CRM_CHAT_FILES_PER_MESSAGE', 5),

            // Refused outright. Not a virus policy — a room where one drag
            // can leave a runnable file on the server is a room with a
            // different threat model than the one it was designed for.
            'blocked_extensions' => [
                'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'ps1', 'psm1',
                'sh', 'bash', 'zsh', 'php', 'phar', 'jar', 'apk', 'deb',
                'rpm', 'dmg', 'app', 'so', 'dll', 'py', 'rb', 'pl',
            ],

            // A file goes when its message goes. Both are dropped after this,
            // because a room is a working record and not an archive.
            'retention_days' => (int) env('CRM_CHAT_RETENTION_DAYS', 180),
        ],

        'lainos' => [
            // How many recent messages are replayed as context. The room
            // prints this number under every answer, so changing it changes
            // what the room promises.
            'context_messages' => (int) env('CRM_CHAT_LAINOS_CONTEXT', 20),

            // How much of an attached text file is quoted into the question.
            // Names and sizes always go up; contents only when the file is
            // attached to the line that called LainOS.
            'file_bytes' => (int) env('CRM_CHAT_LAINOS_FILE_BYTES', 8000),

            // Answering with the tool-less persona when the daemon is
            // unreachable. Off means an unreachable daemon is reported as
            // exactly that: two correspondents are not interchangeable, and
            // silently swapping them is how "LainOS said so" stops meaning
            // anything.
            'fallback' => (bool) env('CRM_CHAT_LAINOS_FALLBACK', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The ingest — where a task comes from when nobody typed it.
    |--------------------------------------------------------------------------
    | LainOS does most of its work while nobody is watching: it forges wishes,
    | takes profit on journaled positions, fires balance watches and brings
    | back research digests. None of that reached the board that is supposed
    | to say what this project is doing, so the daemon now files each of them
    | as a task over POST /api/crm/tasks.
    |
    | Gated on a shared token compared in constant time, and an unset token
    | means the route 404s — same rule as the host heartbeat. This one writes
    | rather than reads, so an open ingest would be a way for anyone to put
    | words on the operators' board.
    |
    | It accepts facts and never instructions: a title, a detail, whether it
    | is already done, and an id the sender minted. It cannot assign anybody,
    | cannot touch an existing task, and cannot reach a contact.
    */

    'ingest' => [
        'token' => (string) env('CRM_INGEST_TOKEN', ''),

        // Longest single record. A daemon that pastes a stack trace into a
        // task title is a daemon that made the board unreadable.
        'max_title' => 200,
        'max_detail' => 4000,
    ],

];
