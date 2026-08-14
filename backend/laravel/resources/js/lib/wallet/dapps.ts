/**
 * What is on this chain, as a wallet can honestly present it.
 *
 * The entries are Cyberia's own surfaces, and they are links rather than
 * embedded applications. That is the whole design decision in this file, so it
 * is written down here rather than discovered later.
 *
 * A page in a browser tab cannot host another site's dapp and hand it a
 * wallet. Two separate walls stand in the way and neither has a workaround: a
 * cross-origin frame cannot be scripted, so no provider can be injected into
 * one; and this vault lives in *this* origin's storage, so a frame that could
 * reach it would be a frame that could read the keys. Every product that does
 * this for real is a browser extension or a native shell, because both sit
 * outside the page and can mediate — which is exactly what Cyberia's extension
 * already does, per origin, with a human in front of every signature.
 *
 * So the wallet's Web tab is a directory and an explanation, and it says which
 * build does the mediating. The alternative — an address bar that quietly only
 * worked for pages we own, or a frame with a fake provider — would be a demo
 * of a browser rather than a browser.
 */

export type DappKind = 'trade' | 'earn' | 'own' | 'govern' | 'move';

export type Dapp = {
    key: string;
    /** Path on this site. Same origin, so the wallet's own links stay internal. */
    path: string;
    /** Two letters, the same shorthand the network marks use. */
    tag: string;
    kind: DappKind;
    /** Whether using it needs a wallet connected to it at all. */
    signs: boolean;
    /** True where the wallet already does this itself, without leaving. */
    inWallet?: boolean;
};

/**
 * Ordered by what someone opens a wallet to do: trade, put it to work, own
 * something, have a say, move it somewhere else.
 */
export const CYBERIA_DAPPS: readonly Dapp[] = [
    {
        key: 'swap',
        path: '/swap',
        tag: 'SW',
        kind: 'trade',
        signs: true,
        inWallet: true,
    },
    {
        key: 'liquidity',
        path: '/liquidity',
        tag: 'LQ',
        kind: 'earn',
        signs: true,
    },
    {
        key: 'farm',
        path: '/farm',
        tag: 'FA',
        kind: 'earn',
        signs: true,
        inWallet: true,
    },
    { key: 'staking', path: '/staking', tag: 'ST', kind: 'earn', signs: true },
    { key: 'lending', path: '/lending', tag: 'LE', kind: 'earn', signs: true },
    {
        key: 'launchpad',
        path: '/launchpad',
        tag: 'LP',
        kind: 'own',
        signs: true,
        inWallet: true,
    },
    {
        key: 'predictions',
        path: '/predictions',
        tag: 'PR',
        kind: 'own',
        signs: true,
    },
    { key: 'tokens', path: '/tokens', tag: 'TK', kind: 'trade', signs: false },
    {
        key: 'dao',
        path: '/dao',
        tag: 'DA',
        kind: 'govern',
        signs: true,
        inWallet: true,
    },
    {
        key: 'bridge',
        path: '/bridge',
        tag: 'BR',
        kind: 'move',
        signs: true,
        inWallet: true,
    },
];

/**
 * How a page gets to talk to a wallet, in this shell.
 *
 * Four answers, and only two of them are yes. Feature-detected rather than
 * guessed from a user agent, because the honest answer is about what this
 * window can actually do.
 */
export type DappBridgeMode = 'extension' | 'desktop' | 'browser' | 'mobile';

export const dappBridgeMode = (
    shell: 'desktop' | 'mobile' | 'telegram' | null,
    hasInjectedProvider: boolean,
): DappBridgeMode => {
    if (hasInjectedProvider) {
        return 'extension';
    }

    if (shell === 'desktop') {
        return 'desktop';
    }

    return shell === null ? 'browser' : 'mobile';
};

/**
 * Whether something in this browser is already offering an EIP-1193 provider
 * to pages — Cyberia's extension, or another wallet.
 *
 * A wallet page cannot tell *which*, and does not claim to: what it says is
 * that pages here have something to talk to.
 */
export const hasInjectedProvider = (): boolean =>
    typeof window !== 'undefined' &&
    (window as { ethereum?: unknown }).ethereum !== undefined;
