import type { EvmChain } from '@/lib/evmChains';

/** Cyberia Fuji as FujiController describes it to the /fuji page. */
export type FujiNetwork = {
    chainId: number;
    name: string;
    rpcUrl: string;
    currency: { name: string; symbol: string; decimals: number };
    explorerTx: string | null;
};

/**
 * The chain as wallet_addEthereumChain takes it, built from the page's props.
 *
 * Every field is copied into a plain object. Inertia hands props over as Vue
 * proxies, and MetaMask posts each request to its extension with
 * window.postMessage, which cannot clone a proxy: the add then fails before
 * the wallet ever shows it, and the page reports the switch that preceded it.
 */
export const fujiChain = (network: FujiNetwork): EvmChain => ({
    chainId: Number(network.chainId),
    name: String(network.name),
    nativeCurrency: {
        name: String(network.currency.name),
        symbol: String(network.currency.symbol),
        decimals: Number(network.currency.decimals),
    },
    rpcUrls: [String(network.rpcUrl)],
    status: 'live',
});
