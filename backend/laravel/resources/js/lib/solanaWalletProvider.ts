import type { Transaction } from '@solana/web3.js';

type StandardWalletAccount = {
    address: string;
    publicKey: Uint8Array;
    chains: readonly string[];
    features: readonly string[];
};

type StandardWallet = {
    version: '1.0.0';
    name: string;
    icon?: string;
    chains: readonly string[];
    features: Record<string, unknown>;
    accounts: readonly StandardWalletAccount[];
};

type StandardConnectFeature = {
    connect(args?: {
        silent?: boolean;
    }): Promise<{ accounts?: readonly StandardWalletAccount[] } | void>;
};

type StandardDisconnectFeature = {
    disconnect(): Promise<void>;
};

type StandardEventsFeature = {
    on(
        event: 'change',
        listener: (event: { accounts?: readonly StandardWalletAccount[] }) => void,
    ): () => void;
};

type StandardSignMessageFeature = {
    signMessage(input: {
        message: Uint8Array;
        account: StandardWalletAccount;
    }): Promise<unknown>;
};

type StandardSignAndSendTransactionFeature = {
    signAndSendTransaction(input: {
        transaction: Uint8Array;
        account: StandardWalletAccount;
        chain: string;
        options?: object;
    }): Promise<unknown>;
};

export type SolanaInjectedProvider = {
    isPhantom?: boolean;
    isSolflare?: boolean;
    isBackpack?: boolean;
    publicKey: { toBase58(): string; toBytes?(): Uint8Array } | null;
    isConnected?: boolean;
    connect(opts?: {
        onlyIfTrusted?: boolean;
    }): Promise<{ publicKey: { toBase58(): string; toBytes?(): Uint8Array } }>;
    disconnect?(): Promise<void>;
    signMessage?(
        message: Uint8Array,
        encoding?: string,
    ): Promise<{ signature: Uint8Array; publicKey: { toBase58(): string } }>;
    signAndSendTransaction?(
        tx: Transaction,
        options?: object,
    ): Promise<{ signature: string }>;
    on?(event: string, handler: (...args: unknown[]) => void): void;
    off?(event: string, handler: (...args: unknown[]) => void): void;
};

export type SolanaWalletProvider = {
    id: string;
    name: string;
    icon?: string;
    source: 'wallet-standard' | 'legacy';
    wallet?: StandardWallet;
    legacy?: SolanaInjectedProvider;
};

export type SolanaTransactionProvider = {
    publicKey: { toBase58(): string };
    signAndSendTransaction(
        tx: Transaction,
        options?: object,
    ): Promise<{ signature: string }>;
};

const REGISTER_EVENT = 'wallet-standard:register-wallet';
const APP_READY_EVENT = 'wallet-standard:app-ready';
const SOLANA_MAINNET = 'solana:mainnet';
const SOLANA_DEVNET = 'solana:devnet';

const standardWallets = new Set<StandardWallet>();
let initialized = false;
let selectedWallet: SolanaWalletProvider | null = null;
let selectedAccount: StandardWalletAccount | null = null;

const encodeBase58 = (bytes: Uint8Array): string => {
    const alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    let value = 0n;

    for (const byte of bytes) {
        value = value * 256n + BigInt(byte);
    }

    let encoded = '';

    while (value > 0n) {
        const remainder = Number(value % 58n);
        encoded = alphabet[remainder] + encoded;
        value /= 58n;
    }

    for (const byte of bytes) {
        if (byte !== 0) {
            break;
        }

        encoded = alphabet[0] + encoded;
    }

    return encoded || alphabet[0];
};

const encodeBase64 = (bytes: Uint8Array): string =>
    btoa(String.fromCharCode(...bytes));

const isSolanaWallet = (wallet: StandardWallet): boolean =>
    wallet.chains.some((chain) => chain.startsWith('solana:')) ||
    Object.keys(wallet.features).some((feature) => feature.startsWith('solana:'));

const registerStandardWallets = (...wallets: StandardWallet[]): (() => void) => {
    wallets.filter(isSolanaWallet).forEach((wallet) => standardWallets.add(wallet));

    return () => {
        wallets.forEach((wallet) => standardWallets.delete(wallet));
    };
};

const initWalletStandard = (): void => {
    if (initialized || typeof window === 'undefined') {
        return;
    }

    initialized = true;

    window.addEventListener(REGISTER_EVENT, (event) => {
        const callback = (event as CustomEvent).detail as
            | ((api: { register: typeof registerStandardWallets }) => void)
            | undefined;

        if (typeof callback === 'function') {
            callback({ register: registerStandardWallets });
        }
    });

    window.dispatchEvent(
        new CustomEvent(APP_READY_EVENT, {
            detail: { register: registerStandardWallets },
        }),
    );
};

