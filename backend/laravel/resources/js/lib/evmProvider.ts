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
 * provider the user actually asked for. Legacy fallbacks (`providers[]` array,
 * the `isMetaMask`/`isPhantom` flags) cover wallets that predate EIP-6963.
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

const RDNS = {
    metamask: 'io.metamask',
    phantom: 'app.phantom',
} as const;

const discovered = new Map<string, Eip6963ProviderDetail>();

if (typeof window !== 'undefined') {
    window.addEventListener('eip6963:announceProvider', (event) => {
        const detail = (event as CustomEvent<Eip6963ProviderDetail>).detail;

        if (detail?.info?.rdns) {
            discovered.set(detail.info.rdns, detail);
        }
    });
    // Ask any already-loaded wallets to (re)announce themselves.
    window.dispatchEvent(new Event('eip6963:requestProvider'));
}

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

    const announced = discovered.get(RDNS.metamask);

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
        discovered.values().next().value?.provider ??
        window.ethereum ??
        null
    );
};
