import type { Messages } from '@/composables/useLocale';

/**
 * Strings for the unified multichain wallet.
 *
 * Money moves here, so every language has to say the same thing about custody
 * and about what is irreversible — a mistranslated warning is a lost wallet.
 * No language softens a consequence the others state plainly.
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
        launchBuy: 'Buy in the wallet',
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

        // Swap and wrap
        swapTitle: 'Swap',
        swapTab: 'Swap',
        wrapTab: 'Wrap',
        swapPay: 'You pay',
        swapReceive: 'You receive',
        swapPick: 'Select',
        swapPickAsset: 'Choose an asset',
        swapAdd: 'Add',
        swapByAddress: 'Or paste a contract address',
        swapByAddressNote:
            'The contract is read before it is offered: the symbol and the decimals come from the chain itself, not from any list.',
        swapFlip: 'Swap the two sides',
        swapSlippage: 'Max slippage',
        swapRate: 'Rate',
        swapMinOut: 'Minimum received',
        swapImpact: 'Price impact',
        swapRoute: 'Route',
        swapReview: 'Review swap',
        swapQuoting: 'Reading pools…',
        swapApproval:
            'Two transactions: an allowance for exactly this much {symbol}, then the swap. Both cost gas and both are in the fee above. This wallet never approves more than the trade needs, so nothing is left standing afterwards.',
        swapApprovalReset:
            'Three transactions: this token refuses to change an allowance that is not zero, so the leftover one is zeroed first, then set to exactly this much {symbol}, and then the swap runs. All of it is in the fee above.',
        swapApprovalTx: 'Allowance transaction',
        swapImpactWarn:
            'This trade moves the pool price by {impact}%. The pool is thin for an amount this size — a smaller amount pays a better rate.',
        swapNoDex:
            'No exchange is deployed on {chain}, so there is nothing here to trade against. Swapping works on the networks that run one.',
        swapOnNetwork: 'Swap on {chain}',
        swapWatchOnly:
            'This is a watched address: it can be quoted but never signed for. Importing the key is what makes it tradeable.',
        swapSentence:
            'Trade {amount} {from} for at least {min} {to} on {network}, paid out to this same wallet. Up to {fee} {gas} in gas. If the pool would pay less than that minimum, the whole swap reverts and nothing is spent.',
        wrapSentence:
            'Wrap {amount} {from} into {amount} {to} on {network}. One for one — the coin sits in the wrapper contract and you can take it back at any time. Up to {fee} {gas} in gas.',
        unwrapSentence:
            'Unwrap {amount} {from} back into {amount} {to} on {network}. One for one. Up to {fee} {gas} in gas.',
        wrapBody:
            '{coin} is the coin this chain runs on, not a token — pools, farms and most contracts take {wrapped} instead. Wrapping is one for one in both directions and costs only gas.',
        wrapUnavailable:
            'The wrapper contract on this network could not be read, so there is nothing here that can be named honestly. Try again once the network answers.',
        swapOutcome_signing: 'Signing',
        swapOutcome_approving: 'Allowance',
        swapOutcome_pending: 'Broadcast',
        swapOutcome_confirmed: 'Traded',
        swapOutcome_failed: 'Not executed',
        swapOutcomeBody_signing:
            'The transaction is being built and signed on this device.',
        swapOutcomeBody_approving:
            'Setting the allowance first. The swap is signed as soon as it is mined.',
        swapOutcomeBody_pending:
            'The transaction is in the network. Waiting for a block.',
        swapOutcomeBody_confirmed: 'Your {from} is now {to}.',
        swapOutcomeBody_failed: 'Nothing left the account.',

        // NFT, IPFS, torrents
        tabNft: 'NFT',
        tileIpfsHint: 'Pin a file · a page',
        tileTorrentHint: 'DHT · desktop app',

        nftTitle: 'NFT',
        nftBody: 'One collection anyone can mint into. What a token *is* lives entirely in the address it points at — usually a file in IPFS, sometimes just a link.',
        nftMint: 'Mint an NFT',
        nftLoading: 'Reading what this account holds…',
        nftEmpty: 'This account holds no NFTs on this network yet.',
        nftUnreadable:
            'The explorer did not answer, so what this account owns could not be listed.',
        nftOwned: 'Owned',
        nftMintedHere: 'Minted here',
        nftGatewayNote:
            'Images come from a public IPFS gateway, so that gateway sees which tokens are being looked at.',
        nftStandard: 'Standard',
        nftAmount: 'Amount',
        nftContract: 'Contract',
        nftPointsAt: 'Points at',
        nftExplorer: 'Explorer',
        nftExternal: 'Link',

        mintTitle: 'Mint an NFT',
        mintBody:
            'Two steps. The metadata is pinned to IPFS, then its address is written on chain — only the second costs gas, and only the second is permanent.',
        mintNoAccount:
            'This account has no address on the network the collection lives on.',
        mintWatchOnly:
            'This is a watched address. It can hold tokens; it has no key to sign a mint with.',
        mintCompose: 'Compose',
        mintDirect: 'Existing address',
        mintDirectBody:
            'Mint a token pointing at something already published. The string goes on chain verbatim — an ipfs:// address, a link, or a line of text.',
        mintName: 'Name',
        mintNamePlaceholder: 'What is this?',
        mintDescription: 'Description',
        mintImage: 'File',
        mintImageOptional: 'Optional — a token can be text or a link alone.',
        mintLink: 'Link',
        mintContinue: 'Continue',
        mintPreparing: 'Pinning and pricing…',
        mintPinNote:
            'The file and the metadata are pinned before anything is signed. Nothing about your keys or your account travels with them.',
        mintConfirmTitle: 'Confirm the mint',
        mintCollection: 'Network',
        mintUri: 'Token points at',
        mintFee: 'Fee, at most',
        mintPermanent:
            'This cannot be edited or removed later. The token stays in the collection, pointing at this address, for as long as the chain exists.',
        mintHold: 'Hold to mint',
        mintSentTitle: 'Mint sent',
        mintSentBody:
            'The transaction is on its way. It joins your collection once the chain has it and the explorer has read the metadata.',
        mintExplorer: 'Open the transaction',
        mintAnother: 'Mint another',

        ipfsTitle: 'IPFS',
        ipfsBody:
            'Publish a file or a page and get a CID — an address made of the content itself. Anyone holding the CID can fetch it from any node that has the bytes.',
        ipfsOff: 'This server is not pinning right now, so nothing can be published from here.',
        ipfsFile: 'File',
        ipfsPage: 'Page',
        ipfsFileBody: 'Any file: an image, audio, an archive, a document.',
        ipfsPageBody:
            'HTML, pinned as a whole site — the CID opens as a page rather than as a download.',
        ipfsUpTo: 'Up to {size}.',
        ipfsTooLarge: 'That is over {size}, which is all this server will pin.',
        ipfsPin: 'Publish',
        ipfsPinning: 'Publishing…',
        ipfsCid: 'CID',
        ipfsSize: 'Size',
        ipfsCopyUri: 'Copy ipfs://',
        ipfsOpen: 'Open',
        ipfsGatewayNote:
            'The CID is the address. The link opens it through a public gateway — one host among many that can serve the same bytes.',
        ipfsPersistenceNote:
            'Our node pins this now. Nothing here promises forever, so pin the CID somewhere of your own if it matters.',
        ipfsMintThis: 'Mint this as an NFT',
        ipfsAgain: 'Publish something else',
        ipfsRelayNote:
            'The bytes pass through this site because an IPFS node cannot be handed to a browser — it can run any command on the node. Nothing else in this wallet works that way.',

        torrentTitle: 'Torrents',
        torrentBrowserBody:
            'A real BitTorrent client runs inside the Cyberia desktop app. This tab cannot be one.',
        torrentWhyNot:
            'The DHT is UDP and peers are reached over TCP, and a web page has neither. The swarm a page can reach — browser peers over WebRTC — has almost no members for an ordinary magnet, so a downloader here would find nobody and make it look like your link was wrong.',
        torrentGetDesktop: 'Get the desktop app',
        torrentMobileNote:
            'The same is true in the mobile app and inside Telegram: both are web views.',
        torrentDesktopBody:
            'Mainline DHT, peer exchange and trackers — the same swarm any other client sees. Files land in the app’s download folder.',
        torrentSource: 'Magnet, info hash or .torrent link',
        torrentSourceHint:
            'magnet:, a 40-character info hash, or an https link to a .torrent',
        torrentBadSource:
            'That is not a magnet link, an info hash or an https .torrent link.',
        torrentAdd: 'Add torrent',
        torrentAdding: 'Adding…',
        torrentPrivacy:
            'Peers see your IP address, and the app’s proxy does not cover this traffic — that setting is for web requests, and these are raw sockets.',
        torrentFolder: 'Folder',
        torrentOpenFolder: 'Open the folder',
        torrentEmpty: 'Nothing is downloading.',
        torrentPause: 'Pause',
        torrentResume: 'Resume',
        torrentRemove: 'Remove',
        torrentRemoveKeep: 'Remove, keep files',
        torrentRemoveDelete: 'Remove and delete',
        torrentPeers: '{count} peers',
        torrentPinFile: 'Pin to IPFS',
        torrentMeta: 'fetching metadata',
        torrentDownloading: 'downloading',
        torrentSeeding: 'seeding',
        torrentPaused: 'paused',
        torrentError: 'stopped',
        torrentLawNote:
            'What you download and share is yours to answer for. The client makes no distinction between a Linux image and anything else.',
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
        launchBuy: 'Купить в кошельке',
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

        // Обмен и обёртка
        swapTitle: 'Обмен',
        swapTab: 'Обмен',
        wrapTab: 'Обёртка',
        swapPay: 'Отдаёте',
        swapReceive: 'Получаете',
        swapPick: 'Выбрать',
        swapPickAsset: 'Выберите актив',
        swapAdd: 'Добавить',
        swapByAddress: 'Или вставьте адрес контракта',
        swapByAddressNote:
            'Контракт читается до того, как попадёт в список: символ и разрядность берутся из самой сети, а не из какого-либо перечня.',
        swapFlip: 'Поменять стороны местами',
        swapSlippage: 'Допустимое проскальзывание',
        swapRate: 'Курс',
        swapMinOut: 'Минимум к получению',
        swapImpact: 'Влияние на цену',
        swapRoute: 'Маршрут',
        swapReview: 'Проверить обмен',
        swapQuoting: 'Читаем пулы…',
        swapApproval:
            'Две транзакции: разрешение ровно на эту сумму {symbol}, затем сам обмен. Обе стоят газа, и обе учтены в комиссии выше. Кошелёк никогда не выдаёт разрешение больше, чем нужно сделке, — после обмена ничего не остаётся.',
        swapApprovalReset:
            'Три транзакции: этот токен не разрешает менять ненулевое разрешение, поэтому старое сначала обнуляется, потом ставится ровно на эту сумму {symbol}, и только затем идёт обмен. Всё это учтено в комиссии выше.',
        swapApprovalTx: 'Транзакция разрешения',
        swapImpactWarn:
            'Сделка двигает цену пула на {impact}%. Для такой суммы пул тонкий — на меньшем объёме курс будет лучше.',
        swapNoDex:
            'В сети {chain} биржа не развёрнута, обменивать здесь не с чем. Обмен работает в сетях, где есть роутер.',
        swapOnNetwork: 'Обменять в сети {chain}',
        swapWatchOnly:
            'Это адрес только для наблюдения: курс он покажет, но подписать обмен нечем. Нужен импорт ключа.',
        swapSentence:
            'Обменять {amount} {from} минимум на {min} {to} в сети {network}, с зачислением на этот же кошелёк. Газа — до {fee} {gas}. Если пул даст меньше минимума, обмен целиком откатится и деньги останутся на месте.',
        wrapSentence:
            'Завернуть {amount} {from} в {amount} {to} в сети {network}. Один к одному — монета лежит в контракте обёртки, забрать её обратно можно в любой момент. Газа — до {fee} {gas}.',
        unwrapSentence:
            'Развернуть {amount} {from} обратно в {amount} {to} в сети {network}. Один к одному. Газа — до {fee} {gas}.',
        wrapBody:
            '{coin} — монета самой сети, а не токен: пулы, фермы и большинство контрактов принимают {wrapped}. Обёртка работает один к одному в обе стороны и стоит только газа.',
        wrapUnavailable:
            'Контракт обёртки в этой сети не читается, поэтому назвать честно нечего. Попробуйте снова, когда сеть ответит.',
        swapOutcome_signing: 'Подпись',
        swapOutcome_approving: 'Разрешение',
        swapOutcome_pending: 'Отправлено',
        swapOutcome_confirmed: 'Обменяно',
        swapOutcome_failed: 'Не выполнено',
        swapOutcomeBody_signing:
            'Транзакция собирается и подписывается на этом устройстве.',
        swapOutcomeBody_approving:
            'Сначала выставляется разрешение. Обмен подписывается сразу после того, как оно попадёт в блок.',
        swapOutcomeBody_pending: 'Транзакция в сети. Ждём блок.',
        swapOutcomeBody_confirmed: 'Ваш {from} теперь {to}.',
        swapOutcomeBody_failed: 'Со счёта ничего не ушло.',

        // NFT, IPFS, торренты
        tabNft: 'NFT',
        tileIpfsHint: 'Файл · страница',
        tileTorrentHint: 'DHT · десктоп',

        nftTitle: 'NFT',
        nftBody: 'Одна коллекция, минтить в неё может кто угодно. Чем токен *является*, целиком решает адрес, на который он указывает: обычно это файл в IPFS, иногда просто ссылка.',
        nftMint: 'Сминтить NFT',
        nftLoading: 'Читаю, что на этом счёте…',
        nftEmpty: 'На этом счёте в этой сети пока нет NFT.',
        nftUnreadable:
            'Обозреватель не ответил, поэтому список токенов этого счёта получить не удалось.',
        nftOwned: 'В собственности',
        nftMintedHere: 'Сминчено здесь',
        nftGatewayNote:
            'Картинки грузятся через публичный IPFS-шлюз, так что шлюз видит, какие токены вы смотрите.',
        nftStandard: 'Стандарт',
        nftAmount: 'Количество',
        nftContract: 'Контракт',
        nftPointsAt: 'Указывает на',
        nftExplorer: 'Обозреватель',
        nftExternal: 'Ссылка',

        mintTitle: 'Сминтить NFT',
        mintBody:
            'Два шага. Сначала метаданные пиннятся в IPFS, потом их адрес записывается в сеть — газ стоит только второй шаг, и необратим тоже только он.',
        mintNoAccount: 'У этого счёта нет адреса в сети, где живёт коллекция.',
        mintWatchOnly:
            'Это адрес только для наблюдения. Держать токены он может, подписать минт — нечем.',
        mintCompose: 'Собрать',
        mintDirect: 'Готовый адрес',
        mintDirectBody:
            'Сминтить токен, указывающий на что-то уже опубликованное. Строка попадает в сеть как есть — адрес ipfs://, ссылка или просто текст.',
        mintName: 'Название',
        mintNamePlaceholder: 'Что это?',
        mintDescription: 'Описание',
        mintImage: 'Файл',
        mintImageOptional: 'Необязательно — токен может быть текстом или ссылкой.',
        mintLink: 'Ссылка',
        mintContinue: 'Дальше',
        mintPreparing: 'Пинню и считаю комиссию…',
        mintPinNote:
            'Файл и метаданные пиннятся до того, как что-либо подписано. Ничего о ваших ключах и счёте вместе с ними не уходит.',
        mintConfirmTitle: 'Подтвердите минт',
        mintCollection: 'Сеть',
        mintUri: 'Токен указывает на',
        mintFee: 'Комиссия, не больше',
        mintPermanent:
            'Это нельзя будет изменить или удалить. Токен останется в коллекции и будет указывать на этот адрес столько, сколько существует сеть.',
        mintHold: 'Удерживайте, чтобы сминтить',
        mintSentTitle: 'Минт отправлен',
        mintSentBody:
            'Транзакция ушла в сеть. В коллекции токен появится, когда сеть его примет, а обозреватель прочитает метаданные.',
        mintExplorer: 'Открыть транзакцию',
        mintAnother: 'Сминтить ещё',

        ipfsTitle: 'IPFS',
        ipfsBody:
            'Опубликуйте файл или страницу и получите CID — адрес, собранный из самого содержимого. Имея CID, его можно забрать с любого узла, где лежат эти байты.',
        ipfsOff: 'Сервер сейчас не пиннит, поэтому опубликовать отсюда ничего нельзя.',
        ipfsFile: 'Файл',
        ipfsPage: 'Страница',
        ipfsFileBody: 'Любой файл: картинка, звук, архив, документ.',
        ipfsPageBody:
            'HTML, запиненный как целый сайт: CID открывается страницей, а не скачиванием.',
        ipfsUpTo: 'До {size}.',
        ipfsTooLarge: 'Это больше {size} — больше сервер не пиннит.',
        ipfsPin: 'Опубликовать',
        ipfsPinning: 'Публикую…',
        ipfsCid: 'CID',
        ipfsSize: 'Размер',
        ipfsCopyUri: 'Скопировать ipfs://',
        ipfsOpen: 'Открыть',
        ipfsGatewayNote:
            'Адрес — это CID. Ссылка открывает его через публичный шлюз, один из многих, кто может отдать те же байты.',
        ipfsPersistenceNote:
            'Сейчас это пиннит наш узел. Обещания «навсегда» здесь нет — если содержимое важно, запиньте CID и у себя.',
        ipfsMintThis: 'Сминтить это как NFT',
        ipfsAgain: 'Опубликовать ещё',
        ipfsRelayNote:
            'Байты идут через сайт, потому что IPFS-узел нельзя отдать браузеру — через него выполняется любая команда узла. Больше ничто в кошельке так не работает.',

        torrentTitle: 'Торренты',
        torrentBrowserBody:
            'Настоящий торрент-клиент работает в десктопном приложении Cyberia. Вкладка браузера им быть не может.',
        torrentWhyNot:
            'DHT — это UDP, а пиры — TCP, и у страницы нет ни того, ни другого. Рой, до которого страница дотягивается (браузерные пиры по WebRTC), для обычного магнета почти пуст — качалка здесь не нашла бы никого и выглядело бы это как «неправильная ссылка».',
        torrentGetDesktop: 'Скачать десктоп-приложение',
        torrentMobileNote:
            'В мобильном приложении и внутри Telegram то же самое: это тоже веб-вью.',
        torrentDesktopBody:
            'Mainline DHT, обмен пирами и трекеры — тот же рой, что видит любой другой клиент. Файлы падают в папку загрузок приложения.',
        torrentSource: 'Магнет, инфохеш или ссылка на .torrent',
        torrentSourceHint:
            'magnet:, инфохеш из 40 символов или https-ссылка на .torrent',
        torrentBadSource:
            'Это не магнет-ссылка, не инфохеш и не https-ссылка на .torrent.',
        torrentAdd: 'Добавить торрент',
        torrentAdding: 'Добавляю…',
        torrentPrivacy:
            'Пиры видят ваш IP, а прокси приложения этот трафик не закрывает — та настройка про веб-запросы, а здесь сырые сокеты.',
        torrentFolder: 'Папка',
        torrentOpenFolder: 'Открыть папку',
        torrentEmpty: 'Ничего не качается.',
        torrentPause: 'Пауза',
        torrentResume: 'Продолжить',
        torrentRemove: 'Убрать',
        torrentRemoveKeep: 'Убрать, файлы оставить',
        torrentRemoveDelete: 'Убрать и удалить',
        torrentPeers: 'пиров: {count}',
        torrentPinFile: 'Запинить в IPFS',
        torrentMeta: 'получаю метаданные',
        torrentDownloading: 'качается',
        torrentSeeding: 'раздаётся',
        torrentPaused: 'на паузе',
        torrentError: 'остановлен',
        torrentLawNote:
            'За то, что вы качаете и раздаёте, отвечаете вы. Клиент не отличает образ Linux от чего угодно другого.',
    },
    zh: {
        // Chrome
        wallet: '钱包',
        eyebrow: '一组助记词，所有链',
        intro: '一组助记词派生出你在 Cyberia 和所有 EVM 网络、Solana、Monero 以及比特币系上的账户。助记词在你的浏览器里生成，用你的密码加密，只保存在这台设备上 — 它从不会到达 Cyberia 的服务器。',
        subtitle: '非托管 · EVM · SOL · XMR · BTC · LTC · +自定义',
        back: '返回',
        cancel: '取消',
        continueLabel: '继续',
        retry: '重试',
        refresh: '刷新',
        navPortfolio: '资产',
        navActivity: '动态',
        navAnalytics: '分析',
        navSecurity: '安全',
        navLain: 'Lain',
        tabWallet: '钱包',
        // Shorter than "Messages", because six labels share the width five had.
        tabChat: '消息',
        tabLaunch: '发射台',
        tileTokensHint: 'ERC-20 · 所有网络',
        tileAnalyticsHint: '配置 · 流向',
        tileSecurityHint: '锁定 · 密钥',

        // Welcome
        welcomeHeadline: '一把密钥。\n所有网络。\n由你保管。',
        welcomeBody:
            '一组助记词派生出你在 Cyberia 和所有 EVM 网络、Solana、Monero 以及比特币系上的账户。密钥在这台设备上生成，绝不离开它。',
        createWallet: '创建钱包',
        importWallet: '导入钱包',
        welcomeFinePrint: '无需账号 · 无需邮箱 · 无找回服务',

        // Telegram Mini App
        tgCustody:
            '密钥留在这台设备的存储里。Telegram 永远拿不到你的助记词和密码。',
        tgStorageWarning:
            '这个钱包存放在 Telegram 自己的存储里。清理 Telegram 缓存就会把它清掉，而能把它找回来的只有你的助记词 — 所以务必保存好那组词。',

        // Risk notice
        riskTitle: '创建之前',
        riskBody: '这是一个非托管钱包。请读完下面几条 — 这不是走过场。',
        risk1: '我们永远看不到你的密钥。没有密码重置，也没有客服帮你找回。',
        risk2: '助记词是唯一的备份。丢了它，资金就没了。',
        risk3: '任何人只要读到这组词，就同时控制了这个钱包在所有网络上的全部余额。',
        riskAck: '我明白，保管助记词完全是我自己的责任。',
        generateSeed: '生成助记词',

        // Seed reveal
        stepOf: '第 {step} 步 / 共 {total} 步',
        seedTitle: '你的助记词',
        seedBody:
            '把这 {count} 个词按顺序抄在纸上。你不按住屏幕，它就一直遮着。',
        seedHidden: '已隐藏',
        seedHiddenHint: '按住以显示',
        holdToReveal: '按住显示',
        hidePhrase: '隐藏助记词',
        copyPhrase: '复制助记词',
        copiedLabel: '已复制',
        clipboardClears: '剪贴板将在 30 秒后清空',
        seedWarn:
            '绝不要把助记词留在备忘录、相册或聊天里。截图就是一份别人能找到的副本。',
        wroteItDown: '我已经抄下来了',
        words12: '12 个词',
        words24: '24 个词',

        // Backup confirmation
        confirmBackupTitle: '确认你的备份',
        confirmBackupBody: '按顺序选出第 {positions} 个词。这里不会显示完整助记词。',
        slotEmpty: '点下面的词',
        confirmWrong: '顺序不对。点一下格子可以清空，然后再试一次。',
        confirmBackup: '确认备份',

        // Import
        importTitle: '导入钱包',
        importBody:
            '输入你的 12 或 24 个词。校验在这台设备上完成 — 什么都不会被发送出去。',
        importPlaceholder: '第 01 个词   第 02 个词   第 03 个词 …',
        importEmpty: '请输入 12 或 24 个词',
        importCount: '{count} 个词',
        importValid: '校验和有效',
        importInvalid: '校验和不通过 — 请检查这些词和它们的顺序',
        paste: '粘贴',
        willDerive: '将派生出',

        // Vault password
        localVault: '本地保险库',
        passwordTitle: '设置保险库密码',
        passwordBody:
            '这个密码用来加密存在本设备上的密钥。它不是找回钱包的办法。',
        password: '密码',
        passwordAgain: '再输一次',
        passwordMismatch: '两次输入的密码不一致。',
        minChars: '至少 8 个字符',
        strengthUnset: '未设置',
        strengthWeak: '太弱',
        strengthOk: '可以接受',
        strengthStrong: '强',
        encryption: '加密',
        encryptionValue: 'AES-256-GCM',
        keyDerivation: '密钥派生',
        keyDerivationValue: 'PBKDF2-SHA-256 · 310 000 轮',
        storage: '存储',
        storageValue: '仅这台设备',
        createVault: '创建保险库',
        openVault: '打开保险库',

        // Portfolio
        vaultTag: '保险库',
        autoLock: '自动锁定',
        totalPortfolio: '资产总值',
        priceSource: '价格：DexScreener · CoinGecko',
        pricePartial: '不完整',
        priceMissing: '{total} 个网络中有 {count} 个没有价格',
        networks: '网络',
        derivedCount: '已派生 {count} 个',
        emptyTitle: '还没有余额',
        emptyBody: '这是一个新的保险库。往你的任一地址收一笔资产就可以开始了。',
        showAddress: '显示地址',
        recent: '最近',
        rpcErrorTitle: '{chain} 节点连不上',
        rpcErrorBody: '这个网络的余额可能缺失或是旧的。',
        rpcErrorDismiss: '隐藏这条提示',
        offlineTitle: '没有连接',
        offlineBody: '显示的是这台设备上一次读到的状态。',
        proxyOfferTitle: '这个网络是不是屏蔽了 Cyberia？',
        proxyOfferBody: '桌面版可以走你自己的代理连过去。',
        proxySettings: '代理设置',
        unpriced: '无价格',
        groupEvm: 'EVM 链',
        groupOther: '其他协议',
        groupUtxo: '比特币系',
        addedByYou: '你添加的',
        endpointUnverified: '节点未经验证',

        // Add network
        addNetwork: '添加网络',
        addNetworkHint: 'EVM 链或比特币分叉 · 同一组助记词',
        addNetworkBody:
            '新账户由同一组助记词派生。不会生成、发送或再次向你索要任何密钥材料。',
        addKindEvm: 'EVM 链',
        addKindEvmHint: '链 ID + RPC',
        addKindUtxo: '比特币分叉',
        addKindUtxoHint: '币种编号 + 节点',
        quickFill: '快速填入',
        knownForks: '已知分叉',
        networkNameLabel: '网络名称',
        coinNameLabel: '币种名称',
        chainIdLabel: '链 ID',
        symbolLabel: '符号',
        tickerLabel: '代号',
        rpcLabel: 'RPC 节点 · 仅限 HTTPS',
        explorerLabel: '区块浏览器 · 可选',
        slip44Label: 'SLIP-44',
        apiLabel: 'Esplora API · 仅限 HTTPS',
        apiHint:
            '浏览器不会说 Electrum 协议，所以这里必须填一个兼容 Esplora 的 HTTPS API — 也就是 mempool.space 及其分叉提供的那种。',
        addressTypeLabel: '地址类型',
        addrBech32: '原生隔离见证',
        addrBech32Note: 'bc1… 手续费最低',
        addrP2sh: '隔离见证 / P2SH',
        addrP2shNote: '3… 兼容性广',
        addrLegacy: '传统地址',
        addrLegacyNote: '1… 最老的节点',
        prefixHrpLabel: 'bech32 前缀',
        prefixVersionLabel: '地址版本字节',
        prefixHint:
            '它决定地址长什么样。前缀填错，会生成一个看起来没问题、却属于另一条链的地址。',
        derivationPath: '派生路径',
        derivationPathBody:
            '从你已经解锁的保险库在本地派生。不会显示、也不会再问你要助记词。',
        addNetworkWarn:
            '有恶意的节点可以给你看错误的余额和错误的手续费。只添加你自己掌握或信任的节点 — Cyberia 没法替你验证它们。',
        addEvmAction: '添加 EVM 网络',
        addForkAction: '派生分叉账户',
        errName: '给这个网络起个至少两个字符的名字。',
        errSymbol: '代号是 2 到 8 位字母或数字。',
        errChainId: '链 ID 是一个正整数。',
        errRpc: 'RPC 节点必须是 https:// 开头的地址。',
        errCoinType: 'SLIP-44 币种编号是一个整数。',
        errApi: '节点地址必须是 https:// 的 Esplora API。',
        errExplorer: '区块浏览器必须是 https:// 开头的地址。',
        errPrefix:
            '这种地址类型需要前缀：像 “bc” 这样的 bech32 前缀，或者 0 到 255 之间的版本字节。',
        errDuplicate: '这个保险库里已经有一个同样标识的网络了。',

        // Tokens
        tokens: '代币',
        tokenCount: '{count} 个代币',
        tokensEmpty: '这个地址上还没有代币。',
        tokensNoIndexer:
            '这个网络没有浏览器不带 API 密钥就能读的公开索引，所以代币没法自动列出来。在下面添加合约地址，它会直接从链上读。',
        tokensUnavailable:
            '代币列不出来：{reason}。你手动添加的仍然显示在这里。',
        addToken: '添加代币',
        tokenContract: '代币合约地址',
        hideToken: '隐藏',
        kToken: '代币',
        tokenByHand: '手动添加',
        tokensScreenBody:
            '这个保险库持有的所有代币，按网络分开。它们和所在网络共用同一个地址、同一份 gas — 代币不是一条自己的链。',
        tokenValue: '代币价值',
        tokensTracked: '{count} 个代币 · {networks} 个网络',
        tokensUnpricedCount: '其中 {count} 个没有价格，未计入总额',
        tokensNoNetworks:
            '这个保险库里没有能持有代币的网络。添加一条 EVM 链，它的代币就会出现在这里。',
        tokenPrice: '价格',
        tokenQuoteSource:
            '价格取自 Cyberia 的资金池，走的是 DEX 读的同一份索引。池子价格是一笔交易能换到的价，不是市场行情。',
        tokenNoQuote:
            '这里没有任何来源为这个合约报价，所以它没有价格 — 这和一文不值不是一回事。下面的余额是准确的。',
        tokenYourBalance: '你的余额',
        tokenValueLabel: '价值',
        tokenDecimals: '小数位',
        tokenListedBy: '列出方',
        tokenListedByIndex: '这条链自己的索引',
        tokenListedByYou: '你，按合约地址',
        tokenContractLabel: '合约',
        tokenManualWarn:
            '任何人都能用任何名字发一个代币。符号对得上什么也证明不了 — 在你往它转账或交易之前，拿项目自己的官网核对这个合约地址。',
        tokenGone:
            '这个代币已经不在列表里了。可能是你隐藏了它，也可能是余额归零后索引把它去掉了。',
        insufficientGasTitle: '{gas} 不够付手续费',
        insufficientGasBody:
            '转移代币是用 {gas} 付费的，不是 {symbol}。你还差 {amount} {gas}。',
        signSentenceToken:
            '从你的 {chain} 账户向 {network} 上的 {to} 转账 {amount} {symbol}，网络手续费最多 {fee} {gas}。',

        // Analytics
        analyticsBody:
            '这些数字在这个浏览器里算出来，用的是它已经读到的余额和这个页面已经拿到的价格。你持有什么，不会被发到任何地方去分析。',
        netWorth: '净值',
        analyticsPartial: '有 {count} 项持仓没有价格，或者根本读不到，未计入这个总额',
        shareNetworks: '网络原生币',
        shareTokens: '代币',
        allocation: '配置',
        analyticsEmpty:
            '还没有可以拆解的有价资产。收一笔资产，或者添加一个这个页面能报价的网络。',
        flowWeek: '转账 · 最近 7 天',
        flowNote:
            '统计自 {total} 个网络中那 {indexed} 个有浏览器不带 API 密钥就能读的索引的网络，并且只算来源标了日期的转账。',
        statNetworks: '已派生网络',
        statTokens: '已跟踪代币',
        statLargest: '最大持仓',
        statUnpriced: '未计入总额',
        statTransfers: '转账 · 7 天',
        statSources: '历史数据源',

        // Network detail
        balance: '余额',
        yourAddress: '你的地址',
        history: '历史',
        historyEmpty: '这个地址上还没有转账记录。',
        historyUnavailable: '历史读不出来：{reason}。区块浏览器上还是有的。',
        historyUnsupported:
            'Monero 的历史需要拿查看密钥去扫描 Monero 节点，这件事浏览器做不到。把同一组助记词恢复到 Monero 钱包里就能看到。',
        historyNoIndexer:
            '这个网络没有浏览器不带 API 密钥就能读的公开索引。完整历史在区块浏览器上。',
        historyNoEndpoint:
            '添加这个网络时没有填节点地址，所以没有地方可以读历史。在“安全”里补一个就能看到。',
        sentTo: '发送至',
        receivedFrom: '收自',
        statusConfirmed: '已确认',
        statusPending: '待确认',
        statusFailed: '失败',
        loading: '读取中…',

        // Receive
        receive: '接收',
        addressLabel: '地址',
        copyAddress: '复制地址',
        copiedClears: '已复制 · 30 秒后清空',
        expandAddress: '显示完整地址',
        qrLabel: '{chain} 地址的二维码',
        qrCaption: '{chain} 地址 · 二维码',
        warnEvm:
            '在每一个 EVM 网络上，这都是你的同一个地址。只往这里发 {chain} 的资产（链 {chainId}）— 从别的 EVM 网络发过来的东西不会丢，它只是在那个网络上，你得切换过去才能看到和花掉它。',
        warnSolana: '仅限 Solana 网络。从其他链发到这里的资产无法找回。',
        warnMonero:
            'Monero 地址和任何其他网络都不通用。这个钱包能收 XMR，但不能花 — 把同一组助记词恢复到 Monero 钱包里才能转出。',
        warnUtxo:
            '仅限 {chain}。比特币的各个分叉彼此共用地址格式，所以一个看起来没问题的地址仍可能属于另一条链 — 转账前先确认对方要你付的是哪条链。',
        warnCustom:
            '这个网络是你自己添加的。Cyberia 没有验证过它的节点，也没法告诉你它报的余额、手续费或确认状态是不是真的。',

        // Send
        send: '发送',
        recipient: '收款方',
        addressValid: '有效的 {kind} 地址',
        addressInvalid: '不是有效的 {kind} 地址',
        amount: '数量',
        max: '全部',
        balanceShort: '余额',
        insufficientTitle: '余额不足',
        insufficientBody: '连同网络手续费，你还差 {amount} {symbol}。',
        networkFee: '网络手续费',
        feeSlow: '慢',
        feeNormal: '普通',
        feeFast: '快',
        feeLoading: '正在读取网络…',
        feeUnavailable: '网络手续费读不出来，所以现在还不能签名。',
        youWillSign: '你将要签名的是',
        signSentence:
            '从你的 {chain} 账户向 {network} 上的 {to} 转账 {amount} {symbol}，网络手续费最多 {fee} {symbol}。',
        reviewTransaction: '核对交易',
        sendUnsupported:
            '这个钱包没法在浏览器里花掉 {chain}。把同一组助记词恢复到 {chain} 钱包里才能转出。',

        // Review and signing
        confirmTransaction: '确认交易',
        reviewBody: '每一行都核对一遍。签出去的交易撤不回来。',
        kNetwork: '网络',
        kTo: '收款方',
        kAmount: '数量',
        kFee: '网络手续费',
        kTotal: '合计扣款',
        plainLanguage: '大白话',
        nothingElse: '这个签名不会授权任何别的事情。',
        holdToSign: '按住签名',

        // Transaction status
        txSigningLabel: '本地签名',
        txSigningTitle: '正在这台设备上签名',
        txSigningBody:
            '你的私钥从不离开保险库。交易正在签名并广播。',
        txPendingLabel: '待确认',
        txPendingTitle: '已广播到网络',
        txPendingBody: '等待确认。你可以离开这个页面 — 转账会继续。',
        txConfirmedLabel: '已接受',
        txConfirmedTitle: '交易已广播',
        txConfirmedBody: '网络接受了这笔交易。随着确认，余额会更新。',
        txFailedLabel: '失败',
        txFailedTitle: '交易没有发出',
        txFailedBody: '什么都没有转出。',
        kVault: '保险库',
        vaultUnlockedLocal: '已解锁 · 本地',
        kTxHash: '交易',
        kReason: '原因',
        adjustRetry: '修改后重试',
        backToPortfolio: '回到资产',
        viewInExplorer: '在区块浏览器中查看',

        // Security
        security: '安全',
        vaultSection: '保险库',
        backupSeed: '备份助记词',
        backupSeedHint: '需要你的密码 · 永远不会显示在资产页上',
        showPhrase: '显示助记词',
        autoLockHint: '闲置一段时间后锁定',
        clipboardRow: '复制后清空剪贴板',
        clipboardHint: '30 秒，前提是这个标签页保持在前台',
        networksSection: '网络',
        builtinNetworks: '内置网络',
        verified: '已验证',
        removeNetwork: '移除',
        removeNetworkHint:
            '移除一个网络只是忘掉它的节点地址。派生出的账户仍然可以用你的助记词恢复。',
        addNetworkRow: '添加 EVM 链或比特币分叉',
        addNetworkRowHint: '由同一组助记词派生 · 无需重新输入',
        lockNow: '立即锁定钱包',
        lock: '锁定',
        dangerZone: '危险区',
        deleteVault: '删除本地保险库',
        deleteVaultBody:
            '把加密的密钥从这台设备上删掉。没有助记词，资金就找不回来了。',
        deleteVaultAction: '删除保险库',
        irreversible: '不可撤销',
        deleteTitle: '删除这个保险库？',
        deleteBody:
            '加密的密钥将从这个浏览器里被抹掉。只有你的助记词能恢复访问。',
        typeToConfirm: '输入 {word} 以确认',
        deleteWord: 'DELETE',
        deleteConfirm: '删除',

        // Locked
        vaultLocked: '保险库已锁定',
        enterPassword: '输入你的密码',
        unlock: '解锁',
        wrongPassword: '钱包密码不对。',
        forgotPassword: '忘记密码 → 用助记词恢复',

        // Monero payouts
        useForPayouts: '用作跨链桥收款地址',
        useForPayoutsDone: '已保存为你的收款地址',
        useForPayoutsHint:
            '把这个地址存到你的个人资料里，XMR 跨链桥的出款就会打到这个钱包。',
        signInForPayouts: '登录后才能用作收款地址',
        noBalanceHere: '浏览器里读不到',
        receiveOnly: '仅可接收',
        path: '派生路径',
        openSite: 'Cyberia',

        // Lain — the $LAIN holders' room
        lainTitle: 'Lain',
        lainIntro:
            'Cyberia 的常驻智能，向持有 $LAIN 实时流通量 {required}% 及以上的钱包开放。你的占比就在这个浏览器里从合约读出来 — 在你决定进入这个房间之前，什么都不会被发送出去。',
        lainHolding: '你持有',
        lainShare: '占流通量',
        lainRequired: '门槛',
        lainReading: '正在读取合约…',
        lainOff: 'Lain 在这台服务器上还没接通。',
        lainReadFailed:
            '读不到 Cyberia 上的 $LAIN 合约。这说明不了你的余额如何 — 只说明网络没有回应。',
        lainShort:
            '这个房间向持有 $LAIN 实时流通量 {required}% 的钱包开放。这个账户持有 {share} — {amount} {symbol}。',
        lainShortHint:
            '每次打开这个页面都会重新计算占比，所以它既跟着你持有的数量走，也跟着增发或销毁走。',
        lainQualifies: '这个账户符合条件',
        lainSignBody:
            '用这个钱包的 Cyberia 密钥签一个挑战即可进入。它不转移资金、不批准交易、不授予任何额度 — 它只证明这个浏览器握有这个地址背后的密钥。',
        lainSign: '按住签名',
        lainSigning: '签名中…',
        lainNoTools:
            'Lain 在这个房间里没有任何工具：她读不到你的余额，签不了任何东西，也动不了资金。永远不要把助记词发给她 — 或者任何人。',
        lainEmpty: '说点什么。她在听。',
        lainName: 'Lain',
        lainYou: '你',
        lainThinking: 'Lain 在想…',
        lainPlaceholder: '给 Lain 写点什么…（回车发送，Shift+回车换行）',
        lainSend: '发送',
        lainStored: '这段对话留在这台设备上。',
        lainForget: '忘掉这段对话',
        lainUnreachable: 'Lain 现在连不上。过一会儿再试。',

        // Encrypted chat between wallets
        chatTitle: '消息',
        chatIntro:
            '钱包之间的加密消息，以 EVM 地址为收件人。一切都在这个浏览器里用一把从你账户派生出的密钥封装和拆开 — Cyberia 转发的是它读不懂的内容。',
        chatNoAccount:
            '这个账户只能观察。它没有密钥，所以既读不了也写不了消息 — 和它不能花钱是同一个原因。',
        chatOpenTitle: '开通加密聊天',
        chatOpenBody:
            '两个签名，只此一次：一个发布一把别人用来加密给你的消息密钥，另一个证明这个地址，好让中继把你的信件交出来。两个都不转移资金、不批准交易、不授予任何额度。消息密钥由这个账户派生，不是给你的交易签名的那把密钥。',
        chatOpen: '按住开通',
        chatOpening: '签名中…',
        chatYourAddress: '你的地址',
        chatFingerprintLabel: '密钥指纹',
        chatMetadataNote:
            '受保护的是内容。中继仍然看得到哪些地址在说话、什么时候说，并且信封最多保留 30 天才删除。没有前向保密：任何人拿到这个账户的密钥，也能读到它过去的消息。',
        chatE2ee: '端到端加密',
        chatSyncing: '检查中…',
        chatThreads: '{count} 个会话',
        chatNew: '新会话',
        chatNewBody:
            '两边的钱包都得开通过聊天：地址是一个哈希，在它的主人发布密钥之前，没有东西可以加密给他。',
        chatAddressLabel: '写给哪个地址',
        chatStart: '打开会话',
        chatLookingUp: '正在查找密钥…',
        chatInvalidAddress: '这不是一个 EVM 地址。',
        chatNoKey: '这个地址还没有开通加密聊天。',
        chatEmpty:
            '还没有会话。任何开通过聊天的人都可以用你的 EVM 地址给你写信。',
        chatThreadEmpty: '这里还什么都没有。写下第一条消息。',
        chatYou: '你',
        chatPlaceholder: '写一条消息…（回车发送，Shift+回车换行）',
        chatSend: '发送',
        chatSending: '封装中…',
        chatUnreadable: '这条消息打不开 — 它和信封上写的对不上。',
        chatKeyChanged:
            '这个地址正在发布的密钥，和这台设备之前见到的那把不一样。要么是钱包在别处恢复了，要么是有人试图坐在中间 — 继续之前，先和对方核对指纹。',
        chatStored:
            '消息以密文形式存在这台设备上，只有钱包解锁时才会被打开。',
        chatForget: '忘掉所有会话',

        // Accounts
        accounts: '账户',
        accountsBody:
            '从你的助记词派生出的账户都在同一个保险库里。导入的私钥、导入的助记词和观察地址会被标出来 — 你的备份不覆盖它们。',
        accountsFootnote:
            '当前账户就是每个页面所讲的那个：资产、代币、历史、手续费，以及你签的一切。',
        orphanTitle: '这个账户无处安放',
        orphanBody:
            '它是在一个已经不在这个钱包里的网络上导入的。把那个网络加回来才能再用它，或者切到别的账户。',
        accountActive: '当前',
        accountSwitch: '切换',
        accountKindSeed: '来自你的助记词',
        accountKindPhrase: '导入的助记词',
        accountKindKey: '导入的私钥',
        accountKindWatch: '仅观察',
        accountUse: '使用',
        accountRename: '重命名',
        accountForget: '忘掉',
        accountForgetSure: '确定忘掉？',
        accountForgetSecret: '这台设备上是唯一的一份。',
        accountForgetConfirm: '忘掉',
        accountPrimaryName: '主账户',
        accountSeedName: '账户 {index}',
        accountPhraseName: '导入的助记词',
        accountKeyName: '导入的 {chain} 私钥',
        accountWatchName: '观察中的 {chain} 地址',
        accountPathKey: '没有派生路径',
        accountPathWatch: '公开地址',
        accountNotInBackup: '导入的私钥 · 不在你的助记词备份里',
        accountOwnPhrase: '它有自己的助记词 · 请单独备份',
        accountWatchOnly: '仅观察 · 不能签名',
        accountDeriveNext: '派生下一个',
        accountSameSeed: '同一组助记词',
        accountImport: '导入',
        accountImportHint: '助记词 · 私钥 · 观察',

        // Import an account
        importAccountTitle: '导入一个账户',
        importAccountBody:
            '这里的一切都在这个浏览器里校验，并存进和你助记词同一个加密保险库。什么都不会被发送出去。',
        importKindPhrase: '助记词',
        importKindPhraseHint: '12 或 24 个词',
        importKindKey: '私钥',
        importKindKeyHint: '单个网络',
        importKindWatch: '观察地址',
        importKindWatchHint: '不能签名',
        importNetwork: '网络',
        importKeyChainsNote:
            '这里没有列出 Monero：这个钱包花不了它，所以导入一把花费密钥，换来的只是你的助记词本来就能派生出的地址。',
        importName: '账户名称 · 可选',
        importNamePlaceholder: '空投猎人',
        importSecret: '密钥',
        importAddress: '地址',
        importPlaceholderPhrase: '第 01 个词   第 02 个词   第 03 个词 …',
        importPlaceholderKey: '私钥，按它原本的钱包导出的样子',
        importPlaceholderAddress: '要观察的公开地址',
        importAwaitPhrase: '请输入 12 或 24 个词',
        importAwaitKey: '粘贴一把私钥',
        importAwaitAddress: '输入一个公开地址',
        importPhraseProgress: '已输入 {count} 个词',
        importLooksValid: '看起来有效',
        importUnrecognised: '认不出这个格式',
        importWarnPhrase:
            '第二组助记词是它自己的根。你已有的备份恢复不了它 — 把这一组单独抄下来，否则它下面的账户会随这台设备一起消失。',
        importWarnKey:
            '导入的私钥不在你助记词的覆盖范围内。这台设备丢了，只有单独备份的这把私钥能恢复这个账户。',
        importWarnWatch:
            '观察地址可以跟踪、可以收款，但在这里永远签不了、也发不出任何东西。',
        importAction: '导入账户',
        importWatchAction: '添加观察账户',

        // Launchpad
        launchpad: '发射台',
        launchpadBody:
            'Cyberia 上的公平发射。为发射付的币被销毁成了锁定的流动性，所以没有预留、也没有解锁期 — 一次发射从它存在的那一刻起就是一个池子。',
        launchpadLoading: '正在从链上读取发射…',
        launchpadEmpty: '这里还没有发射过任何东西。',
        launchpadUnreadable:
            'Cyberia 节点没有回应。不管怎样，这些发射都在链上。',
        launchLocked: '已锁定',
        launchPrice: '价格',
        launchValue: '美元价格',
        launchLiquidity: '锁定的流动性',
        launchCap: '市值',
        launchSupply: '供应量',
        launchContract: '合约',
        launchLockedBody:
            '这个代币背后的流动性在发射时就被销毁了。没有人能把它取出来 — 包括发射它的人。',
        launchRisk:
            '锁定流动性不等于背书。任何人都能在这里发任何东西，名字和符号证明不了它是谁做的。',
        launchBuy: '在钱包里买入',
        launchTrade: '在 DEX 交易',
        launchExplorer: '区块浏览器',

        // Feed
        feed: '动态',
        feedBody: '来自 Cyberia 各处的帖子，以及 DAO 记录下来的事，最新在前。',
        feedTabAll: '全部',
        feedTabPosts: '帖子',
        feedTabDao: 'DAO',
        feedTagPost: '帖子',
        feedTagDao: 'DAO',
        feedProposalCreated: '发起了一个提案',
        feedVoteCast: '投了票',
        feedCommentPosted: '发表了评论',
        feedSomeone: '某人',
        feedLoading: '正在加载动态…',
        feedEmpty: '动态很安静。',
        feedUnreadable: '连不上 Cyberia，拿不到动态。',
        feedOpen: '打开',
        feedOpenSite: '在网站上',
        feedReadOnly:
            '只能阅读。这个钱包背后没有账号 — 这里没有任何东西知道你是谁 — 所以发帖和回复要在网站上做。',

        // DAO
        dao: 'DAO',
        daoBody: '每一个提案，以及投票真实的样子。进度条画的是投票权重，不是投票人数。',
        daoProposals: '提案',
        daoOpenCount: '{count} 个进行中',
        daoLoading: '正在加载提案…',
        daoEmpty: '还没有提案。',
        daoUnreadable: '连不上 Cyberia，拿不到提案。',
        daoStatusOpen: '进行中',
        daoStatusClosed: '已结束',
        daoNoDeadline: '没有截止时间',
        daoNoVotes: '还没有人投票',
        daoFor: '{percent}% 赞成',
        daoAgainst: '{percent}% 反对',
        daoCast: '{votes} 票 · {comments} 条评论',
        daoCastShort: '{votes} 票',
        daoNoSession:
            '投票按代币快照加权，并记在一个账号名下，而这个钱包没有账号。到网站上打开提案才能投票。',
        daoOpenToVote: '打开去投票',

        // Profile
        profileTitle: '个人资料',
        profileYours: '你的资料',
        profileAddress: '地址',
        profileLoading: '正在加载资料…',
        profileUnreadable: '连不上 Cyberia，拿不到这份资料。',
        profileNoAddress: '这个账户没有可以查询的 EVM 地址。',
        profileUnclaimed: 'Cyberia 上还没有账号认领这个地址。',
        profileUnclaimedYours:
            'Cyberia 上还没有人认领这个地址。你的钱包不受影响 — 资料只是多了一个公开名字、徽章和网站上的社交那一面。',
        profileClaim: '到网站上认领',
        profileOnchainName: '在 49406 链上拥有的名字',
        profilePosts: '帖子',
        profileProposals: '提案',
        profileVotes: '投票',
        profileAchievements: '成就 · {total} 个中的 {earned} 个',
        profileOpen: '打开完整资料',

        // Swap and wrap
        swapTitle: '兑换',
        swapTab: '兑换',
        wrapTab: '封装',
        swapPay: '你支付',
        swapReceive: '你收到',
        swapPick: '选择',
        swapPickAsset: '选一个资产',
        swapAdd: '添加',
        swapByAddress: '或者粘贴一个合约地址',
        swapByAddressNote:
            '合约会先被读一遍再拿出来用：符号和小数位来自链本身，不是来自任何列表。',
        swapFlip: '对调两边',
        swapSlippage: '最大滑点',
        swapRate: '汇率',
        swapMinOut: '最少收到',
        swapImpact: '价格影响',
        swapRoute: '路径',
        swapReview: '核对兑换',
        swapQuoting: '正在读取资金池…',
        swapApproval:
            '两笔交易：先给正好这么多 {symbol} 的授权额度，然后才是兑换。两笔都要 gas，两笔都算在上面的手续费里。这个钱包从不授权超过这笔交易所需的额度，所以事后不会留下任何东西。',
        swapApprovalReset:
            '三笔交易：这个代币不允许把一个非零的授权额度改成别的值，所以先把剩下的清零，再设成正好这么多 {symbol}，最后才跑兑换。这些都算在上面的手续费里。',
        swapApprovalTx: '授权交易',
        swapImpactWarn:
            '这笔交易会把池子价格推动 {impact}%。对这个数额来说池子太浅了 — 小一点的数额能换到更好的价。',
        swapNoDex:
            '{chain} 上没有部署交易所，所以这里没有可以交易的对手方。兑换在跑着交易所的网络上才有。',
        swapOnNetwork: '在 {chain} 上兑换',
        swapWatchOnly:
            '这是一个观察地址：可以报价，但永远签不了。把私钥导入进来，它才能交易。',
        swapSentence:
            '在 {network} 上用 {amount} {from} 换至少 {min} {to}，收款到这同一个钱包。gas 最多 {fee} {gas}。如果池子给不到这个最小值，整笔兑换会回滚，什么都不会花出去。',
        wrapSentence:
            '在 {network} 上把 {amount} {from} 封装成 {amount} {to}。一比一 — 币存在封装合约里，你随时可以取回。gas 最多 {fee} {gas}。',
        unwrapSentence:
            '在 {network} 上把 {amount} {from} 解封回 {amount} {to}。一比一。gas 最多 {fee} {gas}。',
        wrapBody:
            '{coin} 是这条链运行所用的币，不是代币 — 资金池、农场和大多数合约收的是 {wrapped}。封装两个方向都是一比一，只花 gas。',
        wrapUnavailable:
            '这个网络上的封装合约读不出来，所以这里没有任何东西能被如实地叫出名字。等网络有回应了再试。',
        swapOutcome_signing: '签名中',
        swapOutcome_approving: '授权中',
        swapOutcome_pending: '已广播',
        swapOutcome_confirmed: '已成交',
        swapOutcome_failed: '未执行',
        swapOutcomeBody_signing: '交易正在这台设备上构建并签名。',
        swapOutcomeBody_approving:
            '先设置授权额度。它一上链，兑换就会被签出去。',
        swapOutcomeBody_pending: '交易已经进入网络。等待打包。',
        swapOutcomeBody_confirmed: '你的 {from} 现在是 {to} 了。',
        swapOutcomeBody_failed: '账户里什么都没有出去。',

        // NFT, IPFS, torrents
        tabNft: 'NFT',
        tileIpfsHint: '固定一个文件 · 一个页面',
        tileTorrentHint: 'DHT · 桌面版',

        nftTitle: 'NFT',
        nftBody: '一个人人都能铸造进去的合集。一个代币究竟*是*什么，完全取决于它指向的那个地址 — 通常是 IPFS 里的一个文件，有时只是一个链接。',
        nftMint: '铸造 NFT',
        nftLoading: '正在读取这个账户持有的东西…',
        nftEmpty: '这个账户在这个网络上还没有 NFT。',
        nftUnreadable: '区块浏览器没有回应，所以列不出这个账户拥有什么。',
        nftOwned: '持有',
        nftMintedHere: '在这里铸造',
        nftGatewayNote:
            '图片来自一个公开的 IPFS 网关，所以那个网关看得到有哪些代币正在被查看。',
        nftStandard: '标准',
        nftAmount: '数量',
        nftContract: '合约',
        nftPointsAt: '指向',
        nftExplorer: '区块浏览器',
        nftExternal: '链接',

        mintTitle: '铸造 NFT',
        mintBody:
            '两步。元数据先固定到 IPFS，然后把它的地址写上链 — 只有第二步花 gas，也只有第二步是永久的。',
        mintNoAccount: '这个账户在合集所在的网络上没有地址。',
        mintWatchOnly:
            '这是一个观察地址。它可以持有代币；但没有密钥去签一次铸造。',
        mintCompose: '现做',
        mintDirect: '已有地址',
        mintDirectBody:
            '铸造一个指向已经发布好的东西的代币。这串字符会原样上链 — 一个 ipfs:// 地址、一个链接，或者一行文字。',
        mintName: '名称',
        mintNamePlaceholder: '这是什么？',
        mintDescription: '描述',
        mintImage: '文件',
        mintImageOptional: '可选 — 一个代币也可以只是文字或链接。',
        mintLink: '链接',
        mintContinue: '继续',
        mintPreparing: '正在固定并估价…',
        mintPinNote:
            '文件和元数据在任何签名之前就已经固定好了。你的密钥和账户的信息不会跟着它们走。',
        mintConfirmTitle: '确认铸造',
        mintCollection: '网络',
        mintUri: '代币指向',
        mintFee: '手续费，最多',
        mintPermanent:
            '这之后既不能修改也不能删除。只要这条链还在，这个代币就留在合集里，指着这个地址。',
        mintHold: '按住铸造',
        mintSentTitle: '铸造已发出',
        mintSentBody:
            '交易已经在路上。等链上有了它、区块浏览器读到元数据，它就会进入你的合集。',
        mintExplorer: '打开这笔交易',
        mintAnother: '再铸一个',

        ipfsTitle: 'IPFS',
        ipfsBody:
            '发布一个文件或一个页面，拿到一个 CID — 一个由内容本身构成的地址。任何拿到这个 CID 的人，都能从任何有这份字节的节点上取到它。',
        ipfsOff: '这台服务器现在没有在做固定，所以这里发布不了任何东西。',
        ipfsFile: '文件',
        ipfsPage: '页面',
        ipfsFileBody: '任何文件：图片、音频、压缩包、文档。',
        ipfsPageBody:
            'HTML，作为一整个站点固定 — CID 打开就是一个页面，而不是一次下载。',
        ipfsUpTo: '最大 {size}。',
        ipfsTooLarge: '超过 {size} 了，这台服务器只固定到这么大。',
        ipfsPin: '发布',
        ipfsPinning: '发布中…',
        ipfsCid: 'CID',
        ipfsSize: '大小',
        ipfsCopyUri: '复制 ipfs://',
        ipfsOpen: '打开',
        ipfsGatewayNote:
            'CID 就是地址。这个链接是通过一个公开网关打开它的 — 而能提供同样字节的主机有很多，它只是其中之一。',
        ipfsPersistenceNote:
            '我们的节点现在固定着它。这里没有任何东西承诺永远，所以如果它重要，把这个 CID 在你自己那边也固定一份。',
        ipfsMintThis: '把它铸成 NFT',
        ipfsAgain: '再发布点别的',
        ipfsRelayNote:
            '这些字节要经过本站，因为 IPFS 节点没法交给浏览器 — 它能在节点上执行任何命令。这个钱包里没有别的东西是这样运作的。',

        torrentTitle: '种子',
        torrentBrowserBody:
            '真正的 BitTorrent 客户端跑在 Cyberia 桌面版里。这个标签页做不到。',
        torrentWhyNot:
            'DHT 走 UDP，连接节点走 TCP，而网页两样都没有。网页能够到的那个群 — 走 WebRTC 的浏览器节点 — 对一个普通磁力链来说几乎没有成员，所以这里的下载器会一个人都找不到，看起来就像你的链接填错了。',
        torrentGetDesktop: '获取桌面版',
        torrentMobileNote:
            '手机应用和 Telegram 里也是一样：两个都是网页视图。',
        torrentDesktopBody:
            '主线 DHT、节点交换和 tracker — 和其他客户端看到的是同一个群。文件落在应用的下载文件夹里。',
        torrentSource: '磁力链、info hash 或 .torrent 链接',
        torrentSourceHint:
            'magnet:、40 个字符的 info hash，或者指向 .torrent 的 https 链接',
        torrentBadSource:
            '这既不是磁力链，也不是 info hash，更不是 https 的 .torrent 链接。',
        torrentAdd: '添加种子',
        torrentAdding: '添加中…',
        torrentPrivacy:
            '其他节点看得到你的 IP 地址，而且应用的代理不覆盖这部分流量 — 那个设置管的是网页请求，这里走的是裸套接字。',
        torrentFolder: '文件夹',
        torrentOpenFolder: '打开文件夹',
        torrentEmpty: '没有正在下载的东西。',
        torrentPause: '暂停',
        torrentResume: '继续',
        torrentRemove: '移除',
        torrentRemoveKeep: '移除，保留文件',
        torrentRemoveDelete: '移除并删除',
        torrentPeers: '{count} 个节点',
        torrentPinFile: '固定到 IPFS',
        torrentMeta: '正在获取元数据',
        torrentDownloading: '下载中',
        torrentSeeding: '做种中',
        torrentPaused: '已暂停',
        torrentError: '已停止',
        torrentLawNote:
            '你下载和分享什么，由你自己负责。客户端分不清 Linux 镜像和别的任何东西。',
    },
};