const getFeature = <T>(wallet: StandardWallet, name: string): T | null => {
    const feature = wallet.features[name];

    return feature && typeof feature === 'object' ? (feature as T) : null;
};

const standardAccount = (
    wallet: StandardWallet,
): StandardWalletAccount | null => {
    return wallet.accounts.find((account) =>
        account.chains.some((chain) => chain.startsWith('solana:')),
    ) ?? null;
};

const legacyProviders = (): SolanaWalletProvider[] => {
    if (typeof window === 'undefined') {
        return [];
    }

    const w = window as unknown as {
        solana?: SolanaInjectedProvider;
        phantom?: { solana?: SolanaInjectedProvider };
        solflare?: SolanaInjectedProvider;
        backpack?: { solana?: SolanaInjectedProvider } | SolanaInjectedProvider;
    };

    const list: SolanaWalletProvider[] = [];
    const push = (
        id: string,
        name: string,
        provider: SolanaInjectedProvider | null | undefined,
    ) => {
        if (!provider || list.some((item) => item.legacy === provider)) {
            return;
        }

        list.push({ id, name, legacy: provider, source: 'legacy' });
    };

    push('legacy:phantom', 'Phantom', w.phantom?.solana);
    push(
        'legacy:phantom-window',
        'Phantom',
        w.solana?.isPhantom ? w.solana : null,
    );
    push('legacy:solflare', 'Solflare', w.solflare);
    push(
        'legacy:backpack',
        'Backpack',
        'solana' in (w.backpack ?? {})
            ? (w.backpack as { solana?: SolanaInjectedProvider }).solana
            : (w.backpack as SolanaInjectedProvider | undefined),
    );

    return list;
};

export const getSolanaWalletProviders = (): SolanaWalletProvider[] => {
    initWalletStandard();

    const providers: SolanaWalletProvider[] = [...standardWallets].map(
        (wallet) => ({
            id: `standard:${wallet.name}`,
            name: wallet.name,
            icon: wallet.icon,
            wallet,
            source: 'wallet-standard',
        }),
    );

    for (const legacy of legacyProviders()) {
        if (
            providers.some(
                (provider) =>
                    provider.name.toLowerCase() === legacy.name.toLowerCase(),
            )
        ) {
            continue;
        }

        providers.push(legacy);
    }

    return providers.sort((a, b) => a.name.localeCompare(b.name));
};

export const getSolanaWalletProvider = (
    id: string,
): SolanaWalletProvider | null =>
    getSolanaWalletProviders().find((wallet) => wallet.id === id) ?? null;

export const getSelectedSolanaWalletProvider = ():
    | SolanaWalletProvider
    | null => selectedWallet;

export const selectSolanaWalletProvider = (
    wallet: SolanaWalletProvider | null,
    account: StandardWalletAccount | null = null,
): void => {
    selectedWallet = wallet;
    selectedAccount = account;
};

export const getSelectedSolanaAddress = (): string | null => {
    if (selectedWallet?.wallet) {
        return selectedAccount?.address ?? standardAccount(selectedWallet.wallet)?.address ?? null;
    }

    return selectedWallet?.legacy?.publicKey?.toBase58() ?? null;
};

export const connectSolanaWalletProvider = async (
    wallet: SolanaWalletProvider,
    silent = false,
): Promise<string | null> => {
    if (wallet.wallet) {
        const connect = getFeature<StandardConnectFeature>(
            wallet.wallet,
            'standard:connect',
        );

        if (!connect) {
            throw new Error(`${wallet.name} does not support standard connect`);
        }

        const response = await connect.connect({ silent });
        const account =
            response?.accounts?.find((candidate) =>
                candidate.chains.some((chain) => chain.startsWith('solana:')),
            ) ?? standardAccount(wallet.wallet);

        if (!account) {
            throw new Error(`${wallet.name} did not expose a Solana account`);
        }

        selectSolanaWalletProvider(wallet, account);

        return account.address;
    }

    if (wallet.legacy) {
        const response = await wallet.legacy.connect({
            onlyIfTrusted: silent,
        });
        selectSolanaWalletProvider(wallet);

        return response.publicKey.toBase58();
    }

    return null;
};

export const disconnectSolanaWalletProvider = async (): Promise<void> => {
    if (selectedWallet?.wallet) {
        const disconnect = getFeature<StandardDisconnectFeature>(
            selectedWallet.wallet,
            'standard:disconnect',
        );

        await disconnect?.disconnect();
    } else {
        await selectedWallet?.legacy?.disconnect?.();
    }

    selectSolanaWalletProvider(null);
};

