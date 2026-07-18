import type { EthereumProvider } from '@/types/global';

/**
 * Multi-wallet disambiguation.
 *
 * When several EIP-1193 wallets are installed (MetaMask + Phantom + Rabby …),
 * they all inject into `window.ethereum`, and the last one to load wins. That
 * is why clicking "Connect MetaMask" can pop up Phantom instead.
 *
 * EIP-6963 fixes this: each wallet announces itself with a stable `rdns`
 * identifier on an event. We listen for those announcements and pick the
 * provider the user actually asked for. Legacy fallbacks (`providers[]` array
 * and vendor flags) cover wallets that predate EIP-6963.
 */

type Eip6963ProviderInfo = {
    uuid: string;
    name: string;
    icon: string;
    rdns: string;
};

type Eip6963ProviderDetail = {
    info: Eip6963ProviderInfo;
    provider: EthereumProvider;
};

export type EvmWalletProvider = {
    id: string;
    name: string;
    icon?: string;
    rdns?: string;
    provider: EthereumProvider;
    source: 'eip6963' | 'legacy';
};

const RDNS = {
    metamask: 'io.metamask',
    phantom: 'app.phantom',
} as const;

const discovered = new Map<string, Eip6963ProviderDetail>();
let selectedProvider: EvmWalletProvider | null = null;

if (typeof window !== 'undefined') {
    window.addEventListener('eip6963:announceProvider', (event) => {
        const detail = (event as CustomEvent<Eip6963ProviderDetail>).detail;

        if (detail?.info?.uuid && detail.provider) {
            discovered.set(detail.info.uuid, detail);
        }
    });
    // Ask any already-loaded wallets to (re)announce themselves.
    window.dispatchEvent(new Event('eip6963:requestProvider'));
}

export const requestEvmWalletProviders = (): void => {
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new Event('eip6963:requestProvider'));
    }
};

/** Pull MetaMask out of the legacy `window.ethereum.providers` array. */
const fromLegacyArray = (
    predicate: (p: EthereumProvider) => boolean,
): EthereumProvider | null => {
    const eth = window.ethereum;
    const list = eth?.providers;

    if (Array.isArray(list)) {
        return list.find(predicate) ?? null;
    }

    return eth && predicate(eth) ? eth : null;
};

const providerFingerprint = (provider: EthereumProvider): string => {
    const flags = [
        provider.isMetaMask ? 'metamask' : '',
        provider.isPhantom ? 'phantom' : '',
        provider.isCoinbaseWallet ? 'coinbase' : '',
        provider.isTrust || provider.isTrustWallet ? 'trust' : '',
        provider.isRabby ? 'rabby' : '',
        provider.isOkxWallet || provider.isOKExWallet ? 'okx' : '',
        provider.isBraveWallet ? 'brave' : '',
        provider.isBinance ? 'binance' : '',
    ].filter(Boolean);

    return flags.join(':') || 'injected';
};

const legacyName = (provider: EthereumProvider): string => {
    if (provider.isMetaMask && !provider.isPhantom) {
        return 'MetaMask';
    }

    if (provider.isCoinbaseWallet) {
        return 'Coinbase Wallet';
    }

    if (provider.isTrust || provider.isTrustWallet) {
        return 'Trust Wallet';
    }

    if (provider.isRabby) {
        return 'Rabby';
    }

    if (provider.isOkxWallet || provider.isOKExWallet) {
        return 'OKX Wallet';
    }

    if (provider.isBraveWallet) {
        return 'Brave Wallet';
    }

    if (provider.isBinance) {
        return 'Binance Wallet';
    }

    if (provider.isPhantom) {
        return 'Phantom EVM';
    }

    return 'Injected wallet';
};

const pushUnique = (
    list: EvmWalletProvider[],
    item: EvmWalletProvider,
): void => {
    if (list.some((existing) => existing.provider === item.provider)) {
        return;
    }

    list.push(item);
};

export const getEvmWalletProviders = (): EvmWalletProvider[] => {
    if (typeof window === 'undefined') {
        return [];
    }

    requestEvmWalletProviders();

    const providers: EvmWalletProvider[] = [];

    for (const detail of discovered.values()) {
        pushUnique(providers, {
            id: `eip6963:${detail.info.uuid}`,
            name: detail.info.name,
            icon: detail.info.icon,
            rdns: detail.info.rdns,
            provider: detail.provider,
            source: 'eip6963',
        });
    }

    const legacy = window.ethereum?.providers ?? [];

    for (const provider of legacy) {
        pushUnique(providers, {
            id: `legacy:${providerFingerprint(provider)}`,
            name: legacyName(provider),
            provider,
            source: 'legacy',
        });
    }

    if (window.ethereum) {
        pushUnique(providers, {
            id: `legacy:${providerFingerprint(window.ethereum)}`,
            name: legacyName(window.ethereum),
            provider: window.ethereum,
            source: 'legacy',
        });
    }

    return providers.sort((a, b) => a.name.localeCompare(b.name));
};

export const getEvmWalletProvider = (
    id: string,
): EvmWalletProvider | null => {
    return getEvmWalletProviders().find((wallet) => wallet.id === id) ?? null;
};

export const selectEvmWalletProvider = (
    wallet: EvmWalletProvider | null,
): void => {
    selectedProvider = wallet;
};

export const getSelectedEvmProvider = (): EthereumProvider | null =>
    selectedProvider?.provider ?? getEvmProvider();

export const getSelectedEvmWalletProvider = (): EvmWalletProvider | null =>
    selectedProvider;

/**
 * Resolve the genuine MetaMask provider, or null if MetaMask is not installed.
 * Triggers a fresh EIP-6963 announcement on every call so late-injecting wallets
 * are picked up.
 */
export const getMetaMaskProvider = (): EthereumProvider | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    window.dispatchEvent(new Event('eip6963:requestProvider'));

    const announced = [...discovered.values()].find(
        (detail) => detail.info.rdns === RDNS.metamask,
    );

    if (announced) {
        return announced.provider;
    }

    // Legacy: a real MetaMask sets isMetaMask and is not Phantom in disguise.
    return fromLegacyArray((p) => !!p.isMetaMask && !p.isPhantom);
};

export const isMetaMaskInstalled = (): boolean =>
    getMetaMaskProvider() !== null;

/**
 * Best-effort EVM provider for read-only / generic use when no specific wallet
 * was requested: prefer MetaMask, then any announced provider, then the raw
 * injected object.
 */
export const getEvmProvider = (): EthereumProvider | null => {
    return (
        getMetaMaskProvider() ??
        getEvmWalletProviders()[0]?.provider ??
        window.ethereum ??
        null
    );
};
