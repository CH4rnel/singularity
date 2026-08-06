import { walletChain, walletChains } from '@/lib/wallet/chains';
import type {
    WalletCapabilities,
    WalletChainFamily,
    WalletChainId,
    WalletMark,
} from '@/lib/wallet/chains';
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
    /** Which key this address belongs to — every `evm` account shares one. */
    family: WalletChainFamily;
    mark: WalletMark;
    /** Added by the user: a real account read through an unvetted endpoint. */
    custom: boolean;
    endpoint?: string;
    address: string;
    path: string;
    curve: 'secp256k1' | 'ed25519';
    capabilities: WalletCapabilities;
    note?: string;
    explorerUrl: string | null;
};

/**
 * Public accounts for every registered chain, derived from one phrase.
 *
 * The registry is read at call time rather than captured, because a network the
 * user adds has to produce an account without the wallet being reopened — and
 * because that account must come from the seed, not from anything stored
 * alongside the network's settings.
 */
export const deriveAccounts = (phrase: string): WalletAccount[] => {
    const seed = seedFromMnemonic(phrase);

    return walletChains().map((chain) => {
        const address = chain.derive(seed);

        return {
            chain: chain.id,
            label: chain.label,
            symbol: chain.symbol,
            decimals: chain.decimals,
            family: chain.family,
            mark: chain.mark,
            custom: chain.custom ?? false,
            endpoint: chain.endpoint,
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
    WALLET_FAMILY_GROUPS,
    WALLET_FEE_TIERS,
    formatUnits,
    parseUnits,
    setCustomWalletChains,
    walletChain,
    walletChains,
} from '@/lib/wallet/chains';
export type {
    WalletBuiltinChainId,
    WalletCapabilities,
    WalletChain,
    WalletChainFamily,
    WalletChainId,
    WalletFeeQuote,
    WalletFeeTier,
    WalletMark,
    WalletMarkShape,
    WalletTx,
    WalletTxStatus,
} from '@/lib/wallet/chains';
export {
    FORK_PRESETS,
    customNetworkId,
    customNetworkTag,
    customWalletChain,
    evmPresets,
    readCustomNetworks,
    validateCustomNetwork,
    writeCustomNetworks,
} from '@/lib/wallet/customChains';
export type {
    CustomEvmNetwork,
    CustomNetwork,
    CustomNetworkProblem,
    CustomUtxoNetwork,
} from '@/lib/wallet/customChains';
export type { UtxoAddressType } from '@/lib/wallet/utxo';
export {
    ERC20_TRANSFER_GAS_CAP,
    blockscoutTokens,
    erc20Balance,
    mergeTokens,
    readErc20,
    sameToken,
    sendErc20,
} from '@/lib/wallet/erc20';
export type { WalletTokenBalance } from '@/lib/wallet/erc20';
export {
    readManualTokens,
    withToken,
    withoutToken,
    writeManualTokens,
} from '@/lib/wallet/tokenList';
export type { ManualToken } from '@/lib/wallet/tokenList';
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
