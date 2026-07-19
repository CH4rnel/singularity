import { CYBERIA_CHAIN_ID, PHANTOM_EVM_CHAIN_IDS } from '@/lib/evmChains';
import type { EvmWalletProvider } from '@/lib/evmProvider';
import type { SolanaWalletProvider } from '@/lib/solanaWalletProvider';

/**
 * Wallet pickers show one row per wallet, not one per (wallet × network).
 * Multi-chain wallets (Phantom, MetaMask, …) inject both an EVM and a Solana
 * provider under the same brand name, so listing the raw providers renders
 * the same wallet twice; here they are merged by normalised name and keep a
 * per-network provider id each.
 */
export type WalletChoice = {
    key: string;
    name: string;
    icon?: string;
    evmId?: string;
    solanaId?: string;
    /** False for curated popular wallets that were not detected in the browser. */
    installed: boolean;
    /** Homepage to install the wallet from; set on non-installed entries. */
    installUrl?: string;
};

/**
 * Curated popular wallets. Anything not detected in the browser is still
 * listed (greyed, linking to its install page) so users see what the app
 * supports rather than an empty or two-item picker. Mobile wallets beyond
 * this list are reachable through the WalletConnect entry.
 */
const POPULAR_WALLETS: {
    key: string;
    name: string;
    installUrl: string;
}[] = [
    { key: 'metamask', name: 'MetaMask', installUrl: 'https://metamask.io' },
    { key: 'phantom', name: 'Phantom', installUrl: 'https://phantom.com' },
    { key: 'rabby', name: 'Rabby', installUrl: 'https://rabby.io' },
    {
        key: 'trust',
        name: 'Trust Wallet',
        installUrl: 'https://trustwallet.com',
    },
    {
        key: 'coinbase',
        name: 'Coinbase Wallet',
        installUrl: 'https://www.coinbase.com/wallet',
    },
    { key: 'okx', name: 'OKX Wallet', installUrl: 'https://web3.okx.com' },
    { key: 'solflare', name: 'Solflare', installUrl: 'https://solflare.com' },
    { key: 'backpack', name: 'Backpack', installUrl: 'https://backpack.app' },
];

// "Trust Wallet" (EVM) vs "Trust" (Solana), "Phantom EVM" (legacy flag
// fallback) vs "Phantom" — same wallet, different self-reported labels.
const nameKey = (name: string): string =>
    name
        .toLowerCase()
        .replace(/\s+(wallet|evm)$/, '')
        .trim();

// Wallets whose EVM side only serves a fixed chain list and rejects
// wallet_addEthereumChain. Their EVM provider must not be invoked for any
// other chain (it only pops an "unsupported network" error), and since
// Cyberia is never in such lists their EVM entry stays out of the pickers
// (Phantom still appears through its Solana side).
const EVM_FIXED_CHAINS = new Map<string, readonly number[]>([
    ['phantom', PHANTOM_EVM_CHAIN_IDS],
]);

const walletKey = (wallet: { name: string; rdns?: string }): string =>
    wallet.rdns === 'app.phantom' ? 'phantom' : nameKey(wallet.name);

export const evmWalletSupportsChain = (
    wallet: { name: string; rdns?: string },
    chainId: number,
): boolean => {
    const fixed = EVM_FIXED_CHAINS.get(walletKey(wallet));

    return !fixed || fixed.includes(chainId);
};

export const evmWalletSupportsCyberia = (wallet: {
    name: string;
    rdns?: string;
}): boolean => evmWalletSupportsChain(wallet, CYBERIA_CHAIN_ID);

export const mergeWalletChoices = (
    evm: EvmWalletProvider[],
    solana: SolanaWalletProvider[],
): WalletChoice[] => {
    const map = new Map<string, WalletChoice>();

    for (const provider of evm) {
        if (!evmWalletSupportsCyberia(provider)) {
            continue;
        }

        const key = nameKey(provider.name);
        const existing = map.get(key);

        if (existing) {
            existing.evmId ??= provider.id;
            existing.icon ??= provider.icon;
        } else {
            map.set(key, {
                key,
                name: provider.name,
                icon: provider.icon,
                evmId: provider.id,
                installed: true,
            });
        }
    }

    for (const provider of solana) {
        const key = nameKey(provider.name);
        const existing = map.get(key);

        if (existing) {
            existing.solanaId ??= provider.id;
            existing.icon ??= provider.icon;
        } else {
            map.set(key, {
                key,
                name: provider.name,
                icon: provider.icon,
                solanaId: provider.id,
                installed: true,
            });
        }
    }

    for (const wallet of POPULAR_WALLETS) {
        if (!map.has(wallet.key)) {
            map.set(wallet.key, {
                key: wallet.key,
                name: wallet.name,
                installed: false,
                installUrl: wallet.installUrl,
            });
        }
    }

    // Installed wallets first, WalletConnect closing that group as the
    // QR/mobile fallback, then the not-installed suggestions.
    const rank = (choice: WalletChoice): number =>
        choice.installed ? (choice.key === 'walletconnect' ? 1 : 0) : 2;

    return [...map.values()].sort(
        (a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name),
    );
};
