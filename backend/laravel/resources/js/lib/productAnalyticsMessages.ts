import type { Messages } from '@/composables/useLocale';

/**
 * Strings for the wallet's product dashboard.
 *
 * English and Russian, like the rest of the operator-facing CRM: this screen
 * is read by the people who run Cyberia, and an English-only operator UI in
 * this project has already been shown to go unused.
 *
 * Definitions are written into the labels rather than left to a wiki. "Active"
 * on this page means something specific and non-obvious — a settled on-chain
 * action, not an app that was opened — and a metric whose definition is a
 * hover away is a metric two people will read two different ways.
 */
export const productAnalyticsMessages: Messages = {
    en: {
        title: 'Wallet analytics',
        description:
            'Acquisition → onboarding → funding → activation → retention, for anonymous wallet installations',
        backToCrm: 'CRM',
        siteFunnel: 'Site funnel',

        // Filters
        filters: 'Filters',
        range: 'Range',
        days7: '7d',
        days30: '30d',
        days90: '90d',
        platform: 'Platform',
        appVersion: 'App version',
        source: 'Source',
        campaign: 'Campaign',
        chain: 'Chain',
        any: 'Any',
        reset: 'Reset',

        // Headline
        northStar: 'Weekly Active Funded Users',
        northStarHint:
            'Funded installations with at least one settled on-chain action in the last 7 days. One installation counts once, however many addresses it holds.',
        newUsers: 'New users',
        newUsersHint: 'Installations first seen in this range',
        activatedUsers: 'Activated',
        activatedHint: 'First settled on-chain action, from the acquired cohort',
        wau: 'WAU',
        wauHint: 'Distinct installations with a settled action in the last 7 days',
        dau: 'DAU',
        mau: 'MAU',
        d7Retention: 'D7 retention',
        d7Hint: 'Newest cohort old enough to answer',
        activationRate: 'Activation rate',
        fundedRate: 'Funded rate',
        txSuccess: 'Transaction success',
        txSuccessHint: 'Confirmed ÷ (confirmed + failed), from the signature onwards',
        swapVolume: 'Swap volume',
        bridgeVolume: 'Bridge volume',
        sponsoredGas: 'Sponsored gas',
        sponsoredGasHint: 'Read from what the station actually released, not from the browser',
        returning: 'Returning',
        returningHint: 'Active in range, first seen before it',

        // Series
        activeOverTime: 'Active users over time',
        opened: 'Opened the app',
        active: 'Did something',
        newLine: 'New',
        noData: 'No data in this range',

        // Funnels
        mainFunnel: 'Main funnel',
        mainFunnelHint:
            'The cohort acquired in this range, followed forward. Retained = a settled action at least a day after activation.',
        step_first_open: 'First open',
        step_wallet: 'Wallet created or imported',
        step_funded: 'Funded',
        step_activated: 'Activated',
        step_retained: 'Retained',
        productFunnels: 'Product funnels',
        funnelUsersHint: 'Distinct users per step',
        funnel_swap: 'Swap',
        funnel_bridge: 'Bridge',
        funnel_transaction: 'Send',
        funnel_staking: 'Staking',
        funnel_gas: 'Sponsored gas',
        ofTop: 'of first step',
        ofPrevious: 'step',

        // Activation
        activation: 'Activation',
        cohort: 'Cohort',
        medianToFunding: 'Median time to funding',
        medianToFirstTx: 'Median time to first transaction',
        fundedOnchain: 'Confirmed on chain',
        fundedClaimed: 'Reported by client',
        fundedSplitHint:
            'A claim from a chain this server cannot read without an API key is counted apart, never merged in.',

        // Retention
        retention: 'Retention cohorts',
        retentionHint:
            'By week of activation. "Returned by day N" — any settled action from day 1 through day N. A bucket the cohort is too young for shows —, never 0%.',
        week: 'Week',
        size: 'Activated',

        // Acquisition
        acquisition: 'Acquisition',
        acquisitionHint:
            'First-touch: the campaign that acquired an installation keeps the credit. What matters here is not traffic but which sources bring people who use the wallet.',
        users: 'Users',
        wallets: 'Wallets',
        funded: 'Funded',
        activated: 'Activated',
        toActivation: '→ activation',
        direct: 'direct',

        // Product
        productUsage: 'Product usage',
        feature: 'Feature',
        actions: 'Actions',
        volume: 'Volume',
        successRate: 'Success',
        failures: 'Failures',
        feature_swap: 'Swap',
        feature_bridge: 'Bridge',
        feature_send: 'Send',
        feature_staking: 'Staking',
        feature_liquidity: 'Liquidity',
        feature_nft: 'NFT',

        // Errors
        errors: 'Errors',
        errorsHint:
            'Normalised codes, never raw messages — a message names an address and an amount, and no two nodes phrase the same failure alike.',
        event: 'Event',
        errorCode: 'Code',
        count: 'Count',

        // Gas
        gasTitle: 'Sponsored gas',
        gasHint:
            'Cost comes from gas_sponsorships — what the station actually released — never from a browser event.',
        gasDrips: 'Drips',
        gasAddresses: 'Addresses',
        gasUsers: 'Sponsored users',
        gasTotal: 'Total cost',
        gasPerUser: 'Per sponsored user',
        gasPerActivated: 'Per activated user',
        gasRequested: 'Requested',
        gasFailed: 'Refused',
        gasNoPrice: 'CYBER price unavailable — cost cannot be stated in USD',

        // Explorer
        recentUsers: 'Recent installations',
        installation: 'Installation',
        firstSeen: 'First seen',
        lastSeen: 'Last seen',
        status: 'Status',
        open: 'Open',
        userTitle: 'Installation',
        timeline: 'Timeline',
        sessions: 'Sessions',
        attribution: 'Attribution',
        milestones: 'Milestones',
        language: 'Language',
        referrer: 'Referrer',
        landingPath: 'Landing page',
        walletCreated: 'Wallet',
        firstTransaction: 'First transaction',
        linkedAddresses: 'Linked addresses',
        linkedAddressesHint:
            'Held to verify funding and to price a sponsored drip. Not shown: reading them by eye answers no question, and printing them would turn this into a way of matching a wallet to a visitor.',
        never: '—',
        noEvents: 'No events recorded',
        meaningfulMark: 'meaningful',
        started: 'Started',
        ended: 'Ended',
        activeNow: 'open',
    },

    ru: {
        title: 'Аналитика кошелька',
        description:
            'Привлечение → онбординг → пополнение → активация → удержание, по анонимным установкам кошелька',
        backToCrm: 'CRM',
        siteFunnel: 'Воронка сайта',

        filters: 'Фильтры',
        range: 'Период',
        days7: '7д',
        days30: '30д',
        days90: '90д',
        platform: 'Платформа',
        appVersion: 'Версия',
        source: 'Источник',
        campaign: 'Кампания',
        chain: 'Сеть',
        any: 'Любой',
        reset: 'Сбросить',

        northStar: 'Weekly Active Funded Users',
        northStarHint:
            'Пополненные установки, совершившие хотя бы одно завершённое on-chain действие за 7 дней. Одна установка считается один раз, сколько бы адресов она ни держала.',
        newUsers: 'Новые пользователи',
        newUsersHint: 'Установки, впервые увиденные в этом периоде',
        activatedUsers: 'Активированы',
        activatedHint:
            'Первое завершённое on-chain действие, из когорты этого периода',
        wau: 'WAU',
        wauHint:
            'Уникальные установки с завершённым действием за последние 7 дней',
        dau: 'DAU',
        mau: 'MAU',
        d7Retention: 'Удержание D7',
        d7Hint: 'Самая свежая когорта, дозревшая до ответа',
        activationRate: 'Конверсия в активацию',
        fundedRate: 'Конверсия в пополнение',
        txSuccess: 'Успешность транзакций',
        txSuccessHint:
            'Подтверждено ÷ (подтверждено + неудачно), начиная с подписи',
        swapVolume: 'Объём свопов',
        bridgeVolume: 'Объём мостов',
        sponsoredGas: 'Спонсируемый газ',
        sponsoredGasHint:
            'Считается по тому, что станция реально выдала, а не по событию из браузера',
        returning: 'Вернувшиеся',
        returningHint: 'Активны в периоде, впервые увидены до него',

        activeOverTime: 'Активные пользователи по дням',
        opened: 'Открыли приложение',
        active: 'Сделали действие',
        newLine: 'Новые',
        noData: 'Нет данных за период',

        mainFunnel: 'Основная воронка',
        mainFunnelHint:
            'Когорта, привлечённая в этом периоде, прослеженная вперёд. Удержан = завершённое действие минимум через сутки после активации.',
        step_first_open: 'Первый запуск',
        step_wallet: 'Кошелёк создан или импортирован',
        step_funded: 'Пополнен',
        step_activated: 'Активирован',
        step_retained: 'Удержан',
        productFunnels: 'Продуктовые воронки',
        funnelUsersHint: 'Уникальные пользователи на каждом шаге',
        funnel_swap: 'Своп',
        funnel_bridge: 'Мост',
        funnel_transaction: 'Отправка',
        funnel_staking: 'Стейкинг',
        funnel_gas: 'Спонсируемый газ',
        ofTop: 'от первого шага',
        ofPrevious: 'шаг',

        activation: 'Активация',
        cohort: 'Когорта',
        medianToFunding: 'Медиана до пополнения',
        medianToFirstTx: 'Медиана до первой транзакции',
        fundedOnchain: 'Подтверждено в сети',
        fundedClaimed: 'Со слов клиента',
        fundedSplitHint:
            'Заявка с сети, которую этот сервер не может прочитать без API-ключа, считается отдельно и никогда не смешивается.',

        retention: 'Когорты удержания',
        retentionHint:
            'По неделе активации. «Вернулся к дню N» — любое завершённое действие с 1-го по N-й день. Незрелая корзина показывает —, а не 0%.',
        week: 'Неделя',
        size: 'Активировано',

        acquisition: 'Привлечение',
        acquisitionHint:
            'First-touch: кампания, которая привела установку, сохраняет за собой заслугу. Важен не трафик, а какие источники приводят тех, кто реально пользуется кошельком.',
        users: 'Пользователи',
        wallets: 'Кошельки',
        funded: 'Пополнены',
        activated: 'Активированы',
        toActivation: '→ активация',
        direct: 'прямой',

        productUsage: 'Использование функций',
        feature: 'Функция',
        actions: 'Действий',
        volume: 'Объём',
        successRate: 'Успех',
        failures: 'Ошибок',
        feature_swap: 'Своп',
        feature_bridge: 'Мост',
        feature_send: 'Отправка',
        feature_staking: 'Стейкинг',
        feature_liquidity: 'Ликвидность',
        feature_nft: 'NFT',

        errors: 'Ошибки',
        errorsHint:
            'Нормализованные коды, никогда не сырые сообщения — сообщение называет адрес и сумму, и две ноды описывают одну и ту же ошибку по-разному.',
        event: 'Событие',
        errorCode: 'Код',
        count: 'Число',

        gasTitle: 'Спонсируемый газ',
        gasHint:
            'Стоимость берётся из gas_sponsorships — того, что станция реально выдала, — а не из события браузера.',
        gasDrips: 'Выдач',
        gasAddresses: 'Адресов',
        gasUsers: 'Проспонсировано пользователей',
        gasTotal: 'Суммарная стоимость',
        gasPerUser: 'На проспонсированного',
        gasPerActivated: 'На активированного',
        gasRequested: 'Запросов',
        gasFailed: 'Отказов',
        gasNoPrice: 'Цена CYBER недоступна — стоимость в USD не выводится',

        recentUsers: 'Последние установки',
        installation: 'Установка',
        firstSeen: 'Первый раз',
        lastSeen: 'Последний раз',
        status: 'Статус',
        open: 'Открыть',
        userTitle: 'Установка',
        timeline: 'Хронология',
        sessions: 'Сессии',
        attribution: 'Атрибуция',
        milestones: 'Вехи',
        language: 'Язык',
        referrer: 'Реферер',
        landingPath: 'Страница входа',
        walletCreated: 'Кошелёк',
        firstTransaction: 'Первая транзакция',
        linkedAddresses: 'Связанных адресов',
        linkedAddressesHint:
            'Хранятся, чтобы проверить пополнение и посчитать стоимость выданного газа. Не показываются: глазами по ним ничего не понять, а вывод превратил бы страницу в способ сопоставить кошелёк с посетителем.',
        never: '—',
        noEvents: 'Событий нет',
        meaningfulMark: 'значимое',
        started: 'Начата',
        ended: 'Завершена',
        activeNow: 'открыта',
    },
};
