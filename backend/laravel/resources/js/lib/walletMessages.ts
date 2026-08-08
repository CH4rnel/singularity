import type { Messages } from '@/composables/useLocale';

/**
 * Strings for the unified multichain wallet.
 *
 * Money moves here, so both languages have to say the same thing about custody
 * and about what is irreversible — a mistranslated warning is a lost wallet.
 * Neither language softens a consequence the other states plainly.
 */
export const walletMessages: Messages = {
    en: {
        // Chrome
        wallet: 'Wallet',
        eyebrow: 'One seed, every chain',
        intro: 'One seed phrase derives your accounts on Cyberia and every EVM network, on Solana, on Monero and in the Bitcoin family. The phrase is generated in your browser, encrypted with your password and stored on this device only — it never reaches Cyberia servers.',
        subtitle: 'NON-CUSTODIAL · EVM · SOL · XMR · BTC · LTC · +CUSTOM',
        back: 'Back',
        cancel: 'Cancel',
        continueLabel: 'Continue',
        retry: 'Retry',
        refresh: 'Refresh',
        navPortfolio: 'Portfolio',
        navActivity: 'Activity',
        navSecurity: 'Security',

        // Welcome
        welcomeHeadline: 'one key.\nevery network.\nyour custody.',
        welcomeBody:
            'One seed phrase derives your accounts on Cyberia and every EVM network, on Solana, on Monero and in the Bitcoin family. Keys are generated on this device and never leave it.',
        createWallet: 'Create wallet',
        importWallet: 'Import wallet',
        welcomeFinePrint: 'No account · no email · no recovery service',

        // Risk notice
        riskTitle: 'Before you create',
        riskBody:
            'This wallet is non-custodial. Read this — it is not a formality.',
        risk1: 'We never see your keys. There is no password reset and no support recovery.',
        risk2: 'Your seed phrase is the only backup. Lose it and the funds are gone.',
        risk3: 'Anyone who reads the phrase controls every balance in this wallet, on every network, at once.',
        riskAck:
            'I understand that I am solely responsible for storing my seed phrase.',
        generateSeed: 'Generate seed phrase',

        // Seed reveal
        stepOf: 'Step {step} / {total}',
        seedTitle: 'Your seed phrase',
        seedBody:
            'Write these {count} words on paper, in order. The screen stays covered until you hold it.',
        seedHidden: 'Hidden',
        seedHiddenHint: 'press and hold to reveal',
        holdToReveal: 'Hold to reveal',
        copyPhrase: 'Copy phrase',
        copiedLabel: 'Copied',
        clipboardClears: 'Clipboard clears in 30s',
        seedWarn:
            'Never keep the phrase in notes, photos or chats. A screenshot is a copy that someone else can find.',
        wroteItDown: 'I wrote it down',
        words12: '12 words',
        words24: '24 words',

        // Backup confirmation
        confirmBackupTitle: 'Confirm your backup',
        confirmBackupBody:
            'Select words {positions} in order. The full phrase is not shown here.',
        slotEmpty: 'tap a word below',
        confirmWrong:
            'That is not the right order. Tap a slot to clear it and try again.',
        confirmBackup: 'Confirm backup',

        // Import
        importTitle: 'Import a wallet',
        importBody:
            'Enter your 12 or 24-word phrase. It is checked on this device — nothing is sent anywhere.',
        importPlaceholder: 'word 01   word 02   word 03 …',
        importEmpty: 'Enter 12 or 24 words',
        importCount: '{count} words',
        importValid: 'Checksum valid',
        importInvalid: 'Checksum failed — check the words and their order',
        paste: 'Paste',
        willDerive: 'Will derive',

        // Vault password
        localVault: 'Local vault',
        passwordTitle: 'Set a vault password',
        passwordBody:
            'This password encrypts the keys stored on this device. It is not a recovery method.',
        password: 'Password',
        passwordAgain: 'Repeat',
        passwordMismatch: 'The two passwords do not match.',
        minChars: 'Min 8 characters',
        strengthUnset: 'Not set',
        strengthWeak: 'Too weak',
        strengthOk: 'Acceptable',
        strengthStrong: 'Strong',
        encryption: 'Encryption',
        encryptionValue: 'AES-256-GCM',
        keyDerivation: 'Key derivation',
        keyDerivationValue: 'PBKDF2-SHA-256 · 310 000 rounds',
        storage: 'Storage',
        storageValue: 'this device only',
        createVault: 'Create vault',
        openVault: 'Open vault',

        // Portfolio
        vaultTag: 'Vault',
        autoLock: 'Auto-lock',
        totalPortfolio: 'Total portfolio',
        priceSource: 'Prices: DexScreener · CoinGecko',
        pricePartial: 'Partial',
        priceMissing: '{count} of {total} networks unpriced',
        networks: 'Networks',
        derivedCount: '{count} derived',
        emptyTitle: 'No balances yet',
        emptyBody:
            'This vault is new. Receive assets to one of your addresses to get started.',
        showAddress: 'Show address',
        recent: 'Recent',
        rpcErrorTitle: '{chain} node unreachable',
        rpcErrorBody: 'Balances for this network may be missing or stale.',
        rpcErrorDismiss: 'Hide this notice',
        offlineTitle: 'No connection',
        offlineBody: 'Showing the last state read on this device.',
        unpriced: 'no price',
        groupEvm: 'EVM chains',
        groupOther: 'Other protocols',
        groupUtxo: 'Bitcoin family',
        addedByYou: 'Added by you',
        endpointUnverified: 'endpoint not verified',

        // Add network
        addNetwork: 'Add network',
        addNetworkHint: 'EVM chain or Bitcoin fork · same seed',
        addNetworkBody:
            'The same seed derives the new account. No key material is created, sent or re-entered.',
        addKindEvm: 'EVM chain',
        addKindEvmHint: 'chain id + RPC',
        addKindUtxo: 'Bitcoin fork',
        addKindUtxoHint: 'coin type + node',
        quickFill: 'Quick fill',
        knownForks: 'Known forks',
        networkNameLabel: 'Network name',
        coinNameLabel: 'Coin name',
        chainIdLabel: 'Chain id',
        symbolLabel: 'Symbol',
        tickerLabel: 'Ticker',
        rpcLabel: 'RPC endpoint · HTTPS only',
        explorerLabel: 'Block explorer · optional',
        slip44Label: 'SLIP-44',
        apiLabel: 'Esplora API · HTTPS only',
        apiHint:
            'A browser cannot speak the Electrum protocol, so this has to be an Esplora-compatible HTTPS API — the kind mempool.space and its forks serve.',
        addressTypeLabel: 'Address type',
        addrBech32: 'Native segwit',
        addrBech32Note: 'bc1… lowest fees',
        addrP2sh: 'Segwit / P2SH',
        addrP2shNote: '3… broad support',
        addrLegacy: 'Legacy',
        addrLegacyNote: '1… oldest nodes',
        prefixHrpLabel: 'bech32 prefix',
        prefixVersionLabel: 'Address version byte',
        prefixHint:
            'Decides what the address looks like. A wrong prefix produces a valid-looking address on the wrong chain.',
        derivationPath: 'Derivation path',
        derivationPathBody:
            'Derived locally from the vault you already unlocked. Your seed phrase is not shown or requested again.',
        addNetworkWarn:
            'A hostile endpoint can show wrong balances and wrong fees. Only add nodes you control or trust — Cyberia cannot verify them for you.',
        addEvmAction: 'Add EVM network',
        addForkAction: 'Derive fork account',
        errName: 'Give the network a name of at least two characters.',
        errSymbol: 'The ticker is 2 to 8 letters or digits.',
        errChainId: 'The chain id is a positive whole number.',
        errRpc: 'The RPC endpoint has to be an https:// URL.',
        errCoinType: 'The SLIP-44 coin type is a whole number.',
        errApi: 'The node endpoint has to be an https:// Esplora API.',
        errExplorer: 'The block explorer has to be an https:// URL.',
        errPrefix:
            'This address type needs a prefix: a bech32 prefix like "bc", or a version byte from 0 to 255.',
        errDuplicate:
            'A network with this identifier is already in this vault.',

        // Tokens
        tokens: 'Tokens',
        tokenCount: '{count} tokens',
        tokensEmpty: 'No tokens on this address yet.',
        tokensNoIndexer:
            'This network has no public index a browser can read without an API key, so tokens cannot be listed automatically. Add a contract below and it is read straight from the chain.',
        tokensUnavailable:
            'Tokens could not be listed: {reason}. Anything you added by hand is still shown.',
        addToken: 'Add token',
        tokenContract: 'Token contract address',
        hideToken: 'Hide',
        kToken: 'Token',
        insufficientGasTitle: 'Not enough {gas} for the fee',
        insufficientGasBody:
            'Moving a token is paid for in {gas}, not in {symbol}. You need {amount} {gas} more.',
        signSentenceToken:
            'Transfer {amount} {symbol} from your {chain} account to {to} on {network}, paying up to {fee} {gas} in network fees.',

        // Network detail
        balance: 'Balance',
        yourAddress: 'Your address',
        history: 'History',
        historyEmpty: 'No transfers on this address yet.',
        historyUnavailable:
            'History could not be read: {reason}. The explorer still has it.',
        historyUnsupported:
            'Monero history needs a view-key scan against a Monero node, which a browser cannot do. Restore this same phrase in a Monero wallet to see it.',
        historyNoIndexer:
            'This network has no public index a browser can read without an API key. The explorer has the full history.',
        historyNoEndpoint:
            'This network was added without a node endpoint, so there is nothing to read a history from. Add one in Security to see it.',
        sentTo: 'Sent to',
        receivedFrom: 'Received from',
        statusConfirmed: 'Confirmed',
        statusPending: 'Pending',
        statusFailed: 'Failed',
        loading: 'Reading…',

        // Receive
        receive: 'Receive',
        addressLabel: 'Address',
        copyAddress: 'Copy address',
        copiedClears: 'Copied · clears in 30s',
        expandAddress: 'Show the full address',
        qrLabel: '{chain} address as a QR code',
        qrCaption: '{chain} address · QR',
        warnEvm:
            'The same address is yours on every EVM network. Send only {chain} assets here (chain {chainId}) — anything sent on another EVM network is not lost, it is simply on that network, and you have to switch to it to see or spend it.',
        warnSolana:
            'Solana network only. Assets from another chain sent here cannot be recovered.',
        warnMonero:
            'Monero addresses are not interchangeable with any other network. This wallet can receive XMR but not spend it — restore the same phrase in a Monero wallet to send.',
        warnUtxo:
            '{chain} only. Bitcoin forks share address formats with each other, so an address that looks right can still belong to a different chain — check which chain you are being asked to pay before sending.',
        warnCustom:
            'You added this network yourself. Cyberia has not verified its endpoint and cannot tell you whether the balance, the fee or the confirmation it reports is true.',

        // Send
        send: 'Send',
        recipient: 'Recipient',
        addressValid: 'Valid {kind} address',
        addressInvalid: 'Not a valid {kind} address',
        amount: 'Amount',
        max: 'Max',
        balanceShort: 'Balance',
        insufficientTitle: 'Insufficient balance',
        insufficientBody:
            'You need {amount} {symbol} more, including the network fee.',
        networkFee: 'Network fee',
        feeSlow: 'Slow',
        feeNormal: 'Normal',
        feeFast: 'Fast',
        feeLoading: 'Reading the network…',
        feeUnavailable:
            'The network fee could not be read, so nothing can be signed yet.',
        youWillSign: 'You will sign',
        signSentence:
            'Transfer {amount} {symbol} from your {chain} account to {to} on {network}, paying up to {fee} {symbol} in network fees.',
        reviewTransaction: 'Review transaction',
        sendUnsupported:
            'This wallet cannot spend {chain} in a browser. Restore the same seed phrase in a {chain} wallet to send.',

        // Review and signing
        confirmTransaction: 'Confirm transaction',
        reviewBody: 'Check every line. Signed transactions cannot be reversed.',
        kNetwork: 'Network',
        kTo: 'To',
        kAmount: 'Amount',
        kFee: 'Network fee',
        kTotal: 'Total debit',
        plainLanguage: 'Plain language',
        nothingElse: 'Nothing else is authorised by this signature.',
        holdToSign: 'Hold to sign',

        // Transaction status
        txSigningLabel: 'Signing locally',
        txSigningTitle: 'Signing on this device',
        txSigningBody:
            'Your private key never leaves the vault. The transaction is being signed and broadcast.',
        txPendingLabel: 'Pending',
        txPendingTitle: 'Broadcast to the network',
        txPendingBody:
            'Waiting for confirmation. You can leave this screen — the transfer keeps going.',
        txConfirmedLabel: 'Accepted',
        txConfirmedTitle: 'Transaction broadcast',
        txConfirmedBody:
            'The network accepted the transaction. Balances update as it confirms.',
        txFailedLabel: 'Failed',
        txFailedTitle: 'Transaction not sent',
        txFailedBody: 'Nothing was transferred.',
        kVault: 'Vault',
        vaultUnlockedLocal: 'unlocked · local',
        kTxHash: 'Transaction',
        kReason: 'Reason',
        adjustRetry: 'Adjust and retry',
        backToPortfolio: 'Back to portfolio',
        viewInExplorer: 'View in explorer',

        // Security
        security: 'Security',
        vaultSection: 'Vault',
        backupSeed: 'Back up seed phrase',
        backupSeedHint: 'requires your password · never shown on the portfolio',
        showPhrase: 'Show phrase',
        autoLockHint: 'locks after inactivity',
        clipboardRow: 'Clear clipboard after copy',
        clipboardHint: '30 seconds, while this tab keeps focus',
        networksSection: 'Networks',
        builtinNetworks: 'Built-in networks',
        verified: 'Verified',
        removeNetwork: 'Remove',
        removeNetworkHint:
            'Removing a network only forgets its endpoint. The derived account stays recoverable from your seed.',
        addNetworkRow: 'Add EVM chain or Bitcoin fork',
        addNetworkRowHint: 'derives from the same seed · no re-entry',
        lockNow: 'Lock wallet now',
        lock: 'Lock',
        dangerZone: 'Danger zone',
        deleteVault: 'Delete local vault',
        deleteVaultBody:
            'Removes the encrypted keys from this device. Without your seed phrase the funds are unrecoverable.',
        deleteVaultAction: 'Delete vault',
        irreversible: 'Irreversible',
        deleteTitle: 'Delete this vault?',
        deleteBody:
            'The encrypted keys will be erased from this browser. Only your seed phrase can restore access.',
        typeToConfirm: 'Type {word} to confirm',
        deleteWord: 'DELETE',
        deleteConfirm: 'Delete',

        // Locked
        vaultLocked: 'Vault locked',
        enterPassword: 'Enter your password',
        unlock: 'Unlock',
        wrongPassword: 'Wrong wallet password.',
        forgotPassword: 'Forgot password → restore from seed',

        // Monero payouts
        useForPayouts: 'Use for bridge payouts',
        useForPayoutsDone: 'Saved as your payout address',
        useForPayoutsHint:
            'Saves this address on your profile so XMR bridge payouts land in this wallet.',
        signInForPayouts: 'Sign in to use it for payouts',
        noBalanceHere: 'Not readable in a browser',
        receiveOnly: 'Receive-only',
        path: 'Derivation path',
        openSite: 'Cyberia',
    },
    ru: {
        // Chrome
        wallet: 'Кошелёк',
        eyebrow: 'Одна сид-фраза, все сети',
        intro: 'Одна сид-фраза даёт счета в Cyberia и любой EVM-сети, в Solana, в Monero и в семействе Bitcoin. Фраза создаётся в браузере, шифруется вашим паролем и хранится только на этом устройстве — на серверы Cyberia она не попадает.',
        subtitle: 'НЕКАСТОДИАЛЬНЫЙ · EVM · SOL · XMR · BTC · LTC · +СВОИ',
        back: 'Назад',
        cancel: 'Отмена',
        continueLabel: 'Дальше',
        retry: 'Повторить',
        refresh: 'Обновить',
        navPortfolio: 'Портфель',
        navActivity: 'История',
        navSecurity: 'Безопасность',

        // Welcome
        welcomeHeadline: 'один ключ.\nвсе сети.\nваше хранение.',
        welcomeBody:
            'Одна сид-фраза даёт счета в Cyberia и любой EVM-сети, в Solana, в Monero и в семействе Bitcoin. Ключи создаются на этом устройстве и его не покидают.',
        createWallet: 'Создать кошелёк',
        importWallet: 'Импортировать кошелёк',
        welcomeFinePrint:
            'Без аккаунта · без почты · без службы восстановления',

        // Risk notice
        riskTitle: 'Прежде чем создавать',
        riskBody: 'Кошелёк некастодиальный. Прочитайте — это не формальность.',
        risk1: 'Мы не видим ваши ключи. Пароль не сбрасывается, поддержка ничего не восстановит.',
        risk2: 'Сид-фраза — единственная резервная копия. Потеряете её — потеряете средства.',
        risk3: 'Кто прочитает фразу, тот сразу распоряжается всеми балансами кошелька во всех сетях.',
        riskAck:
            'Я понимаю, что храню сид-фразу сам и отвечаю за это только я.',
        generateSeed: 'Создать сид-фразу',

        // Seed reveal
        stepOf: 'Шаг {step} / {total}',
        seedTitle: 'Ваша сид-фраза',
        seedBody:
            'Запишите эти {count} слов на бумаге, по порядку. Экран остаётся закрытым, пока вы не удержите кнопку.',
        seedHidden: 'Скрыто',
        seedHiddenHint: 'нажмите и удерживайте, чтобы показать',
        holdToReveal: 'Удерживайте, чтобы показать',
        copyPhrase: 'Скопировать фразу',
        copiedLabel: 'Скопировано',
        clipboardClears: 'Буфер очистится через 30 с',
        seedWarn:
            'Не храните фразу в заметках, фотографиях и переписках. Скриншот — это копия, которую найдёт кто-то другой.',
        wroteItDown: 'Записал',
        words12: '12 слов',
        words24: '24 слова',

        // Backup confirmation
        confirmBackupTitle: 'Подтвердите копию',
        confirmBackupBody:
            'Выберите слова {positions} по порядку. Полная фраза здесь не показывается.',
        slotEmpty: 'нажмите слово ниже',
        confirmWrong:
            'Порядок не совпадает. Нажмите на ячейку, чтобы очистить её, и попробуйте снова.',
        confirmBackup: 'Подтвердить копию',

        // Import
        importTitle: 'Импорт кошелька',
        importBody:
            'Введите фразу из 12 или 24 слов. Она проверяется на этом устройстве — никуда не отправляется.',
        importPlaceholder: 'слово 01   слово 02   слово 03 …',
        importEmpty: 'Введите 12 или 24 слова',
        importCount: 'слов: {count}',
        importValid: 'Контрольная сумма верна',
        importInvalid:
            'Контрольная сумма не сошлась — проверьте слова и порядок',
        paste: 'Вставить',
        willDerive: 'Будут выведены',

        // Vault password
        localVault: 'Локальное хранилище',
        passwordTitle: 'Задайте пароль хранилища',
        passwordBody:
            'Этим паролем шифруются ключи на этом устройстве. Это не способ восстановления.',
        password: 'Пароль',
        passwordAgain: 'Повторите',
        passwordMismatch: 'Пароли не совпадают.',
        minChars: 'Минимум 8 символов',
        strengthUnset: 'Не задан',
        strengthWeak: 'Слишком слабый',
        strengthOk: 'Приемлемый',
        strengthStrong: 'Надёжный',
        encryption: 'Шифрование',
        encryptionValue: 'AES-256-GCM',
        keyDerivation: 'Вывод ключа',
        keyDerivationValue: 'PBKDF2-SHA-256 · 310 000 итераций',
        storage: 'Хранение',
        storageValue: 'только это устройство',
        createVault: 'Создать хранилище',
        openVault: 'Открыть хранилище',

        // Portfolio
        vaultTag: 'Хранилище',
        autoLock: 'Автоблокировка',
        totalPortfolio: 'Портфель целиком',
        priceSource: 'Цены: DexScreener · CoinGecko',
        pricePartial: 'Частично',
        priceMissing: 'без цены сетей: {count} из {total}',
        networks: 'Сети',
        derivedCount: 'выведено: {count}',
        emptyTitle: 'Балансов пока нет',
        emptyBody:
            'Хранилище новое. Пополните любой из своих адресов, чтобы начать.',
        showAddress: 'Показать адрес',
        recent: 'Последние',
        rpcErrorTitle: 'Узел {chain} недоступен',
        rpcErrorBody: 'Баланс этой сети может отсутствовать или устареть.',
        rpcErrorDismiss: 'Скрыть уведомление',
        offlineTitle: 'Нет соединения',
        offlineBody: 'Показано последнее состояние, прочитанное на устройстве.',
        unpriced: 'нет цены',
        groupEvm: 'EVM-сети',
        groupOther: 'Другие протоколы',
        groupUtxo: 'Семейство Bitcoin',
        addedByYou: 'Добавлено вами',
        endpointUnverified: 'узел не проверен',

        // Add network
        addNetwork: 'Добавить сеть',
        addNetworkHint: 'EVM-сеть или форк Bitcoin · та же сид-фраза',
        addNetworkBody:
            'Новый счёт выводится из той же сид-фразы. Никакие ключи не создаются, не отправляются и не вводятся заново.',
        addKindEvm: 'EVM-сеть',
        addKindEvmHint: 'chain id + RPC',
        addKindUtxo: 'Форк Bitcoin',
        addKindUtxoHint: 'coin type + узел',
        quickFill: 'Быстрое заполнение',
        knownForks: 'Известные форки',
        networkNameLabel: 'Название сети',
        coinNameLabel: 'Название монеты',
        chainIdLabel: 'Chain id',
        symbolLabel: 'Символ',
        tickerLabel: 'Тикер',
        rpcLabel: 'RPC-эндпоинт · только HTTPS',
        explorerLabel: 'Обозреватель блоков · необязательно',
        slip44Label: 'SLIP-44',
        apiLabel: 'Esplora API · только HTTPS',
        apiHint:
            'Браузер не умеет в протокол Electrum, поэтому нужен HTTPS-API, совместимый с Esplora, — такой отдаёт mempool.space и его форки.',
        addressTypeLabel: 'Тип адреса',
        addrBech32: 'Native segwit',
        addrBech32Note: 'bc1… комиссия ниже всех',
        addrP2sh: 'Segwit / P2SH',
        addrP2shNote: '3… поддержка везде',
        addrLegacy: 'Legacy',
        addrLegacyNote: '1… самые старые узлы',
        prefixHrpLabel: 'Префикс bech32',
        prefixVersionLabel: 'Байт версии адреса',
        prefixHint:
            'Определяет вид адреса. Неверный префикс даст правдоподобный адрес не той сети.',
        derivationPath: 'Путь деривации',
        derivationPathBody:
            'Выводится локально из уже открытого хранилища. Сид-фразу мы не показываем и не спрашиваем снова.',
        addNetworkWarn:
            'Враждебный узел покажет неверный баланс и неверную комиссию. Добавляйте только те узлы, которыми управляете сами или которым доверяете, — Cyberia не может проверить их за вас.',
        addEvmAction: 'Добавить EVM-сеть',
        addForkAction: 'Вывести счёт форка',
        errName: 'Название сети — минимум два символа.',
        errSymbol: 'Тикер — от 2 до 8 букв или цифр.',
        errChainId: 'Chain id — целое положительное число.',
        errRpc: 'RPC-эндпоинт должен быть адресом https://.',
        errCoinType: 'Coin type по SLIP-44 — целое число.',
        errApi: 'Эндпоинт узла должен быть Esplora API по https://.',
        errExplorer: 'Обозреватель блоков должен быть адресом https://.',
        errPrefix:
            'Для этого типа адреса нужен префикс: bech32-префикс вроде «bc» или байт версии от 0 до 255.',
        errDuplicate: 'Сеть с таким идентификатором уже есть в хранилище.',

        // Tokens
        tokens: 'Токены',
        tokenCount: 'токенов: {count}',
        tokensEmpty: 'На этом адресе пока нет токенов.',
        tokensNoIndexer:
            'У этой сети нет публичного индекса, который браузер прочитает без API-ключа, поэтому список токенов не собрать автоматически. Добавьте контракт ниже — он читается прямо из сети.',
        tokensUnavailable:
            'Список токенов получить не удалось: {reason}. Добавленные вручную по-прежнему показаны.',
        addToken: 'Добавить токен',
        tokenContract: 'Адрес контракта токена',
        hideToken: 'Скрыть',
        kToken: 'Токен',
        insufficientGasTitle: 'Не хватает {gas} на комиссию',
        insufficientGasBody:
            'Перевод токена оплачивается в {gas}, а не в {symbol}. Не хватает {amount} {gas}.',
        signSentenceToken:
            'Перевод {amount} {symbol} со счёта {chain} на {to} в сети {network}, комиссия сети — до {fee} {gas}.',

        // Network detail
        balance: 'Баланс',
        yourAddress: 'Ваш адрес',
        history: 'История',
        historyEmpty: 'На этом адресе ещё не было переводов.',
        historyUnavailable:
            'Историю не удалось прочитать: {reason}. В обозревателе она есть.',
        historyUnsupported:
            'История Monero требует сканирования блоков с ключом просмотра на узле Monero — браузер так не умеет. Восстановите эту же фразу в кошельке Monero, чтобы её увидеть.',
        historyNoIndexer:
            'У этой сети нет публичного индекса, который браузер прочитает без API-ключа. Полная история есть в обозревателе.',
        historyNoEndpoint:
            'Эта сеть добавлена без узла, поэтому историю читать неоткуда. Укажите узел в «Безопасности», чтобы её увидеть.',
        sentTo: 'Отправлено на',
        receivedFrom: 'Получено от',
        statusConfirmed: 'Подтверждено',
        statusPending: 'В ожидании',
        statusFailed: 'Ошибка',
        loading: 'Читаем…',

        // Receive
        receive: 'Получить',
        addressLabel: 'Адрес',
        copyAddress: 'Скопировать адрес',
        copiedClears: 'Скопировано · очистится через 30 с',
        expandAddress: 'Показать адрес целиком',
        qrLabel: 'Адрес {chain} в виде QR-кода',
        qrCaption: 'Адрес {chain} · QR',
        warnEvm:
            'Этот адрес — ваш во всех EVM-сетях. Отправляйте сюда только активы {chain} (сеть {chainId}). Присланное в другой EVM-сети не пропадёт: оно просто в той сети, и чтобы его увидеть и потратить, нужно на неё переключиться.',
        warnSolana:
            'Только сеть Solana. Активы из другой сети восстановить не получится.',
        warnMonero:
            'Адреса Monero не взаимозаменяемы с другими сетями. Здесь кошелёк умеет принимать XMR, но не тратить — чтобы отправлять, восстановите эту же фразу в кошельке Monero.',
        warnUtxo:
            'Только сеть {chain}. У форков Bitcoin форматы адресов совпадают, поэтому правильный на вид адрес может принадлежать другой сети — проверьте, в какой сети у вас просят оплату.',
        warnCustom:
            'Эту сеть вы добавили сами. Cyberia не проверяла её узел и не может сказать, правду ли он сообщает о балансе, комиссии и подтверждении.',

        // Send
        send: 'Отправить',
        recipient: 'Получатель',
        addressValid: 'Адрес {kind} корректен',
        addressInvalid: 'Это не адрес {kind}',
        amount: 'Сумма',
        max: 'Всё',
        balanceShort: 'Баланс',
        insufficientTitle: 'Недостаточно средств',
        insufficientBody:
            'Не хватает {amount} {symbol} с учётом комиссии сети.',
        networkFee: 'Комиссия сети',
        feeSlow: 'Медленно',
        feeNormal: 'Обычно',
        feeFast: 'Быстро',
        feeLoading: 'Читаем сеть…',
        feeUnavailable:
            'Комиссию сети прочитать не удалось, поэтому подписывать пока нечего.',
        youWillSign: 'Вы подпишете',
        signSentence:
            'Перевод {amount} {symbol} со счёта {chain} на {to} в сети {network}, комиссия сети — до {fee} {symbol}.',
        reviewTransaction: 'Проверить перевод',
        sendUnsupported:
            'Этот кошелёк не умеет тратить {chain} в браузере. Восстановите ту же сид-фразу в кошельке {chain}, чтобы отправлять.',

        // Review and signing
        confirmTransaction: 'Подтвердите перевод',
        reviewBody: 'Проверьте каждую строку. Подписанный перевод не отменить.',
        kNetwork: 'Сеть',
        kTo: 'Кому',
        kAmount: 'Сумма',
        kFee: 'Комиссия сети',
        kTotal: 'Спишется всего',
        plainLanguage: 'Человеческим языком',
        nothingElse: 'Ничего другого эта подпись не разрешает.',
        holdToSign: 'Удерживайте для подписи',

        // Transaction status
        txSigningLabel: 'Подписываем локально',
        txSigningTitle: 'Подпись на этом устройстве',
        txSigningBody:
            'Приватный ключ не покидает хранилище. Транзакция подписывается и отправляется в сеть.',
        txPendingLabel: 'В ожидании',
        txPendingTitle: 'Отправлено в сеть',
        txPendingBody:
            'Ждём подтверждения. Экран можно закрыть — перевод продолжится.',
        txConfirmedLabel: 'Принято',
        txConfirmedTitle: 'Транзакция отправлена',
        txConfirmedBody:
            'Сеть приняла транзакцию. Балансы обновятся по мере подтверждения.',
        txFailedLabel: 'Ошибка',
        txFailedTitle: 'Транзакция не отправлена',
        txFailedBody: 'Ничего не переведено.',
        kVault: 'Хранилище',
        vaultUnlockedLocal: 'открыто · локально',
        kTxHash: 'Транзакция',
        kReason: 'Причина',
        adjustRetry: 'Исправить и повторить',
        backToPortfolio: 'Вернуться в портфель',
        viewInExplorer: 'Открыть в обозревателе',

        // Security
        security: 'Безопасность',
        vaultSection: 'Хранилище',
        backupSeed: 'Резервная копия сид-фразы',
        backupSeedHint: 'нужен пароль · в портфеле фраза не показывается',
        showPhrase: 'Показать фразу',
        autoLockHint: 'блокируется после простоя',
        clipboardRow: 'Очищать буфер после копирования',
        clipboardHint: '30 секунд, пока вкладка остаётся активной',
        networksSection: 'Сети',
        builtinNetworks: 'Встроенные сети',
        verified: 'Проверено',
        removeNetwork: 'Убрать',
        removeNetworkHint:
            'Удаление сети забывает только её узел. Сам счёт по-прежнему восстанавливается из сид-фразы.',
        addNetworkRow: 'Добавить EVM-сеть или форк Bitcoin',
        addNetworkRowHint: 'выводится из той же фразы · вводить ничего не надо',
        lockNow: 'Заблокировать кошелёк',
        lock: 'Заблокировать',
        dangerZone: 'Опасная зона',
        deleteVault: 'Удалить локальное хранилище',
        deleteVaultBody:
            'Удаляет зашифрованные ключи с этого устройства. Без сид-фразы средства не вернуть.',
        deleteVaultAction: 'Удалить хранилище',
        irreversible: 'Необратимо',
        deleteTitle: 'Удалить это хранилище?',
        deleteBody:
            'Зашифрованные ключи будут стёрты из этого браузера. Доступ вернёт только сид-фраза.',
        typeToConfirm: 'Введите {word} для подтверждения',
        deleteWord: 'DELETE',
        deleteConfirm: 'Удалить',

        // Locked
        vaultLocked: 'Хранилище заблокировано',
        enterPassword: 'Введите пароль',
        unlock: 'Разблокировать',
        wrongPassword: 'Неверный пароль кошелька.',
        forgotPassword: 'Забыли пароль → восстановить по сид-фразе',

        // Monero payouts
        useForPayouts: 'Использовать для выплат моста',
        useForPayoutsDone: 'Сохранён как адрес для выплат',
        useForPayoutsHint:
            'Сохраняет адрес в профиле, чтобы выплаты XMR из моста приходили в этот кошелёк.',
        signInForPayouts: 'Войдите, чтобы получать сюда выплаты',
        noBalanceHere: 'Нельзя прочитать в браузере',
        receiveOnly: 'Только приём',
        path: 'Путь деривации',
        openSite: 'Cyberia',
    },
};
