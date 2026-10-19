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
         * Everything a person does here, on the chain and off it.
         *
         * There was briefly a rule that only chain-verified sources may pay,
         * because XP was about to discount a real fee and a browser can move
         * `visit`. The rule was right and the premise was wrong: experience
         * buys *access to this project* — a room, a game, the right to write a
         * quest for somebody else — and none of that is worth farming. So the
         * DAO and the wall pay again, and nothing XP unlocks is allowed to be
         * serious enough to care that they do.
         */
        'visit' => 10,
        'post' => 20,
        'reaction' => 5,
        'comment' => 15,
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
    | Unlocks: what experience is spent on
    |--------------------------------------------------------------------------
    |
    | Experience works the way it works in a game: a currency, not a rank. You
    | gather it, you spend it on something permanent, and the balance goes back
    | down. `xp` stays whole — it is the leaderboard, a record of taking part
    | that never falls — and only the *spendable* balance moves.
    |
    | Everything here is **access to this project**, and that is the rule
    | rather than the current contents. XP is handed out for opening a page and
    | can be farmed, so it must never decide anything that moves money: no fee
    | discounts, no inference credits, nothing another person pays for. There
    | was a version of this list that discounted a real cross-chain fee, and it
    | forced the whole system to carry two kinds of XP to defend itself. Spend
    | it on rooms, games and the right to build something for other people
    | instead, where a farmed balance takes nothing from anybody.
    |
    | `level` gates what is offered; the balance is what it costs. Spending
    | must not take back the standing that earned the right to spend, so the
    | gate reads the lifetime number and the price reads the balance.
    |
    */

    /*
    | Where the NO CARRIER web export lives on this host.
    |
    | Outside `public/` on purpose: the controller serves it, so the unlock
    | covers the whole game rather than its front door. Not in the repository
    | either — it is a 40 MB Godot export, and committing that to serve twenty
    | people is the wrong trade. Unset or missing is a supported state and the
    | page says so.
    */

    'nocarrier_path' => (string) env('NOCARRIER_BUILD_PATH', storage_path('app/private/nocarrier')),

    'unlocks' => [
        [
            'key' => 'nocarrier',
            'cost' => 5000,
            'level' => 8,
            'title' => ['en' => 'NO CARRIER', 'ru' => 'NO CARRIER', 'zh' => 'NO CARRIER'],
            'description' => [
                'en' => 'The netstalking sim, playable in the browser. Yours for good.',
                'ru' => 'Симулятор нетсталкинга, играется в браузере. Остаётся навсегда.',
                'zh' => '可在浏览器中游玩的网络潜行模拟器，永久解锁。',
            ],
            'effects' => ['nocarrier' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streak milestones
    |--------------------------------------------------------------------------
    |
    | Bonus XP the first time a consecutive-day streak reaches each length.
    |
    | Attendance pays here, and that is a deliberate choice rather than an
    | oversight: coming back is the behaviour this whole system exists to
    | encourage, and what the XP buys is access to parts of the project rather
    | than anything a farmed balance could take from somebody else.
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
            'key' => 'daily_wall',
            'period' => 'daily',
            'title' => ['en' => 'Say something', 'ru' => 'Сказать что-нибудь', 'zh' => '说点什么'],
            'description' => [
                'en' => 'Post on the wall or react to somebody.',
                'ru' => 'Написать на стену или отреагировать на кого-то.',
                'zh' => '在墙上发帖或给别人一个反应。',
            ],
            'actions' => ['post', 'reaction', 'comment'],
            'target' => 1,
            'xp' => 20,
        ],
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
            'actions' => ['vote', 'comment', 'proposal'],
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
        [
            'key' => 'weekly_digger',
            'period' => 'weekly',
            'title' => ['en' => 'Dig deeper', 'ru' => 'Копнуть глубже', 'zh' => '再挖深一点'],
            'description' => [
                'en' => 'Find one secret in the layers under Settings.',
                'ru' => 'Найти один секрет в слоях под настройками.',
                'zh' => '在设置下面的层里找到一个秘密。',
            ],
            'actions' => ['secret'],
            'target' => 1,
            'xp' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Secrets: the layers under Settings
    |--------------------------------------------------------------------------
    |
    | The wallet's face is a plain wallet and carries no experience at all.
    | Progress lives *down*: Settings → Developer (layer 02) → Experiments
    | (layer 03) → the gallery (layer 07). Somebody who went looking in
    | Settings already wanted something, and that is who this is for.
    |
    | A secret is a phrase hidden somewhere — the browser console, robots.txt,
    | the theme template, a picture in the gallery, the terminal itself — and
    | typed into the terminal on layer 03. Opening a layer is a secret too,
    | with a code the wallet sends by itself.
    |
    | Only the hash of the answer lives here, because this repository is
    | public: sha256 of the phrase lowercased, apostrophes dropped and every
    | other run of non-letters folded into one space (see
    | GamificationService::secretHash). Each pays once per account, through
    | the same ledger as everything else (`source` = `secret`, `reference` =
    | the key), so it buys the same things everything else buys and nothing
    | that moves money.
    |
    | `kind` is `layer` or `word`. The title is withheld until it is found;
    | the hint is what somebody still looking is shown.
    |
    */

    'secrets' => [
        [
            'key' => 'layer_02',
            'kind' => 'layer',
            'hash' => '923ebe402801afd0462cd3abb21facb936edb15abee666d3a71b87b7134a25a7',
            'xp' => 25,
            'title' => ['en' => 'Layer 02', 'ru' => 'Слой 02', 'zh' => '第 02 层'],
            'hint' => [
                'en' => 'At the very bottom of Settings.',
                'ru' => 'В самом низу настроек.',
                'zh' => '在设置的最底部。',
            ],
        ],
        [
            'key' => 'layer_03',
            'kind' => 'layer',
            'hash' => '5f72e425b7aec5cd0c51cf97b9b4dbca03f09f1577afae6b315bbdd5db4a69e5',
            'xp' => 50,
            'title' => ['en' => 'Layer 03', 'ru' => 'Слой 03', 'zh' => '第 03 层'],
            'hint' => [
                'en' => 'Developer settings have a door further down.',
                'ru' => 'В настройках для разработчиков есть дверь ниже.',
                'zh' => '开发者设置里还有一扇更深的门。',
            ],
        ],
        [
            'key' => 'layer_07',
            'kind' => 'layer',
            'hash' => '60183d4c0c39830e65a9ab299b930a27ff2a04c9157b8bf7593e4e0b2dbf17d1',
            'xp' => 100,
            'title' => ['en' => 'Layer 07', 'ru' => 'Слой 07', 'zh' => '第 07 层'],
            'hint' => [
                'en' => 'The deepest layer opens at level 3.',
                'ru' => 'Самый глубокий слой открывается с уровня 3.',
                'zh' => '最深的一层在 3 级时打开。',
            ],
        ],
        [
            'key' => 'seven',
            'kind' => 'word',
            'hash' => '2f5e87743c776270549b5beeb7ccf1729faea48f36b00e7fcfc967ff94548e0d',
            'xp' => 50,
            'title' => ['en' => 'Seven taps', 'ru' => 'Семь нажатий', 'zh' => '七次点击'],
            'hint' => [
                'en' => 'Tap seven times on the line nobody reads.',
                'ru' => 'Семь раз нажми на строчку, которую никто не читает.',
                'zh' => '在没人读的那一行上点七下。',
            ],
        ],
        [
            'key' => 'present',
            'kind' => 'word',
            'hash' => '87cafc23bf1a5a0f126d774999ed85607802998cf7dbf61ca8e324a5a626f3ef',
            'xp' => 50,
            'title' => ['en' => 'Present day', 'ru' => 'Настоящий день', 'zh' => '现在的日子'],
            'hint' => [
                'en' => 'What day is it? What time is it?',
                'ru' => 'Какой сейчас день? Какое время?',
                'zh' => '今天是哪一天？现在是什么时间？',
            ],
        ],
        [
            'key' => 'navi',
            'kind' => 'word',
            'hash' => '21305f1bcd6152755435b325874ae7333e07065caeafec6dbfbf04a6869abfa9',
            'xp' => 75,
            'title' => ['en' => 'Hidden file', 'ru' => 'Скрытый файл', 'zh' => '隐藏文件'],
            'hint' => [
                'en' => 'ls does not show everything.',
                'ru' => 'ls показывает не всё.',
                'zh' => 'ls 并不显示一切。',
            ],
        ],
        [
            'key' => 'console',
            'kind' => 'word',
            'hash' => '56a48ae47598bf75baeea057c058fdd754ec0760aac271557be17c8e11aad37f',
            'xp' => 75,
            'title' => ['en' => 'The console', 'ru' => 'Консоль', 'zh' => '控制台'],
            'hint' => [
                'en' => 'The wallet talks to whoever opens the browser console.',
                'ru' => 'Кошелёк разговаривает с теми, кто открыл консоль браузера.',
                'zh' => '钱包会和打开浏览器控制台的人说话。',
            ],
        ],
        [
            'key' => 'robots',
            'kind' => 'word',
            'hash' => '9928843eb95501531cca2a1fb33ca40e924e0f3072724c8e3d0be318e9111fd9',
            'xp' => 75,
            'title' => ['en' => 'Not for robots', 'ru' => 'Не для роботов', 'zh' => '不给机器人看'],
            'hint' => [
                'en' => 'Ask what robots are told. Read it backwards.',
                'ru' => 'Спроси, что говорят роботам. Читай задом наперёд.',
                'zh' => '看看对机器人说了什么。倒着读。',
            ],
        ],
        [
            'key' => 'theme',
            'kind' => 'word',
            'hash' => 'ffeb23f8c72cbe93c636dc0eecbb1497facb92bf673fc2a69c70f9a9e8d52a2b',
            'xp' => 75,
            'title' => ['en' => 'The template', 'ru' => 'Шаблон', 'zh' => '模板'],
            'hint' => [
                'en' => 'Whoever makes their own theme reads the template to the end.',
                'ru' => 'Тот, кто делает свою тему, читает шаблон до конца.',
                'zh' => '自己做主题的人会把模板读到最后。',
            ],
        ],
        [
            'key' => 'noise',
            'kind' => 'word',
            'hash' => '5b5df1850499eb3b649f60ed3eca76c76734dcee000d08be943b470cb2458b4b',
            'xp' => 100,
            'title' => ['en' => 'In the noise', 'ru' => 'В шуме', 'zh' => '噪声里'],
            'hint' => [
                'en' => 'Look into the noise on layer 07.',
                'ru' => 'Вглядись в шум седьмого слоя.',
                'zh' => '盯着第 07 层的噪声看。',
            ],
        ],
    ],

];
