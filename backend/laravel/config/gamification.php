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
        /*
         * Every source here is credited from ground truth — the indexer, the
         * bridge table, the DAO tables — and none of them can be moved by a
         * browser saying so.
         *
         * `visit` and `comment` used to be on this list and are gone. They
         * were harmless while XP was a scoreboard and became untenable the
         * moment XP was spendable: opening a page is not work, and paying for
         * it meant a script could buy things. Removing them also removed the
         * need to carry two numbers and explain the difference, which was the
         * real cost.
         */
        'swap' => 25,
        'liquidity' => 60,
        'lending' => 40,
        'convert' => 40,
        'bridge' => 90,
        'proposal' => 70,
        'vote' => 30,
        'staking' => 50,
        'onchain_profile' => 100,
        'launchpad' => 150,
    ],

    /*
    |--------------------------------------------------------------------------
    | Enchantments: what experience is spent on
    |--------------------------------------------------------------------------
    |
    | Experience here works the way it works in a game: it is a currency, not a
    | rank. You accumulate it, you spend it on something permanent, and the
    | balance goes back down.
    |
    | Two numbers do two jobs and neither can do the other's. `xp` counts
    | everything and is the leaderboard — a record of taking part that is never
    | spent and never falls. **Spendable** is proven XP minus what has already
    | been spent, and it is the only thing an enchantment costs.
    |
    | `level` gates which enchantments are offered at all, and it is computed
    | from *lifetime* proven XP rather than from the balance. That is a
    | deliberate departure: spending should not take back the standing that
    | earned the right to spend, or somebody who buys the thing they qualified
    | for would immediately stop qualifying for it.
    |
    | `requires` names an enchantment that must already be owned, so a ladder
    | is climbed rather than skipped.
    |
    | Every effect here is something this server actually controls. Cyberia's
    | cut of a cross-chain swap is composed on this host; the inference API's
    | gate is a column on a key this host issues. Nothing promises a discount
    | on somebody else's costs.
    |
    */

    'enchantments' => [
        [
            'key' => 'route_i',
            'cost' => 400,
            'level' => 2,
            'title' => ['en' => 'Clean Route I', 'ru' => 'Чистый маршрут I', 'zh' => '净路 I'],
            'description' => [
                'en' => 'A quarter off Cyberia’s cut of every cross-chain swap. Permanent.',
                'ru' => 'Четверть от комиссии Cyberia на каждом кроссчейн-обмене. Навсегда.',
                'zh' => '永久减免 Cyberia 跨链兑换抽成的四分之一。',
            ],
            'effects' => ['crosschain_fee_discount' => 25],
        ],
        [
            'key' => 'route_ii',
            'cost' => 1200,
            'level' => 6,
            'requires' => 'route_i',
            'title' => ['en' => 'Clean Route II', 'ru' => 'Чистый маршрут II', 'zh' => '净路 II'],
            'description' => [
                'en' => 'Three fifths off Cyberia’s cut. Replaces the first.',
                'ru' => 'Три пятых от комиссии Cyberia. Заменяет первый уровень.',
                'zh' => '减免抽成的五分之三，取代第一级。',
            ],
            'effects' => ['crosschain_fee_discount' => 60],
        ],
        [
            'key' => 'route_iii',
            'cost' => 3600,
            'level' => 12,
            'requires' => 'route_ii',
            'title' => ['en' => 'Clean Route III', 'ru' => 'Чистый маршрут III', 'zh' => '净路 III'],
            'description' => [
                'en' => 'Cyberia takes nothing from your cross-chain swaps. Ever.',
                'ru' => 'Cyberia больше не берёт ничего с ваших кроссчейн-обменов. Никогда.',
                'zh' => 'Cyberia 不再从你的跨链兑换中抽成。',
            ],
            'effects' => ['crosschain_fee_discount' => 100],
        ],
        [
            'key' => 'lain_key',
            'cost' => 2000,
            'level' => 8,
            'title' => ['en' => 'Key to Lain', 'ru' => 'Ключ к Лейн', 'zh' => '通向 Lain 的钥匙'],
            'description' => [
                'en' => 'An inference API key that needs no $LAIN holding. Yours to keep.',
                'ru' => 'Ключ к Inference API, которому не нужен холдинг $LAIN. Остаётся у вас.',
                'zh' => '无需持有 $LAIN 的推理 API 密钥，永久归你。',
            ],
            'effects' => ['ai_access' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streak milestones
    |--------------------------------------------------------------------------
    |
    | Empty, and deliberately still here.
    |
    | A streak is worth showing — it is the one number on the profile about
    | persistence rather than volume — but it is earned by opening the app, and
    | XP is now a currency. Paying for attendance would put spendable value in
    | reach of anybody who can run a browser.
    |
    | Fill this in only if the streak is ever redefined to count days with an
    | on-chain action, which is a different and better thing to measure.
    |
    */

    'streak_bonuses' => [],

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
        /*
         * `daily_visit` and `daily_streak_keeper` used to sit here. Both paid
         * for showing up, which is exactly what stopped being worth paying
         * for. Every row on this board now costs a transaction.
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
            // `comment` is gone: it is free to produce and the quest bonus
            // is spendable, so a governance quest a comment could finish would
            // be a way to mint currency by typing.
            'actions' => ['vote', 'proposal'],
            'target' => 3,
            'xp' => 100,
        ],
        [
            'key' => 'daily_lend',
            'period' => 'daily',
            'title' => ['en' => 'Lend a hand', 'ru' => 'Дать взаймы', 'zh' => '出借一手'],
            'description' => [
                'en' => 'Supply, borrow or repay in the lending market.',
                'ru' => 'Внести, занять или вернуть в лендинге.',
                'zh' => '在借贷市场存入、借出或偿还。',
            ],
            'actions' => ['lending'],
            'target' => 1,
            'xp' => 50,
        ],
        [
            'key' => 'daily_stake',
            'period' => 'daily',
            'title' => ['en' => 'Lock it up', 'ru' => 'Запереть', 'zh' => '锁仓'],
            'description' => [
                'en' => 'Stake into a pool.',
                'ru' => 'Застейкать в пул.',
                'zh' => '质押到一个池子。',
            ],
            'actions' => ['staking'],
            'target' => 1,
            'xp' => 50,
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
