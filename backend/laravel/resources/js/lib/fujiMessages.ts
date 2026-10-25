import type { Messages } from '@/composables/useLocale';

/** Strings for /fuji: Cyberia's Avalanche L1 on Fuji and its faucet. */
export const fujiMessages: Messages = {
    en: {
        eyebrow: 'Testnet · Avalanche L1',
        title: 'Cyberia Fuji',
        intro: 'Cyberia’s new chain, running as an Avalanche L1 on the Fuji testnet. Add it to your wallet, take test CYBER from the faucet and deploy whatever you like. Test coins have no value.',

        stepNetwork: 'Add the network',
        stepNetworkBody:
            'One click adds Cyberia Fuji to MetaMask and switches to it. Any other EVM wallet takes the same parameters by hand.',
        addToMetaMask: 'Add to MetaMask',
        addToWallet: 'Add to wallet',
        adding: 'Waiting for the wallet…',
        installMetaMask: 'Install MetaMask',
        noWallet:
            'No browser wallet found. Install MetaMask, or enter the parameters below in your wallet.',
        connected: 'Connected',
        onFuji: 'on Cyberia Fuji',
        otherChain: 'on another network',
        switchNow: 'Switch to Cyberia Fuji',
        balance: 'Balance',
        rejected: 'The wallet declined the request.',
        walletError: 'The wallet could not add the network: {message}',

        networkName: 'Network name',
        rpcUrl: 'RPC URL',
        chainId: 'Chain ID',
        currency: 'Currency symbol',
        explorer: 'Block explorer',
        explorerNone: 'none yet: leave empty',
        copy: 'Copy',
        copied: 'Copied',

        stepFaucet: 'Get test CYBER',
        stepFaucetBody:
            '{drip} CYBER per address, once every {hours} hours. That is about sixty transfers or one contract deployment.',
        addressLabel: 'Your address',
        addressPlaceholder: '0x…',
        useConnected: 'Use connected wallet',
        send: 'Send {drip} CYBER',
        sending: 'Sending…',
        sent: '{drip} CYBER sent.',
        sentPending: '{drip} CYBER sent; the block is still being confirmed.',
        transaction: 'Transaction',
        tank: 'The faucet wallet {address} holds {amount} CYBER.',
        faucetOff: 'The faucet is closed right now.',

        reason_invalid: 'That is not an EVM address.',
        reason_disabled: 'The faucet is closed right now.',
        reason_empty:
            'The faucet has run dry. It will be refilled; try again later.',
        reason_hasGas: 'This address already holds {drip} CYBER or more.',
        reason_coolingDown:
            'This address got its drip already. Next one in {wait}.',
        wait: '{hours} h {minutes} min',
        reason_quota:
            'Your connection has asked its share for today. Try again tomorrow.',
        reason_dailyCap:
            'The faucet has given out its day. Try again tomorrow.',
        reason_busy: 'Another drip is on its way. Try again in a moment.',
        reason_unreadable: 'The chain did not answer. Try again in a minute.',
        reason_tooMany: 'Too many requests. Wait a minute.',

        fees: 'Fees',
        feesBody:
            'A transfer costs {cost} CYBER: fees are priced like an Ethereum mainnet transfer, counted in CYBER.',
        feesToTreasury: 'Every fee goes to the DAO treasury {address}.',
        feesToProducer: 'Fees go to the validator that produced the block.',
        feesUnknown: 'The fee could not be read right now.',
    },
    ru: {
        eyebrow: 'Тестнет · Avalanche L1',
        title: 'Cyberia Fuji',
        intro: 'Новая сеть Сайберии — Avalanche L1 в тестнете Fuji. Добавьте её в кошелёк, возьмите тестовые CYBER в кране и деплойте что хотите. Тестовые монеты ничего не стоят.',

        stepNetwork: 'Добавить сеть',
        stepNetworkBody:
            'Одна кнопка добавляет Cyberia Fuji в MetaMask и переключает на неё. В другой EVM-кошелёк те же параметры вводятся вручную.',
        addToMetaMask: 'Добавить в MetaMask',
        addToWallet: 'Добавить в кошелёк',
        adding: 'Ждём кошелёк…',
        installMetaMask: 'Установить MetaMask',
        noWallet:
            'Кошелёк в браузере не найден. Установите MetaMask или введите параметры ниже в свой кошелёк.',
        connected: 'Подключён',
        onFuji: 'в Cyberia Fuji',
        otherChain: 'в другой сети',
        switchNow: 'Переключить на Cyberia Fuji',
        balance: 'Баланс',
        rejected: 'Кошелёк отклонил запрос.',
        walletError: 'Кошелёк не смог добавить сеть: {message}',

        networkName: 'Название сети',
        rpcUrl: 'RPC URL',
        chainId: 'Chain ID',
        currency: 'Символ валюты',
        explorer: 'Обозреватель блоков',
        explorerNone: 'пока нет — оставьте пустым',
        copy: 'Копировать',
        copied: 'Скопировано',

        stepFaucet: 'Получить тестовые CYBER',
        stepFaucetBody:
            '{drip} CYBER на адрес раз в {hours} ч. Это около шестидесяти переводов или один деплой контракта.',
        addressLabel: 'Ваш адрес',
        addressPlaceholder: '0x…',
        useConnected: 'Взять из кошелька',
        send: 'Отправить {drip} CYBER',
        sending: 'Отправляем…',
        sent: '{drip} CYBER отправлены.',
        sentPending: '{drip} CYBER отправлены, блок ещё подтверждается.',
        transaction: 'Транзакция',
        tank: 'В кошельке крана {address} осталось {amount} CYBER.',
        faucetOff: 'Кран сейчас закрыт.',

        reason_invalid: 'Это не EVM-адрес.',
        reason_disabled: 'Кран сейчас закрыт.',
        reason_empty: 'Кран опустел. Его пополнят — попробуйте позже.',
        reason_hasGas: 'На этом адресе уже {drip} CYBER или больше.',
        reason_coolingDown:
            'Этот адрес уже получил свою порцию. Следующая через {wait}.',
        wait: '{hours} ч {minutes} мин',
        reason_quota:
            'С вашего подключения сегодня уже брали. Попробуйте завтра.',
        reason_dailyCap: 'Кран раздал дневной лимит. Попробуйте завтра.',
        reason_busy:
            'Сейчас уходит другая выдача. Попробуйте через несколько секунд.',
        reason_unreadable: 'Сеть не ответила. Попробуйте через минуту.',
        reason_tooMany: 'Слишком много запросов. Подождите минуту.',

        fees: 'Комиссии',
        feesBody:
            'Перевод стоит {cost} CYBER: комиссии как у перевода в мейннете Ethereum, только в CYBER.',
        feesToTreasury: 'Все комиссии идут в казну DAO {address}.',
        feesToProducer: 'Комиссии получает валидатор, собравший блок.',
        feesUnknown: 'Комиссию сейчас не удалось прочитать.',
    },
};
