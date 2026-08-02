import { WALLET_CHAINS, walletChain } from '@/lib/wallet/chains';
import type { WalletCapabilities, WalletChainId } from '@/lib/wallet/chains';
import { seedFromMnemonic } from '@/lib/wallet/vault';

/**
 * The unified multichain wallet: one seed phrase, one derivation tree, one
 * address per supported chain.
 *
 * Everything a caller gets from here is public — addresses, paths, labels.
 * The seed exists only inside `deriveAccounts` and inside a single `send`
 * call; it is never returned, stored in module state, or logged.
 */

export type WalletAccount = {
    chain: WalletChainId;
    label: string;
    symbol: string;
    decimals: number;
    address: string;
    path: string;
    curve: 'secp256k1' | 'ed25519';
    capabilities: WalletCapabilities;
    note?: string;
    explorerUrl: string | null;
};

/** Public accounts for every registered chain, derived from one phrase. */
export const deriveAccounts = (phrase: string): WalletAccount[] => {
    const seed = seedFromMnemonic(phrase);

    return WALLET_CHAINS.map((chain) => {
        const address = chain.derive(seed);

        return {
            chain: chain.id,
            label: chain.label,
            symbol: chain.symbol,
            decimals: chain.decimals,
            address,
            path: chain.path,
            curve: chain.curve,
            capabilities: chain.capabilities,
            note: chain.note,
            explorerUrl: chain.explorerAddressUrl(address),
        };
    });
};

/** Address for a single chain, without deriving the rest. */
export const deriveAddress = (phrase: string, chain: WalletChainId): string =>
    walletChain(chain).derive(seedFromMnemonic(phrase));

export {
    WALLET_CHAINS,
    formatUnits,
    parseUnits,
    walletChain,
} from '@/lib/wallet/chains';
export type {
    WalletCapabilities,
    WalletChain,
    WalletChainId,
} from '@/lib/wallet/chains';
export {
    createMnemonic,
    forgetVault,
    hasVault,
    isValidMnemonic,
    normalizeMnemonic,
    openVault,
    readVault,
    saveVault,
    seedFromMnemonic,
} from '@/lib/wallet/vault';
