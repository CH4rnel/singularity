import type { Messages } from '@/composables/useLocale';

/**
 * Strings for the unified multichain wallet page. Money moves here, so both
 * languages have to say the same thing about custody and about what is
 * irreversible — a mistranslated warning is a lost wallet.
 */
export const walletMessages: Messages = {
    en: {
        wallet: 'Wallet',
        eyebrow: 'One seed, every chain',
        intro: 'One seed phrase, one address per chain — Cyberia and every EVM network, Solana, Monero. The phrase is generated in your browser, encrypted with your password and stored on this device only. It never reaches Cyberia servers.',
        create: 'Create a wallet',
        createHint:
            'A new 12-word phrase is generated on this device. Write it down before anything else — it is the only way back.',
        restore: 'Restore from a seed phrase',
        restoreHint:
            'Any BIP-39 phrase from another wallet works: the addresses below are derived on the standard paths.',
        password: 'Wallet password',
        passwordAgain: 'Repeat password',
        passwordHint:
            'At least 8 characters. It encrypts the phrase on this device and cannot be reset — there is no server copy.',
        passwordMismatch: 'The two passwords do not match.',
        seedPhrase: 'Seed phrase',
        words12: '12 words',
        words24: '24 words',
        unlock: 'Unlock',
        unlockHint:
            'Enter your wallet password to derive this device’s addresses.',
        lock: 'Lock',
        locked: 'Locked',
        wrongPassword: 'Wrong wallet password.',
        backupTitle: 'Write down your seed phrase',
        backupBody:
            'These words are the wallet. Anyone who reads them can spend everything; nobody can help you if you lose them. Write them on paper, never in a chat, never in a screenshot.',
        backupConfirm: 'I wrote it down',
        backup: 'Back up',
        backupHint: 'Shows the phrase again after re-entering your password.',
        hide: 'Hide',
        accounts: 'Accounts',
        address: 'Address',
        path: 'Derivation path',
        balance: 'Balance',
        refresh: 'Refresh balances',
        copy: 'Copy',
        copied: 'Copied',
        explorer: 'History',
        receive: 'Receive',
        receiveHint:
            'Send only this chain’s coins to this address. Coins from another chain sent here are lost.',
        send: 'Send',
        sendTo: 'Recipient address',
        amount: 'Amount',
        review: 'Review',
        confirmSend: 'Confirm and send',
        confirmTitle: 'Confirm this payment',
        confirmBody:
            'Check the address character by character. Once broadcast, a payment cannot be recalled.',
        cancel: 'Cancel',
        sending: 'Broadcasting…',
        sent: 'Sent',
        viewTx: 'View transaction',
        noSend: 'Receive-only here',
        forget: 'Remove from this device',
        forgetHint:
            'Deletes the encrypted phrase from this browser. Without your written backup the funds are gone.',
        forgetConfirm: 'Delete the wallet from this device?',
        useForPayouts: 'Use for bridge payouts',
        useForPayoutsDone: 'Saved as your payout address',
        useForPayoutsHint:
            'Saves this address on your profile so XMR bridge payouts land in this wallet.',
        noBalanceHere: 'Balance not readable in a browser',
    },
    ru: {
        wallet: 'Кошелёк',
        eyebrow: 'Одна сид-фраза, все сети',
        intro: 'Одна сид-фраза — по адресу в каждой сети: Cyberia и другие EVM-сети, Solana, Monero. Фраза создаётся в браузере, шифруется вашим паролем и хранится только на этом устройстве. На серверы Cyberia она не попадает.',
        create: 'Создать кошелёк',
        createHint:
            'Новая фраза из 12 слов создаётся на этом устройстве. Запишите её сразу — другого способа восстановить кошелёк нет.',
        restore: 'Восстановить по сид-фразе',
        restoreHint:
            'Подойдёт любая BIP-39 фраза из другого кошелька: адреса ниже выводятся по стандартным путям деривации.',
        password: 'Пароль кошелька',
        passwordAgain: 'Повторите пароль',
        passwordHint:
            'Минимум 8 символов. Им шифруется фраза на этом устройстве; восстановить пароль нельзя — копии на сервере нет.',
        passwordMismatch: 'Пароли не совпадают.',
        seedPhrase: 'Сид-фраза',
        words12: '12 слов',
        words24: '24 слова',
        unlock: 'Разблокировать',
        unlockHint:
            'Введите пароль кошелька, чтобы получить адреса на этом устройстве.',
        lock: 'Заблокировать',
        locked: 'Заблокирован',
        wrongPassword: 'Неверный пароль кошелька.',
        backupTitle: 'Запишите сид-фразу',
        backupBody:
            'Эти слова и есть кошелёк. Кто их прочитает — потратит всё; кто их потеряет — не восстановит ничего. Запишите на бумаге: не в переписке и не скриншотом.',
        backupConfirm: 'Записал',
        backup: 'Резервная копия',
        backupHint: 'Показывает фразу снова после повторного ввода пароля.',
        hide: 'Скрыть',
        accounts: 'Счета',
        address: 'Адрес',
        path: 'Путь деривации',
        balance: 'Баланс',
        refresh: 'Обновить балансы',
        copy: 'Копировать',
        copied: 'Скопировано',
        explorer: 'История',
        receive: 'Получить',
        receiveHint:
            'Отправляйте на этот адрес только монеты этой сети. Монеты из другой сети будут потеряны.',
        send: 'Отправить',
        sendTo: 'Адрес получателя',
        amount: 'Сумма',
        review: 'Проверить',
        confirmSend: 'Подтвердить и отправить',
        confirmTitle: 'Подтвердите перевод',
        confirmBody:
            'Сверьте адрес посимвольно. Отправленный перевод отменить невозможно.',
        cancel: 'Отмена',
        sending: 'Отправляем…',
        sent: 'Отправлено',
        viewTx: 'Посмотреть транзакцию',
        noSend: 'Здесь только приём',
        forget: 'Удалить с этого устройства',
        forgetHint:
            'Удаляет зашифрованную фразу из этого браузера. Без записанной копии средства пропадут.',
        forgetConfirm: 'Удалить кошелёк с этого устройства?',
        useForPayouts: 'Использовать для выплат моста',
        useForPayoutsDone: 'Сохранён как адрес для выплат',
        useForPayoutsHint:
            'Сохраняет адрес в профиле, чтобы выплаты XMR из моста приходили в этот кошелёк.',
        noBalanceHere: 'Баланс нельзя прочитать в браузере',
    },
};
