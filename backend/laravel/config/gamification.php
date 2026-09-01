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
    | Standing: the XP a level may be built on
    |--------------------------------------------------------------------------
    |
    | A level is about to be worth money, so it stops being a count of
    | everything and becomes a count of what the chain can vouch for.
    |
    | The browser can move `visit` and the exploration quest, which was
    | harmless while XP was a scoreboard and is not harmless the moment a level
    | discounts a fee: a script that opens pages would earn a discount. So
    | perks key off **proven** XP only — the sources credited from the
    | indexer, the bridge table and the DAO tables, never from a client's word.
    |
    | `xp` and the leaderboard are untouched: they still count everything,
    | because they are a record of participation rather than a claim on
    | anything.
    |
    */

    'proven_sources' => [
        'swap',
        'liquidity',
        'lending',
        'convert',
        'bridge',
        'staking',
        'launchpad',
        'proposal',
        'vote',
        'onchain_profile',
    ],

    /*
    |--------------------------------------------------------------------------
    | What a level is worth
    |--------------------------------------------------------------------------
    |
    | Keyed by the first proven level that earns the perk; lookup takes the
    | highest key that is <= the level, exactly like `titles`.
    |
    | `crosschain_fee_discount_bps` comes off Cyberia's own cut of a
    | cross-chain swap, which this server composes and therefore controls
    | completely. It is deliberately the first perk: it is money, it is
    | recurring, it costs nothing that was not earned by the same person's
    | trading, and it cannot be faked into existence because the standing
    | behind it is chain-verified.
    |
    | The discount is a proportion of our fee, never of the trade — at 100 the
    | swap is free of *our* cut, and the router's own costs are untouched
    | because they were never ours to waive.
    |
    */

    'perks' => [
        2 => ['crosschain_fee_discount' => 10],
        4 => ['crosschain_fee_discount' => 20],
        6 => ['crosschain_fee_discount' => 35],
        9 => ['crosschain_fee_discount' => 50],
        12 => ['crosschain_fee_discount' => 65],
        16 => ['crosschain_fee_discount' => 80],
        21 => ['crosschain_fee_discount' => 100],
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
        /*
         * `daily_explore` used to live here: visit three sections. It was the
         * one quest a browser could complete by wandering, it paid XP for
         * nothing anybody valued, and it made the board look busy while
         * asking for nothing. What replaced it asks for one real act a day,
         * from a list wide enough that most people already do one of them.
         */
        [
            'key' => 'daily_onchain',
            'period' => 'daily',
            'title' => ['en' => 'Touch the chain', 'ru' => 'Тронуть цепь', 'zh' => '触碰链上'],
            'description' => [
                'en' => 'Do one thing on-chain: swap, bridge, stake, lend or add liquidity.',
                'ru' => 'Сделать одно действие в цепи: обмен, мост, стейкинг, лендинг или ликвидность.',
                'zh' => '完成一次链上操作：兑换、跨链、质押、借贷或添加流动性。',
            ],
            'actions' => ['swap', 'bridge', 'staking', 'lending', 'liquidity', 'convert'],
            'target' => 1,
            'xp' => 30,
        ],
        [
            'key' => 'daily_streak_keeper',
            'period' => 'daily',
            'title' => ['en' => 'Hold the line', 'ru' => 'Удержать линию', 'zh' => '守住连续'],
            'description' => [
                'en' => 'Come back two days running.',
                'ru' => 'Зайти два дня подряд.',
                'zh' => '连续两天回来。',
            ],
            'actions' => ['streak_day'],
            'target' => 1,
            'xp' => 15,
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
            'key' => 'weekly_lender',
            'period' => 'weekly',
            'title' => ['en' => 'Put it to work', 'ru' => 'Заставить работать', 'zh' => '让它生息'],
            'description' => [
                'en' => 'Supply, borrow or repay in the lending market.',
                'ru' => 'Внести, занять или вернуть в лендинге.',
                'zh' => '在借贷市场存入、借出或偿还。',
            ],
            'actions' => ['lending'],
            'target' => 1,
            'xp' => 140,
        ],
        [
            'key' => 'weekly_staker',
            'period' => 'weekly',
            'title' => ['en' => 'Stand behind it', 'ru' => 'Встать за него', 'zh' => '为它背书'],
            'description' => [
                'en' => 'Stake into a pool.',
                'ru' => 'Застейкать в пул.',
                'zh' => '质押到一个池子。',
            ],
            'actions' => ['staking'],
            'target' => 1,
            'xp' => 130,
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
