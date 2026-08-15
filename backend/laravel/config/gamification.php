<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Level curve
    |--------------------------------------------------------------------------
    |
    | Cumulative XP required to reach a level is `step * (level - 1) * level`,
    | i.e. a quadratic curve: 100 / 300 / 600 / 1000 XP for levels 2-5 at the
    | default step of 50. Early levels land within a session or two, later
    | ones take weeks — which is the point.
    |
    */

    'level_step' => 50,

    'max_level' => 50,

    /*
    | Rank titles, keyed by the first level that earns them. Lookup takes the
    | highest key that is <= the user's level.
    */
    'titles' => [
        1 => 'Lurker',
        2 => 'Dial-Up',
        4 => 'Node',
        6 => 'Netrunner',
        9 => 'Ghost',
        12 => 'Daemon',
        16 => 'Wired',
        21 => 'Protocol Seven',
        27 => 'Collective',
        35 => 'Present Day',
    ],

    /*
    |--------------------------------------------------------------------------
    | XP per action
    |--------------------------------------------------------------------------
    |
    | Awards are idempotent per (source, reference). Every value action carries
    | a tx hash or row id as its reference, so it is paid exactly once however
    | often the sync reruns. Awards without a real subject (the daily visit)
    | fall back to a day stamp and are therefore capped at one per UTC day.
    |
    | Nothing here is paid on the browser's word alone: the client can only
    | move the visit/exploration quests, while swaps, liquidity, bridges and
    | governance are credited from the indexer, the bridge table and the DAO
    | tables respectively.
    |
    */

    'xp' => [
        'visit' => 10,
        'swap' => 25,
        'liquidity' => 60,
        'lending' => 40,
        'convert' => 40,
        'bridge' => 90,
        'proposal' => 70,
        'vote' => 30,
        'comment' => 15,
        'staking' => 50,
        'onchain_profile' => 100,
        'launchpad' => 150,
    ],

    /*
    |--------------------------------------------------------------------------
    | Streak milestones
    |--------------------------------------------------------------------------
    |
    | Bonus XP the first time a consecutive-day streak reaches each length.
    |
    */

    'streak_bonuses' => [
        3 => 25,
        7 => 75,
        14 => 200,
        30 => 500,
        60 => 1000,
        100 => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quests
    |--------------------------------------------------------------------------
    |
    | `period` is daily (resets at UTC midnight) or weekly (ISO week).
    | `actions` lists the action keys that advance the quest; `target` is how
    | many are needed, `xp` the completion bonus on top of per-action XP.
    | `distinct_pages` quests count separate pages visited, not raw hits.
    |
    */

    'quests' => [
        [
            'key' => 'daily_visit',
            'period' => 'daily',
            'title' => ['en' => 'Jack in', 'ru' => 'Подключиться', 'zh' => '接入'],
            'description' => ['en' => 'Open Cyberia today.', 'ru' => 'Зайти в Cyberia сегодня.', 'zh' => '今天打开 Cyberia。'],
            'actions' => ['visit'],
            'target' => 1,
            'xp' => 10,
        ],
        [
            'key' => 'daily_explore',
            'period' => 'daily',
            'title' => ['en' => 'Walk the wired', 'ru' => 'Пройтись по сети', 'zh' => '走一遍线路'],
            'description' => ['en' => 'Visit 3 different sections.', 'ru' => 'Посетить 3 разных раздела.', 'zh' => '访问 3 个不同的板块。'],
            'actions' => ['page_view'],
            'target' => 3,
            'xp' => 20,
            'distinct_pages' => true,
        ],
        [
            'key' => 'daily_trade',
            'period' => 'daily',
            'title' => ['en' => 'Move value', 'ru' => 'Сделать обмен', 'zh' => '让价值流动'],
            'description' => ['en' => 'Swap once on the DEX.', 'ru' => 'Совершить один своп на DEX.', 'zh' => '在 DEX 上兑换一次。'],
            'actions' => ['swap'],
            'target' => 1,
            'xp' => 40,
        ],
        [
            'key' => 'weekly_trader',
            'period' => 'weekly',
            'title' => ['en' => 'Market maker', 'ru' => 'Маркет-мейкер', 'zh' => '做市商'],
            'description' => ['en' => 'Complete 5 swaps this week.', 'ru' => 'Совершить 5 свопов за неделю.', 'zh' => '本周完成 5 笔兑换。'],
            'actions' => ['swap'],
            'target' => 5,
            'xp' => 120,
        ],
        [
            'key' => 'weekly_liquidity',
            'period' => 'weekly',
            'title' => ['en' => 'Deepen the pool', 'ru' => 'Углубить пул', 'zh' => '把池子做深'],
            'description' => ['en' => 'Add liquidity once this week.', 'ru' => 'Добавить ликвидность за неделю.', 'zh' => '本周添加一次流动性。'],
            'actions' => ['liquidity'],
            'target' => 1,
            'xp' => 150,
        ],
        [
            'key' => 'weekly_governance',
            'period' => 'weekly',
            'title' => ['en' => 'Have a say', 'ru' => 'Влиять на решения', 'zh' => '说得上话'],
            'description' => ['en' => 'Take 3 governance actions.', 'ru' => 'Совершить 3 действия в управлении.', 'zh' => '完成 3 次治理操作。'],
            'actions' => ['vote', 'comment', 'proposal'],
            'target' => 3,
            'xp' => 100,
        ],
        [
            'key' => 'weekly_bridge',
            'period' => 'weekly',
            'title' => ['en' => 'Cross over', 'ru' => 'Перейти мост', 'zh' => '渡过去'],
            'description' => ['en' => 'Complete a bridge transfer.', 'ru' => 'Совершить перевод через мост.', 'zh' => '完成一次跨链转账。'],
            'actions' => ['bridge'],
            'target' => 1,
            'xp' => 180,
        ],
    ],

];
