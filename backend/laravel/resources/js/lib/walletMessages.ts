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
        navAnalytics: 'Analytics',
        navSecurity: 'Security',
        navLain: 'Lain',
        tabWallet: 'Wallet',
        // Shorter than "Messages", because six labels share the width five had.
        tabChat: 'Chat',
        tabLaunch: 'Launch',
        tileTokensHint: 'ERC-20 · every network',
        tileAnalyticsHint: 'Allocation · flow',
        tileSecurityHint: 'Lock · keys',

        // Welcome
        welcomeHeadline: 'one key.\nevery network.\nyour custody.',
        welcomeBody:
            'One seed phrase derives your accounts on Cyberia and every EVM network, on Solana, on Monero and in the Bitcoin family. Keys are generated on this device and never leave it.',
        createWallet: 'Create wallet',
        importWallet: 'Import wallet',
        welcomeFinePrint: 'No account · no email · no recovery service',

        // Telegram Mini App
        tgCustody:
            'Keys stay in this device’s storage. Telegram never receives your seed phrase or your password.',
        tgNoSeedTitle: 'A new phrase is not shown here',
        tgNoSeedBody:
            'Creating a wallet means writing down twelve words, and a chat window is the wrong place to show them. Create one in the app or on the site, then import it here.',
        tgOpenSite: 'Open cyberia.church',
        tgStorageWarning:
            'This wallet lives in Telegram’s own storage. Clearing Telegram’s cache clears it, and your recovery phrase is what brings it back — so keep that phrase.',

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
        hidePhrase: 'Hide phrase',
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
        proxyOfferTitle: 'Is this network blocking Cyberia?',
        proxyOfferBody:
            'The desktop app can reach it through a proxy of your own.',
        proxySettings: 'Proxy settings',
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
        tokenByHand: 'Added by hand',
        tokensScreenBody:
            'Every token this vault holds, network by network. They share the address and the gas of the network they sit on — a token is not a chain of its own.',
        tokenValue: 'Token value',
        tokensTracked: '{count} tokens · {networks} networks',
        tokensUnpricedCount:
            '{count} of them have no price and are not in the total',
        tokensNoNetworks:
            'No network in this vault can hold tokens. Add an EVM chain and its tokens appear here.',
        tokenPrice: 'Price',
        tokenQuoteSource:
            'Priced from the Cyberia pools, through the same index the DEX reads. A pool price is what one trade would get, not a market rate.',
        tokenNoQuote:
            'Nothing here quotes this contract, so it has no price — which is not the same as being worth nothing. The balance below is exact.',
        tokenYourBalance: 'Your balance',
        tokenValueLabel: 'Value',
        tokenDecimals: 'Decimals',
        tokenListedBy: 'Listed by',
        tokenListedByIndex: "the chain's own index",
        tokenListedByYou: 'you, by contract',
        tokenContractLabel: 'Contract',
        tokenManualWarn:
            "Anyone can deploy a token with any name. A matching symbol is not proof of anything — check this contract against the project's own site before you send to it or trade it.",
        tokenGone:
            'This token is no longer on the list. A contract you hid, or one the index dropped once the balance reached zero.',
        insufficientGasTitle: 'Not enough {gas} for the fee',
        insufficientGasBody:
            'Moving a token is paid for in {gas}, not in {symbol}. You need {amount} {gas} more.',
        signSentenceToken:
            'Transfer {amount} {symbol} from your {chain} account to {to} on {network}, paying up to {fee} {gas} in network fees.',

        // Analytics
        analyticsBody:
            'Computed in this browser from balances it already read and prices this page already has. Nothing about what you hold is sent anywhere to be analysed.',
        netWorth: 'Net worth',
        analyticsPartial:
            '{count} holdings have no price, or could not be read at all, and are left out of this total',
        shareNetworks: 'In network coins',
        shareTokens: 'In tokens',
        allocation: 'Allocation',
        analyticsEmpty:
            'Nothing priced to break down yet. Receive assets, or add a network whose coin this page can quote.',
        flowWeek: 'Transfers · last 7 days',
        flowNote:
            'Counted from the {indexed} of {total} networks with an index a browser can read without an API key, and only for transfers their source dated.',
        statNetworks: 'Networks derived',
        statTokens: 'Tokens tracked',
        statLargest: 'Largest holding',
        statUnpriced: 'Left out of the total',
        statTransfers: 'Transfers · 7d',
        statSources: 'History sources',

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

        // Lain — the $LAIN holders' room
        lainTitle: 'Lain',
        lainIntro:
            'Cyberia’s resident intelligence, open to wallets holding {required}% or more of the live $LAIN supply. Your share is read from the contract here, in this browser — nothing is sent anywhere until you choose to open the room.',
        lainHolding: 'You hold',
        lainShare: 'Share of supply',
        lainRequired: 'Required',
        lainReading: 'Reading the contract…',
        lainOff: 'Lain is not wired up on this server yet.',
        lainReadFailed:
            'Could not read the $LAIN contract on Cyberia. This says nothing about your balance — only that the network did not answer.',
        lainShort:
            'The room is open to wallets holding {required}% of the live $LAIN supply. This account holds {share} — {amount} {symbol}.',
        lainShortHint:
            'The share is recomputed every time this screen is opened, so it follows both what you hold and what has been minted or burned.',
        lainQualifies: 'This account qualifies',
        lainSignBody:
            'Sign a challenge with this wallet’s Cyberia key to open the room. It moves no funds, approves no transaction and grants no allowance — it only proves that this browser holds the key behind the address.',
        lainSign: 'Hold to sign',
        lainSigning: 'Signing…',
        lainNoTools:
            'Lain has no tools in this room: she cannot read your balances, sign anything or move funds. Never send her — or anyone — your seed phrase.',
        lainEmpty: 'Say something. She is listening.',
        lainName: 'Lain',
        lainYou: 'You',
        lainThinking: 'Lain is thinking…',
        lainPlaceholder:
            'Write to Lain… (Enter sends, Shift+Enter is a new line)',
        lainSend: 'Send',
        lainStored: 'The conversation stays on this device.',
        lainForget: 'Forget conversation',
        lainUnreachable:
            'Lain is unreachable right now. Try again in a moment.',

        // Encrypted chat between wallets
        chatTitle: 'Messages',
        chatIntro:
            'Encrypted messages between wallets, addressed by EVM address. Everything is sealed and opened in this browser with a key derived from your account — Cyberia relays messages it cannot read.',
        chatNoAccount:
            'This account is watch-only. It has no key, so it can neither read nor write messages — the same reason it cannot spend.',
        chatOpenTitle: 'Open encrypted chat',
        chatOpenBody:
            'Two signatures, once: one publishes a messaging key others use to encrypt to you, the other proves this address so the relay hands over your mail. Both move no funds, approve no transaction and grant no allowance. The messaging key is derived from this account and is not the key that signs your transactions.',
        chatOpen: 'Hold to open',
        chatOpening: 'Signing…',
        chatYourAddress: 'Your address',
        chatFingerprintLabel: 'Key fingerprint',
        chatMetadataNote:
            'What is protected is the content. The relay still sees which addresses are talking and when, and it keeps envelopes for up to 30 days before deleting them. There is no forward secrecy: anyone who obtains this account’s key can read its past messages too.',
        chatE2ee: 'End-to-end encrypted',
        chatSyncing: 'Checking…',
        chatThreads: '{count} conversations',
        chatNew: 'New conversation',
        chatNewBody:
            'Both wallets have to have opened chat: an address is a hash, so there is nothing to encrypt to until its owner has published a key.',
        chatAddressLabel: 'Write to which address',
        chatStart: 'Open thread',
        chatLookingUp: 'Looking up the key…',
        chatInvalidAddress: 'That is not an EVM address.',
        chatNoKey: 'This address has not opened encrypted chat yet.',
        chatEmpty:
            'No conversations yet. Anyone who has opened chat can write to you at your EVM address.',
        chatThreadEmpty: 'Nothing here yet. Write the first message.',
        chatYou: 'You',
        chatPlaceholder:
            'Write a message… (Enter sends, Shift+Enter is a new line)',
        chatSend: 'Send',
        chatSending: 'Sealing…',
        chatUnreadable:
            'This message could not be opened — it is not what its envelope claims.',
        chatKeyChanged:
            'This address is publishing a different key than the one this device saw before. That is either a wallet restored somewhere new or someone attempting to sit in the middle — check the fingerprint with them before continuing.',
        chatStored:
            'Messages are stored on this device as ciphertext and opened only while the wallet is unlocked.',
        chatForget: 'Forget all conversations',

        // Accounts
        accounts: 'Accounts',
        accountsBody:
            'Accounts derived from your phrase live in one vault. Imported keys, imported phrases and watched addresses are marked — your backup does not cover them.',
        accountsFootnote:
            'The active account is the one every screen is about: portfolio, tokens, history, fees and anything you sign.',
        orphanTitle: 'This account has nowhere to be',
        orphanBody:
            'It was imported on a network that is no longer in this wallet. Add that network back to use it again, or switch to another account.',
        accountActive: 'Active',
        accountSwitch: 'Switch',
        accountKindSeed: 'From your phrase',
        accountKindPhrase: 'Imported phrase',
        accountKindKey: 'Imported key',
        accountKindWatch: 'Watch only',
        accountUse: 'Use',
        accountRename: 'Rename',
        accountForget: 'Forget',
        accountForgetSure: 'Forget it?',
        accountForgetSecret: 'This device holds the only copy.',
        accountForgetConfirm: 'Forget',
        accountPrimaryName: 'Main account',
        accountSeedName: 'Account {index}',
        accountPhraseName: 'Imported phrase',
        accountKeyName: 'Imported {chain} key',
        accountWatchName: 'Watched {chain} address',
        accountPathKey: 'no derivation path',
        accountPathWatch: 'public address',
        accountNotInBackup: 'Imported key · not in your seed backup',
        accountOwnPhrase: 'Its own phrase · back it up separately',
        accountWatchOnly: 'Watch only · cannot sign',
        accountDeriveNext: 'Derive next',
        accountSameSeed: 'Same phrase',
        accountImport: 'Import',
        accountImportHint: 'Phrase · key · watch',

        // Import an account
        importAccountTitle: 'Import an account',
        importAccountBody:
            'Everything here is checked in this browser and stored in the same encrypted vault as your phrase. Nothing is sent anywhere.',
        importKindPhrase: 'Seed phrase',
        importKindPhraseHint: '12 or 24 words',
        importKindKey: 'Private key',
        importKindKeyHint: 'one network',
        importKindWatch: 'Watch address',
        importKindWatchHint: 'no signing',
        importNetwork: 'Network',
        importKeyChainsNote:
            'Monero is not listed: nothing here can spend it, so an imported spend key would buy an address your phrase already derives.',
        importName: 'Account name · optional',
        importNamePlaceholder: 'Airdrop hunter',
        importSecret: 'Secret',
        importAddress: 'Address',
        importPlaceholderPhrase: 'word 01   word 02   word 03 …',
        importPlaceholderKey: 'The private key, as its own wallet exports it',
        importPlaceholderAddress: 'A public address to watch',
        importAwaitPhrase: 'Enter 12 or 24 words',
        importAwaitKey: 'Paste a private key',
        importAwaitAddress: 'Enter a public address',
        importPhraseProgress: '{count} words so far',
        importLooksValid: 'Looks valid',
        importUnrecognised: 'Format not recognised',
        importWarnPhrase:
            'A second phrase is its own root. Your existing backup does not restore it — write this one down separately or the accounts under it are gone with this device.',
        importWarnKey:
            'An imported key is not covered by your seed phrase. If you lose this device, only a separate backup of this key restores the account.',
        importWarnWatch:
            'A watched address can be tracked and received to, but nothing can ever be signed or sent from it here.',
        importAction: 'Import account',
        importWatchAction: 'Add watch account',

        // Launchpad
        launchpad: 'Launchpad',
        launchpadBody:
            'Fair launches on Cyberia. The coin that paid for a launch is burned into locked liquidity, so there is nothing to reserve and nothing to vest — a launch is a pool from the moment it exists.',
        launchpadLoading: 'Reading launches from the chain…',
        launchpadEmpty: 'Nothing has launched here yet.',
        launchpadUnreadable:
            'The Cyberia node did not answer. The launches are on chain either way.',
        launchLocked: 'locked',
        launchPrice: 'Price',
        launchValue: 'Price in USD',
        launchLiquidity: 'Locked liquidity',
        launchCap: 'Market cap',
        launchSupply: 'Supply',
        launchContract: 'Contract',
        launchLockedBody:
            'The liquidity behind this token was burned at launch. Nobody — including whoever launched it — can withdraw it.',
        launchRisk:
            'Locked liquidity is not an endorsement. Anyone can launch anything here, and a name or a symbol proves nothing about who made it.',
        launchTrade: 'Trade on the DEX',
        launchExplorer: 'Explorer',

        // Feed
        feed: 'Feed',
        feedBody:
            'Posts from across Cyberia and what the DAO recorded, newest first.',
        feedTabAll: 'All',
        feedTabPosts: 'Posts',
        feedTabDao: 'DAO',
        feedTagPost: 'Post',
        feedTagDao: 'DAO',
        feedProposalCreated: 'Opened a proposal',
        feedVoteCast: 'Voted',
        feedCommentPosted: 'Commented',
        feedSomeone: 'Someone',
        feedLoading: 'Loading the feed…',
        feedEmpty: 'The feed is quiet.',
        feedUnreadable: 'Could not reach Cyberia for the feed.',
        feedOpen: 'Open',
        feedOpenSite: 'On the site',
        feedReadOnly:
            'Reading only. This wallet has no account behind it — nothing here knows who you are — so posting and replying happen on the site.',

        // DAO
        dao: 'DAO',
        daoBody:
            'Every proposal and how the vote actually stands. The bar is voting power, not the number of voters.',
        daoProposals: 'Proposals',
        daoOpenCount: '{count} open',
        daoLoading: 'Loading proposals…',
        daoEmpty: 'No proposals yet.',
        daoUnreadable: 'Could not reach Cyberia for the proposals.',
        daoStatusOpen: 'Open',
        daoStatusClosed: 'Closed',
        daoNoDeadline: 'No deadline',
        daoNoVotes: 'No votes yet',
        daoFor: '{percent}% for',
        daoAgainst: '{percent}% against',
        daoCast: '{votes} votes · {comments} comments',
        daoCastShort: '{votes} votes',
        daoNoSession:
            'Voting is weighted by a token snapshot and recorded against an account, which this wallet does not have. Open the proposal on the site to cast a vote.',
        daoOpenToVote: 'Open to vote',

        // Profile
        profileTitle: 'Profile',
        profileYours: 'Your profile',
        profileAddress: 'Address',
        profileLoading: 'Loading the profile…',
        profileUnreadable: 'Could not reach Cyberia for this profile.',
        profileNoAddress: 'This account has no EVM address to look up.',
        profileUnclaimed: 'No account on Cyberia has claimed this address.',
        profileUnclaimedYours:
            'Nobody has claimed this address on Cyberia. Your wallet works either way — a profile only adds a public name, badges and the social side of the site.',
        profileClaim: 'Claim it on the site',
        profileOnchainName: 'Name owned on chain 49406',
        profilePosts: 'Posts',
        profileProposals: 'Proposals',
        profileVotes: 'Votes',
        profileAchievements: 'Achievements · {earned} of {total}',
        profileOpen: 'Open full profile',
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
        navAnalytics: 'Аналитика',
        tabWallet: 'Кошелёк',
        tabChat: 'Чат',
        tabLaunch: 'Запуск',
        tileTokensHint: 'ERC-20 · во всех сетях',
        tileAnalyticsHint: 'Доли · переводы',
        tileSecurityHint: 'Замок · ключи',
        navActivity: 'История',
        navSecurity: 'Безопасность',
        navLain: 'Лейн',

        // Welcome
        welcomeHeadline: 'один ключ.\nвсе сети.\nваше хранение.',
        welcomeBody:
            'Одна сид-фраза даёт счета в Cyberia и любой EVM-сети, в Solana, в Monero и в семействе Bitcoin. Ключи создаются на этом устройстве и его не покидают.',
        createWallet: 'Создать кошелёк',
        importWallet: 'Импортировать кошелёк',
        welcomeFinePrint:
            'Без аккаунта · без почты · без службы восстановления',

        // Telegram Mini App
        tgCustody:
            'Ключи остаются в хранилище этого устройства. Telegram не получает ни сид-фразу, ни пароль.',
        tgNoSeedTitle: 'Новая фраза здесь не показывается',
        tgNoSeedBody:
            'Создать кошелёк — значит выписать двенадцать слов, а окно чата для этого неподходящее место. Создайте кошелёк в приложении или на сайте и импортируйте его сюда.',
        tgOpenSite: 'Открыть cyberia.church',
        tgStorageWarning:
            'Этот кошелёк лежит в хранилище самого Telegram. Очистка кэша Telegram сотрёт его, и вернуть его можно только сид-фразой — берегите её.',

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
        hidePhrase: 'Скрыть фразу',
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
        proxyOfferTitle: 'Эта сеть блокирует Cyberia?',
        proxyOfferBody:
            'Настольное приложение может ходить через ваш собственный прокси.',
        proxySettings: 'Настройки прокси',
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
        tokenByHand: 'Добавлен вручную',
        tokensScreenBody:
            'Все токены этого хранилища, по сетям. Они живут на адресе своей сети и оплачиваются её газом — токен не отдельная сеть.',
        tokenValue: 'Стоимость токенов',
        tokensTracked: 'токенов: {count} · сетей: {networks}',
        tokensUnpricedCount: 'из них без цены: {count} — они не вошли в сумму',
        tokensNoNetworks:
            'Ни одна сеть в этом хранилище не хранит токены. Добавьте EVM-сеть — её токены появятся здесь.',
        tokenPrice: 'Цена',
        tokenQuoteSource:
            'Цена из пулов Cyberia, через тот же индекс, что читает DEX. Это цена сделки в пуле, а не рыночный курс.',
        tokenNoQuote:
            'Цену этого контракта здесь никто не даёт — это не значит, что он ничего не стоит. Баланс ниже точный.',
        tokenYourBalance: 'Ваш баланс',
        tokenValueLabel: 'Стоимость',
        tokenDecimals: 'Знаков после запятой',
        tokenListedBy: 'Откуда в списке',
        tokenListedByIndex: 'из индекса сети',
        tokenListedByYou: 'вы добавили по контракту',
        tokenContractLabel: 'Контракт',
        tokenManualWarn:
            'Развернуть токен с любым именем может кто угодно. Совпадение тикера ничего не доказывает — сверьте контракт с сайтом самого проекта, прежде чем отправлять на него или менять его.',
        tokenGone:
            'Этого токена больше нет в списке: вы его скрыли или индекс убрал его, когда баланс стал нулевым.',
        insufficientGasTitle: 'Не хватает {gas} на комиссию',
        insufficientGasBody:
            'Перевод токена оплачивается в {gas}, а не в {symbol}. Не хватает {amount} {gas}.',
        signSentenceToken:
            'Перевод {amount} {symbol} со счёта {chain} на {to} в сети {network}, комиссия сети — до {fee} {gas}.',

        // Analytics
        analyticsBody:
            'Всё посчитано в этом браузере: из уже прочитанных балансов и уже полученных цен. Ничего о ваших активах никуда не отправляется на анализ.',
        netWorth: 'Стоимость активов',
        analyticsPartial:
            'позиций без цены или вовсе не прочитанных: {count} — они не вошли в сумму',
        shareNetworks: 'В монетах сетей',
        shareTokens: 'В токенах',
        allocation: 'Распределение',
        analyticsEmpty:
            'Пока нечего раскладывать: нет активов с ценой. Получите средства или добавьте сеть, монету которой эта страница умеет оценивать.',
        flowWeek: 'Переводы · 7 дней',
        flowNote:
            'Считаем по {indexed} из {total} сетей, у которых есть индекс, читаемый браузером без API-ключа, и только по переводам с датой от источника.',
        statNetworks: 'Сетей выведено',
        statTokens: 'Токенов в списке',
        statLargest: 'Крупнейшая позиция',
        statUnpriced: 'Не вошло в сумму',
        statTransfers: 'Переводов · 7 дней',
        statSources: 'Источников истории',

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

        // Лейн — комната держателей $LAIN
        lainTitle: 'Лейн',
        lainIntro:
            'Разум Cyberia. Комната открыта кошелькам, у которых есть {required}% и больше живой эмиссии $LAIN. Доля читается прямо из контракта здесь, в браузере, — пока вы сами не откроете комнату, наружу не уходит ничего.',
        lainHolding: 'У вас',
        lainShare: 'Доля эмиссии',
        lainRequired: 'Нужно',
        lainReading: 'Читаю контракт…',
        lainOff: 'Лейн на этом сервере ещё не подключена.',
        lainReadFailed:
            'Не удалось прочитать контракт $LAIN в Cyberia. Это ничего не говорит о вашем балансе — только о том, что сеть не ответила.',
        lainShort:
            'Комната открыта кошелькам с {required}% живой эмиссии $LAIN. На этом счёте {share} — {amount} {symbol}.',
        lainShortHint:
            'Доля пересчитывается при каждом открытии экрана, поэтому она следует и за вашим балансом, и за тем, что было выпущено или сожжено.',
        lainQualifies: 'Этот счёт подходит',
        lainSignBody:
            'Подпишите вызов ключом Cyberia из этого кошелька, чтобы открыть комнату. Подпись не двигает средства, не подтверждает транзакцию и не даёт разрешений — она лишь доказывает, что ключ от адреса лежит в этом браузере.',
        lainSign: 'Держите, чтобы подписать',
        lainSigning: 'Подписываю…',
        lainNoTools:
            'В этой комнате у Лейн нет инструментов: она не может прочитать ваши балансы, что-то подписать или перевести средства. Никогда не отправляйте ей — и никому — сид-фразу.',
        lainEmpty: 'Скажите что-нибудь. Она слушает.',
        lainName: 'Лейн',
        lainYou: 'Вы',
        lainThinking: 'Лейн думает…',
        lainPlaceholder:
            'Написать Лейн… (Enter — отправить, Shift+Enter — новая строка)',
        lainSend: 'Отправить',
        lainStored: 'Переписка остаётся на этом устройстве.',
        lainForget: 'Забыть переписку',
        lainUnreachable: 'Лейн сейчас недоступна. Попробуйте через минуту.',

        // Encrypted chat between wallets
        chatTitle: 'Сообщения',
        chatIntro:
            'Зашифрованные сообщения между кошельками, адрес получателя — его EVM-адрес. Всё шифруется и расшифровывается в этом браузере ключом, выведенным из вашего счёта: Cyberia передаёт сообщения, которые не может прочитать.',
        chatNoAccount:
            'Этот счёт — только наблюдение. У него нет ключа, поэтому он не может ни читать, ни писать — по той же причине, по которой не может тратить.',
        chatOpenTitle: 'Открыть зашифрованный чат',
        chatOpenBody:
            'Две подписи, один раз: первая публикует ключ, которым вам будут шифровать, вторая доказывает адрес, чтобы узел отдал вашу почту. Обе не двигают средства, не подтверждают транзакции и не выдают разрешений. Ключ для переписки выведен из этого счёта и не является ключом, которым подписываются ваши транзакции.',
        chatOpen: 'Удерживайте, чтобы открыть',
        chatOpening: 'Подписываем…',
        chatYourAddress: 'Ваш адрес',
        chatFingerprintLabel: 'Отпечаток ключа',
        chatMetadataNote:
            'Защищено содержимое. Узел всё равно видит, какие адреса переписываются и когда, и хранит конверты до 30 дней, прежде чем удалить. Прямой секретности здесь нет: тот, кто получит ключ этого счёта, прочитает и прошлые сообщения.',
        chatE2ee: 'Сквозное шифрование',
        chatSyncing: 'Проверяем…',
        chatThreads: 'Переписок: {count}',
        chatNew: 'Новая переписка',
        chatNewBody:
            'Чат должен быть открыт с обеих сторон: адрес — это хеш, и пока его владелец не опубликовал ключ, шифровать не для кого.',
        chatAddressLabel: 'Кому писать',
        chatStart: 'Открыть переписку',
        chatLookingUp: 'Ищем ключ…',
        chatInvalidAddress: 'Это не EVM-адрес.',
        chatNoKey: 'Этот адрес ещё не открывал зашифрованный чат.',
        chatEmpty:
            'Переписок пока нет. Написать вам на ваш EVM-адрес может любой, кто открыл чат.',
        chatThreadEmpty: 'Здесь пока пусто. Напишите первое сообщение.',
        chatYou: 'Вы',
        chatPlaceholder:
            'Написать сообщение… (Enter — отправить, Shift+Enter — новая строка)',
        chatSend: 'Отправить',
        chatSending: 'Шифруем…',
        chatUnreadable:
            'Это сообщение не открылось — оно не то, чем себя объявляет конверт.',
        chatKeyChanged:
            'Этот адрес публикует не тот ключ, который устройство видело раньше. Либо кошелёк восстановили в новом месте, либо кто-то пытается встать посередине — сверьте отпечаток с собеседником, прежде чем продолжать.',
        chatStored:
            'Сообщения лежат на этом устройстве шифротекстом и открываются только пока кошелёк разблокирован.',
        chatForget: 'Забыть все переписки',

        // Accounts
        accounts: 'Счета',
        accountsBody:
            'Счета, выведенные из вашей фразы, живут в одном хранилище. Импортированные ключи, импортированные фразы и наблюдаемые адреса помечены — ваша резервная копия их не покрывает.',
        accountsFootnote:
            'Активный счёт — тот, о котором говорит каждый экран: портфель, токены, история, комиссии и всё, что вы подписываете.',
        orphanTitle: 'Этому счёту негде быть',
        orphanBody:
            'Он импортирован в сеть, которой больше нет в этом кошельке. Верните сеть, чтобы снова им пользоваться, или переключитесь на другой счёт.',
        accountActive: 'Активный',
        accountSwitch: 'Сменить',
        accountKindSeed: 'Из вашей фразы',
        accountKindPhrase: 'Импортированная фраза',
        accountKindKey: 'Импортированный ключ',
        accountKindWatch: 'Только наблюдение',
        accountUse: 'Выбрать',
        accountRename: 'Переименовать',
        accountForget: 'Забыть',
        accountForgetSure: 'Забыть его?',
        accountForgetSecret: 'Единственная копия — на этом устройстве.',
        accountForgetConfirm: 'Забыть',
        accountPrimaryName: 'Основной счёт',
        accountSeedName: 'Счёт {index}',
        accountPhraseName: 'Импортированная фраза',
        accountKeyName: 'Импортированный ключ {chain}',
        accountWatchName: 'Наблюдаемый адрес {chain}',
        accountPathKey: 'без пути деривации',
        accountPathWatch: 'публичный адрес',
        accountNotInBackup:
            'Импортированный ключ · его нет в резервной копии фразы',
        accountOwnPhrase: 'Своя фраза · сохраните её отдельно',
        accountWatchOnly: 'Только наблюдение · подписать нельзя',
        accountDeriveNext: 'Вывести следующий',
        accountSameSeed: 'Та же фраза',
        accountImport: 'Импорт',
        accountImportHint: 'Фраза · ключ · наблюдение',

        // Import an account
        importAccountTitle: 'Импорт счёта',
        importAccountBody:
            'Всё здесь проверяется в этом браузере и попадает в то же зашифрованное хранилище, что и ваша фраза. Никуда ничего не отправляется.',
        importKindPhrase: 'Сид-фраза',
        importKindPhraseHint: '12 или 24 слова',
        importKindKey: 'Приватный ключ',
        importKindKeyHint: 'одна сеть',
        importKindWatch: 'Наблюдение',
        importKindWatchHint: 'без подписи',
        importNetwork: 'Сеть',
        importKeyChainsNote:
            'Monero в списке нет: потратить её отсюда нельзя, поэтому импорт ключа траты дал бы адрес, который ваша фраза и так выводит.',
        importName: 'Название счёта · необязательно',
        importNamePlaceholder: 'Охотник за аирдропами',
        importSecret: 'Секрет',
        importAddress: 'Адрес',
        importPlaceholderPhrase: 'слово 01   слово 02   слово 03 …',
        importPlaceholderKey:
            'Приватный ключ в том виде, в каком его выдаёт свой кошелёк',
        importPlaceholderAddress: 'Публичный адрес для наблюдения',
        importAwaitPhrase: 'Введите 12 или 24 слова',
        importAwaitKey: 'Вставьте приватный ключ',
        importAwaitAddress: 'Введите публичный адрес',
        importPhraseProgress: 'Пока {count} слов',
        importLooksValid: 'Похоже на верный',
        importUnrecognised: 'Формат не распознан',
        importWarnPhrase:
            'Вторая фраза — самостоятельный корень. Существующая резервная копия её не восстановит: запишите эту фразу отдельно, иначе счета под ней исчезнут вместе с устройством.',
        importWarnKey:
            'Импортированный ключ не покрывается вашей сид-фразой. Если устройство потеряется, счёт вернёт только отдельная копия этого ключа.',
        importWarnWatch:
            'За наблюдаемым адресом можно следить и на него можно получать, но подписать или отправить с него отсюда нельзя никогда.',
        importAction: 'Импортировать счёт',
        importWatchAction: 'Добавить наблюдение',

        // Launchpad
        launchpad: 'Лаунчпад',
        launchpadBody:
            'Честные запуски в Cyberia. Монета, которой оплатили запуск, сжигается в заблокированную ликвидность: резервировать и вестить тут нечего — запуск сразу становится пулом.',
        launchpadLoading: 'Читаю запуски из сети…',
        launchpadEmpty: 'Здесь пока ничего не запускали.',
        launchpadUnreadable:
            'Нода Cyberia не ответила. На запуски в сети это никак не влияет.',
        launchLocked: 'заблокировано',
        launchPrice: 'Цена',
        launchValue: 'Цена в USD',
        launchLiquidity: 'Заблокированная ликвидность',
        launchCap: 'Капитализация',
        launchSupply: 'Эмиссия',
        launchContract: 'Контракт',
        launchLockedBody:
            'Ликвидность за этим токеном сожжена при запуске. Вывести её не может никто — включая того, кто запускал.',
        launchRisk:
            'Заблокированная ликвидность — не рекомендация. Запустить здесь может кто угодно что угодно, а название и тикер ничего не доказывают об авторе.',
        launchTrade: 'Торговать на DEX',
        launchExplorer: 'Обозреватель',

        // Feed
        feed: 'Лента',
        feedBody:
            'Записи со всей Cyberia и то, что зафиксировал DAO, — сначала свежее.',
        feedTabAll: 'Всё',
        feedTabPosts: 'Записи',
        feedTabDao: 'DAO',
        feedTagPost: 'Запись',
        feedTagDao: 'DAO',
        feedProposalCreated: 'Открыл предложение',
        feedVoteCast: 'Проголосовал',
        feedCommentPosted: 'Прокомментировал',
        feedSomeone: 'Кто-то',
        feedLoading: 'Загружаю ленту…',
        feedEmpty: 'В ленте тихо.',
        feedUnreadable: 'Не удалось получить ленту из Cyberia.',
        feedOpen: 'Открыть',
        feedOpenSite: 'На сайте',
        feedReadOnly:
            'Только чтение. За этим кошельком нет аккаунта — здесь никто не знает, кто вы, — поэтому писать и отвечать нужно на сайте.',

        // DAO
        dao: 'DAO',
        daoBody:
            'Все предложения и реальный расклад голосов. Полоса — это вес голосов, а не число проголосовавших.',
        daoProposals: 'Предложения',
        daoOpenCount: 'Открытых: {count}',
        daoLoading: 'Загружаю предложения…',
        daoEmpty: 'Предложений пока нет.',
        daoUnreadable: 'Не удалось получить предложения из Cyberia.',
        daoStatusOpen: 'Открыто',
        daoStatusClosed: 'Закрыто',
        daoNoDeadline: 'Без срока',
        daoNoVotes: 'Голосов пока нет',
        daoFor: '{percent}% за',
        daoAgainst: '{percent}% против',
        daoCast: 'Голосов: {votes} · комментариев: {comments}',
        daoCastShort: 'Голосов: {votes}',
        daoNoSession:
            'Вес голоса считается по снимку баланса токена и записывается на аккаунт, которого у этого кошелька нет. Чтобы проголосовать, откройте предложение на сайте.',
        daoOpenToVote: 'Открыть и проголосовать',

        // Profile
        profileTitle: 'Профиль',
        profileYours: 'Ваш профиль',
        profileAddress: 'Адрес',
        profileLoading: 'Загружаю профиль…',
        profileUnreadable: 'Не удалось получить этот профиль из Cyberia.',
        profileNoAddress: 'У этого счёта нет EVM-адреса для поиска.',
        profileUnclaimed:
            'Этот адрес не привязан ни к одному аккаунту Cyberia.',
        profileUnclaimedYours:
            'Этот адрес никто не привязал к аккаунту Cyberia. Кошельку это не мешает: профиль добавляет только публичное имя, значки и социальную часть сайта.',
        profileClaim: 'Привязать на сайте',
        profileOnchainName: 'Имя закреплено в сети 49406',
        profilePosts: 'Записи',
        profileProposals: 'Предложения',
        profileVotes: 'Голоса',
        profileAchievements: 'Достижения · {earned} из {total}',
        profileOpen: 'Открыть полный профиль',
    },
};
