import { BrowserProvider } from 'ethers';
import { getSelectedEvmProvider } from '@/lib/evmProvider';
import type { EthereumProvider } from '@/types/global';

/**
 * Shared EVM chain registry.
 *
 * Single source of truth for chain parameters (id, currency, RPC, explorer)
 * used by the network-switch flows, read-only providers, and the
 * WalletConnect multichain config. Pages must not hardcode chain ids or hex
 * strings — the hex form is always derived from the numeric id here.
 */

/**
 * live — selectable in the network picker; coming_soon — listed greyed-out
 * as a roadmap teaser; wip — greyed-out, actively being integrated.
 */
export type EvmChainStatus = 'live' | 'coming_soon' | 'wip';

export type EvmChain = {
    chainId: number;
    name: string;
    nativeCurrency: { name: string; symbol: string; decimals: number };
    rpcUrls: string[];
    blockExplorerUrls?: string[];
    status: EvmChainStatus;
};

export const chainIdHex = (chainId: number): string =>
    '0x' + chainId.toString(16);

export const CYBERIA_CHAIN_ID = 49406;
export const CYBERIA_CHAIN_ID_HEX = chainIdHex(CYBERIA_CHAIN_ID);
export const CYBERIA_RPC = '/api/rpc/cyberia';
export const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';

export const CYBERIA_CHAIN: EvmChain = {
    chainId: CYBERIA_CHAIN_ID,
    name: 'Cyberia',
    nativeCurrency: { name: 'Cyber', symbol: 'CYBER', decimals: 18 },
    rpcUrls: [CYBERIA_PUBLIC_RPC],
    blockExplorerUrls: ['https://explorer.cyberia.church'],
    status: 'live',
};

/**
 * EVM chains shown in the network picker and offered to multichain wallets
 * (WalletConnect optionalChains). Only `live` chains are selectable; the
 * rest are roadmap teasers. Robinhood Chain params mirror config/bridge.php.
 */
export const EVM_CHAINS: readonly EvmChain[] = [
    CYBERIA_CHAIN,
    {
        chainId: 4663,
        name: 'Robinhood Chain',
        nativeCurrency: { name: 'Ethereum', symbol: 'ETH', decimals: 18 },
        rpcUrls: ['https://rpc.mainnet.chain.robinhood.com'],
        blockExplorerUrls: ['https://robinhoodchain.blockscout.com'],
        status: 'wip',
    },
    {
        chainId: 1,
        name: 'Ethereum',
        nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 },
        rpcUrls: ['https://cloudflare-eth.com'],
        blockExplorerUrls: ['https://etherscan.io'],
        status: 'coming_soon',
    },
    {
        chainId: 56,
        name: 'BNB Smart Chain',
        nativeCurrency: { name: 'BNB', symbol: 'BNB', decimals: 18 },
        rpcUrls: ['https://bsc-dataseed.binance.org'],
        blockExplorerUrls: ['https://bscscan.com'],
        status: 'coming_soon',
    },
    {
        chainId: 137,
        name: 'Polygon',
        nativeCurrency: { name: 'POL', symbol: 'POL', decimals: 18 },
        rpcUrls: ['https://polygon-rpc.com'],
        blockExplorerUrls: ['https://polygonscan.com'],
        status: 'coming_soon',
    },
    {
        chainId: 8453,
        name: 'Base',
        nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 },
        rpcUrls: ['https://mainnet.base.org'],
        blockExplorerUrls: ['https://basescan.org'],
        status: 'wip',
    },
    {
        chainId: 42161,
        name: 'Arbitrum One',
        nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 },
        rpcUrls: ['https://arb1.arbitrum.io/rpc'],
        blockExplorerUrls: ['https://arbiscan.io'],
        status: 'coming_soon',
    },
    {
        chainId: 10,
        name: 'OP Mainnet',
        nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 },
        rpcUrls: ['https://mainnet.optimism.io'],
        blockExplorerUrls: ['https://optimistic.etherscan.io'],
        status: 'coming_soon',
    },
];

export const evmChainRpcMap = (): Record<number, string> =>
    Object.fromEntries(
        EVM_CHAINS.map((chain) => [chain.chainId, chain.rpcUrls[0]]),
    );

/**
 * Phantom's EVM side is locked to this list (Ethereum, Polygon, Base, Monad)
 * and rejects wallet_addEthereumChain, so asking it for anything else only
 * produces an "unsupported network" popup. Guarded here so Phantom is never
 * even invoked for other chains.
 */
export const PHANTOM_EVM_CHAIN_IDS: readonly number[] = [1, 137, 8453, 143];

/** Whether this injected provider can be asked to switch to `chainId` at all. */
export const evmProviderSupportsChain = (
    eth: EthereumProvider,
    chainId: number,
): boolean => !eth.isPhantom || PHANTOM_EVM_CHAIN_IDS.includes(chainId);

/**
 * Read-only Cyberia RPC endpoint: the same-origin proxy in the browser (no
 * CORS, no wallet), the public RPC during SSR.
 */
export const cyberiaReadRpcUrl = (): string =>
    typeof window !== 'undefined'
        ? window.location.origin + CYBERIA_RPC
        : CYBERIA_PUBLIC_RPC;

/**
 * Switch the wallet to `chain`, adding it first when the wallet does not know
 * it yet. Mobile/WalletConnect wallets report "unknown chain" with codes other
 * than 4902, so any switch failure falls through to an add attempt; if the add
 * also fails the original switch error is thrown.
 */
export const ensureEvmChain = async (
    eth: EthereumProvider,
    chain: EvmChain,
): Promise<void> => {
    const currentHex = (await eth.request({
        method: 'eth_chainId',
    })) as string;

    if (parseInt(currentHex, 16) === chain.chainId) {
        return;
    }

    if (!evmProviderSupportsChain(eth, chain.chainId)) {
        throw new Error(
            `Phantom cannot switch to ${chain.name} — it has a fixed network list. Use MetaMask or another EVM wallet.`,
        );
    }

    const targetHex = chainIdHex(chain.chainId);

    try {
        await eth.request({
            method: 'wallet_switchEthereumChain',
            params: [{ chainId: targetHex }],
        });
    } catch (switchError) {
        try {
            await eth.request({
                method: 'wallet_addEthereumChain',
                params: [
                    {
                        chainId: targetHex,
                        chainName: chain.name,
                        nativeCurrency: chain.nativeCurrency,
                        rpcUrls: chain.rpcUrls,
                        blockExplorerUrls: chain.blockExplorerUrls ?? [],
                    },
                ],
            });
        } catch {
            throw switchError;
        }
    }
};

/**
 * Switch the selected wallet to `chain` and hand back a fresh ethers provider
 * on it. Used by pages that transact on more than one chain (the launchpad
 * walks its selected networks one by one).
 */
export const ensureEvmNetwork = async (
    chain: EvmChain,
): Promise<BrowserProvider> => {
    const eth = getSelectedEvmProvider();

    if (!eth) {
        throw new Error('EVM wallet not found');
    }

    await ensureEvmChain(eth, chain);

    return new BrowserProvider(eth);
};

/**
 * Drop-in helper for pages that transact on Cyberia: switch the selected
 * wallet to the Cyberia chain and hand back a fresh ethers provider on it.
 */
export const ensureCyberiaNetwork = (): Promise<BrowserProvider> =>
    ensureEvmNetwork(CYBERIA_CHAIN);
