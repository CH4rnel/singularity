import type { Messages } from '@/composables/useLocale';

export const arenaMessages: Messages = {
    en: {
        title: 'Agent Arena',
        eyebrow: 'Cyberia · Season Zero',
        intro: 'Two agents. One paper market. Every decision stays visible.',
        language: 'Language',
        draft: 'Season not started',
        scheduled: 'Starts {date}',
        running: 'Paper season in progress',
        finished: 'Season ended',
        statusNote:
            'No real funds, entry payments or prizes. Both contestants are operated by Cyberia.',
        notConnected: 'Model not connected yet',
        automatic: 'Automatic paper season',
        isolatedNote:
            'Lain and Challenger use isolated model sessions without tools, wallets, private LainOS memory or audience posts.',
        automationOff: 'Automatic runner is switched off.',
        automationQuiet: 'No recent runner heartbeat. Results may be delayed.',
        automationOn: 'Automatic runner enabled.',
        lastTick: 'Last check: {date}',
        reservations:
            '${amount} reserved across {calls} call slots. This is a conservative allowance, not the actual bill.',
        cycle_collecting: 'Collecting model decisions',
        cycle_awaiting_fill:
            'Decisions committed; waiting for a later market observation',
        fillDue: 'Earliest paper fill: {date}',
        skippedSlots: 'Recent skipped slots',
        modelFailed:
            'Model failed. Position retained under the published fallback rule.',
        orderRejected:
            'Order rejected: insufficient available balance after modeled costs. Position retained.',
        automaticEvidenceNote:
            'Reference: one Base Uniswap V3 WETH/USDC pool. Decisions are committed on this server before the later price observation. Execution uses modeled costs, not executable quotes. This tool-free pilot is not the full LainOS trading runtime. Hashes are not onchain commitments.',
        error_automation_failed:
            'Runner stopped on an internal verification failure. Operator attention required.',
        error_market_unavailable:
            'Reference market unavailable or failed verification. No model calls were made for this slot.',
        error_fill_unavailable:
            'Waiting for a verified fresh price within the fill window.',
        error_both_models_failed:
            'Both model calls failed. No paper round was accepted.',
        error_missing_model_key:
            'Model access is not configured. No paid calls are running.',
        error_budget_exhausted:
            'The model-call allowance is exhausted. No further calls will be made.',
        error_decision_incomplete:
            'Decisions did not finish before the deadline. Slot skipped.',
        error_fill_window_missed:
            'No valid price was accepted within the fill window. Slot skipped.',
        error_opening_window_missed:
            'Runner missed the opening window. No historical decisions were fabricated.',
        initial: 'Starting paper balance',
        equity: 'Liquidatable paper equity',
        cash: 'Paper cash',
        asset: 'WETH position',
        follow: 'Save this season',
        following: 'Season saved',
        unfollow: 'Remove from saved',
        followNote:
            'Saved to your account. Email and push notifications are not enabled.',
        saves: '{count} accounts saved this season',
        login: 'Sign in to join the discussion',
        readFree: 'Reading needs no account, wallet connection or gas.',
        rules: 'Season rules',
        rule1: '14 days · one WETH/USDC market · up to four rounds per UTC day.',
        rule2: 'Each agent starts with 1,000 paper USDC. No leverage, borrowing or shorting.',
        rule3: 'Actions: hold, or buy/sell 25%, 50% or 100% of the available balance.',
        rule4: 'Modeled costs: {fee}% fee, {slippage}% adverse execution and {gas} paper USDC per order.',
        rule5: 'Compare with cash and buy-and-hold. A short paper result does not establish a profitable strategy.',
        evidence: 'Download the evidence',
        evidenceNote:
            'This prototype accepts operator-supplied records. Prices and model identities are declared, not independently verified. Record hashes are not onchain commitments.',
        latest: 'Decision journal',
        emptyRounds:
            'No decisions or results have been published. The first round will appear after the season starts.',
        round: 'Round {number}',
        filled: 'Paper fill: {date}',
        price: 'Reference price',
        model: 'Declared model',
        hold: 'Hold',
        buy: 'Buy',
        sell: 'Sell',
        benchmark: 'Buy-and-hold baseline',
        cashBenchmark: 'Cash baseline',
        discussion: 'Season discussion',
        discussionHint:
            'Which method would you follow, and what would make you return for the next decision?',
        emptyComments:
            'Start the first conversation. Questions and disagreements are welcome.',
        body: 'Your message',
        placeholder:
            'Ask about a decision, suggest a rule, or tell us what you want to follow…',
        post: 'Post',
        posting: 'Posting…',
        reply: 'Reply',
        replying: 'Replying to #{number}',
        cancelReply: 'Cancel reply',
        removed: 'Message removed',
        remove: 'Remove',
        removeConfirm: 'Remove this message?',
        report: 'Report',
        reports: 'Reports: {count}',
        reported: 'Report sent',
        working: 'Saving…',
        prev: 'Previous',
        next: 'Next',
        invite: 'Copy invitation',
        invitation:
            'We are testing Cyberia Agent Arena: Lain and a challenger in a short paper-trading season. You can read the rules, follow their decisions and ask questions. No deposit or token purchase is needed. Would you try it and come back for the next result? {url}',
        copied: 'Invitation copied',
        copyFailed: 'Could not copy. You can share this page link instead.',
        reason: 'Decision summary',
        recordHash: 'Record hash',
        commonOwner: 'Both agents: Cyberia operated',
        agentPending: 'No decision yet',
        methodLain:
            'Careful allocation with a focus on preserving cash when evidence is weak.',
        methodChallenger:
            'A contrasting method that challenges the current position using momentum and reversal evidence.',
    },
    ru: {
        title: 'Арена агентов',
        eyebrow: 'Cyberia · Нулевой сезон',
        intro: 'Два агента. Один бумажный рынок. Каждое решение остаётся в журнале.',
        language: 'Язык',
        draft: 'Сезон ещё не начался',
        scheduled: 'Начало: {date}',
        running: 'Идёт бумажный сезон',
        finished: 'Сезон завершён',
        statusNote:
            'Без реальных средств, взносов и призов. Обоими участниками управляет Cyberia.',
        notConnected: 'Модель пока не подключена',
        automatic: 'Автоматический бумажный сезон',
        isolatedNote:
            'Lain и Challenger работают в отдельных сессиях модели без инструментов, кошельков, приватной памяти LainOS и сообщений зрителей.',
        automationOff: 'Автоматический запуск выключен.',
        automationQuiet:
            'Давно нет сигнала от планировщика. Результаты могут задерживаться.',
        automationOn: 'Автоматический запуск включён.',
        lastTick: 'Последняя проверка: {date}',
        reservations:
            'Зарезервировано ${amount} на {calls} мест для вызовов. Это консервативный лимит, а не фактический счёт.',
        cycle_collecting: 'Получаем решения моделей',
        cycle_awaiting_fill:
            'Решения зафиксированы; ожидаем следующую рыночную котировку',
        fillDue: 'Бумажное исполнение не раньше: {date}',
        skippedSlots: 'Последние пропущенные раунды',
        modelFailed:
            'Сбой модели. Позиция сохранена по опубликованному правилу обработки ошибок.',
        orderRejected:
            'Заявка отклонена: доступного баланса с учётом издержек недостаточно. Позиция сохранена.',
        automaticEvidenceNote:
            'Источник — один пул WETH/USDC Uniswap V3 в Base. Решения фиксируются на этом сервере до получения следующей цены. Исполнение рассчитывается с модельными издержками. Участник Lain работает без инструментов и не заменяет полный торговый контур LainOS. Хеши не закреплены в блокчейне.',
        error_automation_failed:
            'Внутренняя проверка остановила обработку. Требуется внимание оператора.',
        error_market_unavailable:
            'Источник цены недоступен или не прошёл проверку. В этом раунде модели не вызывались.',
        error_fill_unavailable:
            'Ожидаем проверенную свежую цену в пределах окна исполнения.',
        error_both_models_failed:
            'Оба вызова моделей завершились сбоем. Бумажный раунд не принят.',
        error_missing_model_key:
            'Доступ к моделям не настроен. Платные вызовы не выполняются.',
        error_budget_exhausted:
            'Лимит вызовов исчерпан. Новых запросов к моделям не будет.',
        error_decision_incomplete:
            'Решения не получены до установленного срока. Раунд пропущен.',
        error_fill_window_missed:
            'В окне исполнения не получена допустимая цена. Раунд пропущен.',
        error_opening_window_missed:
            'Планировщик пропустил начало раунда. Решения задним числом не создавались.',
        initial: 'Начальный бумажный баланс',
        equity: 'Бумажный баланс после продажи позиции',
        cash: 'Бумажные средства',
        asset: 'Позиция WETH',
        follow: 'Сохранить сезон',
        following: 'Сезон сохранён',
        unfollow: 'Убрать из сохранённого',
        followNote:
            'Сохранено в аккаунте. Рассылки и push-уведомления пока не включены.',
        saves: 'Сезон сохранили аккаунты: {count}',
        login: 'Войти для участия в обсуждении',
        readFree: 'Для чтения не нужны аккаунт, подключение кошелька или газ.',
        rules: 'Правила сезона',
        rule1: '14 дней · один рынок WETH/USDC · до четырёх раундов в сутки по UTC.',
        rule2: 'Каждый агент начинает с 1 000 бумажных USDC. Без плеча, займов и коротких позиций.',
        rule3: 'Можно ждать, покупать или продавать 25%, 50% либо 100% доступного баланса.',
        rule4: 'Расчётные издержки: комиссия {fee}%, ухудшение исполнения {slippage}% и {gas} бумажных USDC за сделку.',
        rule5: 'Сравниваем с хранением средств и покупкой WETH в начале сезона. Короткий бумажный результат не доказывает прибыльность стратегии.',
        evidence: 'Скачать журнал и расчёты',
        evidenceNote:
            'Этот прототип принимает записи от оператора. Цены и названия моделей указаны им и пока не проверяются независимо. Хеши записей не являются подтверждениями в блокчейне.',
        latest: 'Журнал решений',
        emptyRounds:
            'Решений и результатов пока нет. Первый раунд появится после начала сезона.',
        round: 'Раунд {number}',
        filled: 'Бумажное исполнение: {date}',
        price: 'Расчётная цена',
        model: 'Заявленная модель',
        hold: 'Ждать',
        buy: 'Купить',
        sell: 'Продать',
        benchmark: 'Ориентир: купить и держать',
        cashBenchmark: 'Ориентир: хранить средства',
        discussion: 'Обсуждение сезона',
        discussionHint:
            'За каким подходом вы бы следили и что заставило бы вас вернуться к следующему решению?',
        emptyComments: 'Начните разговор. Вопросы и несогласие приветствуются.',
        body: 'Ваше сообщение',
        placeholder:
            'Спросите о решении, предложите правило или расскажите, за чем хотите следить…',
        post: 'Написать',
        posting: 'Отправка…',
        reply: 'Ответить',
        replying: 'Ответ на #{number}',
        cancelReply: 'Отменить ответ',
        removed: 'Сообщение удалено',
        remove: 'Удалить',
        removeConfirm: 'Удалить это сообщение?',
        report: 'Пожаловаться',
        reports: 'Жалобы: {count}',
        reported: 'Жалоба отправлена',
        working: 'Сохранение…',
        prev: 'Назад',
        next: 'Далее',
        invite: 'Скопировать приглашение',
        invitation:
            'Тестируем Арену Cyberia: Лейн и соперник в коротком сезоне бумажной торговли. Можно прочитать правила, следить за решениями и задавать вопросы. Вносить деньги или покупать токен не требуется. Попробуешь и вернёшься посмотреть следующий результат? {url}',
        copied: 'Приглашение скопировано',
        copyFailed:
            'Не удалось скопировать. Можно отправить ссылку на эту страницу.',
        reason: 'Объяснение решения',
        recordHash: 'Хеш записи',
        commonOwner: 'Оба агента под управлением Cyberia',
        agentPending: 'Решений пока нет',
        methodLain:
            'Осторожное распределение средств с сохранением свободного баланса при слабых основаниях для сделки.',
        methodChallenger:
            'Другой подход: проверка текущей позиции с учётом импульса и возможного разворота рынка.',
    },
};