const firstResult = (value: unknown): Record<string, unknown> | null => {
    if (Array.isArray(value)) {
        return (value[0] as Record<string, unknown> | undefined) ?? null;
    }

    return value && typeof value === 'object'
        ? (value as Record<string, unknown>)
        : null;
};

export const signSolanaMessage = async (
    message: string,
): Promise<string | null> => {
    if (!selectedWallet) {
        throw new Error('Solana wallet not connected');
    }

    const encodedMessage = new TextEncoder().encode(message);

    if (selectedWallet.wallet) {
        const account =
            selectedAccount ?? standardAccount(selectedWallet.wallet);
        const signMessage = getFeature<StandardSignMessageFeature>(
            selectedWallet.wallet,
            'solana:signMessage',
        );

        if (!account || !signMessage) {
            throw new Error(
                `${selectedWallet.name} does not support Solana message signing`,
            );
        }

        const output = firstResult(
            await signMessage.signMessage({
                message: encodedMessage,
                account,
            }),
        );
        const signature = output?.signature;

        if (signature instanceof Uint8Array) {
            return encodeBase64(signature);
        }

        if (typeof signature === 'string') {
            return signature;
        }

        return null;
    }

    const { signature } = await selectedWallet.legacy!.signMessage!(
        encodedMessage,
        'utf8',
    );

    return encodeBase64(signature);
};

const signatureFromOutput = (value: unknown): string | null => {
    const output = firstResult(value);
    const signature = output?.signature ?? output?.signatures;

    if (typeof signature === 'string') {
        return signature;
    }

    if (signature instanceof Uint8Array) {
        return encodeBase58(signature);
    }

    if (Array.isArray(signature)) {
        const first = signature[0];

        if (typeof first === 'string') {
            return first;
        }

        if (first instanceof Uint8Array) {
            return encodeBase58(first);
        }
    }

    return null;
};

export const getSelectedSolanaTransactionProvider = (
    cluster: 'mainnet' | 'devnet' = 'mainnet',
): SolanaTransactionProvider | null => {
    if (!selectedWallet) {
        return null;
    }

    if (selectedWallet.legacy?.publicKey) {
        const provider = selectedWallet.legacy;

        if (!provider.signAndSendTransaction) {
            return null;
        }

        return {
            publicKey: { toBase58: () => provider.publicKey!.toBase58() },
            signAndSendTransaction: (tx, options) =>
                provider.signAndSendTransaction!(tx, options),
        };
    }

    if (!selectedWallet.wallet) {
        return null;
    }

    const wallet = selectedWallet.wallet;
    const account = selectedAccount ?? standardAccount(wallet);
    const signAndSend = getFeature<StandardSignAndSendTransactionFeature>(
        wallet,
        'solana:signAndSendTransaction',
    );

    if (!account || !signAndSend) {
        return null;
    }

    return {
        publicKey: { toBase58: () => account.address },
        signAndSendTransaction: async (tx, options) => {
            const signature = signatureFromOutput(
                await signAndSend.signAndSendTransaction({
                    transaction: tx.serialize({
                        requireAllSignatures: false,
                        verifySignatures: false,
                    }),
                    account,
                    chain: cluster === 'devnet' ? SOLANA_DEVNET : SOLANA_MAINNET,
                    options,
                }),
            );

            if (!signature) {
                throw new Error(
                    `${wallet.name} did not return a transaction signature`,
                );
            }

            return { signature };
        },
    };
};

export const onSelectedSolanaAccountChange = (
    listener: (address: string | null) => void,
): (() => void) => {
    if (selectedWallet?.wallet) {
        const events = getFeature<StandardEventsFeature>(
            selectedWallet.wallet,
            'standard:events',
        );

        return (
            events?.on('change', (event) => {
                selectedAccount =
                    event.accounts?.find((account) =>
                        account.chains.some((chain) =>
                            chain.startsWith('solana:'),
                        ),
                    ) ?? selectedAccount;
                listener(selectedAccount?.address ?? null);
            }) ?? (() => {})
        );
    }

    const legacy = selectedWallet?.legacy;

    if (!legacy?.on || !legacy.off) {
        return () => {};
    }

    const handler = (publicKey: unknown) => {
        listener(
            publicKey && typeof publicKey === 'object' && 'toBase58' in publicKey
                ? (publicKey as { toBase58(): string }).toBase58()
                : null,
        );
    };

    legacy.on('accountChanged', handler);

    return () => legacy.off?.('accountChanged', handler);
};
