import {
    CYBERIA_CHAIN,
    CYBERIA_CHAIN_ID,
    cyberiaReadRpcUrl,
    EVM_CHAINS,
} from '@/lib/evmChains';
import type { EvmChain } from '@/lib/evmChains';

/**
 * Per-chain wiring for the Ritual DEX liquidity page. Each chain has its own
 * router/factory/pools, so /liquidity reads and trades entirely within the
 * wallet's chain — Robinhood liquidity never mixes with Cyberia's.
 */
export type LiquidityChainConfig = {
    chainId: number;
    evmChain: EvmChain;
    readRpcUrl: string;
    router: string;
    factory: string;
    /** Wrapped native token the NATIVE sentinel maps to (WCYBER / WETH). */
    wrappedNative: string;
    /** Native coin symbol shown in the picker (CYBER / ETH). */
    nativeSymbol: string;
    explorer: string;
    /**
     * Curated pickable tokens (excluding the native coin). Cyberia leaves this
     * empty and draws its token universe from the server pool snapshot +
     * KNOWN_TOKENS; satellites list their bridged assets here.
     */
    tokens: { address: string; symbol: string }[];
    /**
     * Cyberia gets its pool list + APR from the server indexer; satellites are
     * client-only (pairs discovered on-chain, no APR snapshot).
     */
    serverPools: boolean;
};

const ROBINHOOD_CHAIN = EVM_CHAINS.find((c) => c.chainId === 4663)!;

export const LIQUIDITY_CHAINS: readonly LiquidityChainConfig[] = [
    {
        chainId: CYBERIA_CHAIN_ID,
        evmChain: CYBERIA_CHAIN,
        readRpcUrl: cyberiaReadRpcUrl(),
        router: '0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62',
        factory: '0xB0aC30907c04b61F1482e62eA66eF4562a690917',
        wrappedNative: '0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9',
        nativeSymbol: 'CYBER',
        explorer: 'https://explorer.cyberia.church',
        tokens: [],
        serverPools: true,
    },
    {
        chainId: 4663,
        evmChain: ROBINHOOD_CHAIN,
        readRpcUrl: 'https://rpc.mainnet.chain.robinhood.com',
        router: '0xB0aC30907c04b61F1482e62eA66eF4562a690917',
        factory: '0xD199e6ae74B992F017f8940B26Fa18A7dD30eE86',
        wrappedNative: '0x0Bd7D308f8E1639FAb988df18A8011f41EAcAD73', // aeWETH
        nativeSymbol: 'ETH',
        explorer: 'https://robinhoodchain.blockscout.com',
        tokens: [
            {
                address: '0x753979e6585CCa139fbB1918966D563a25eEB3B2',
                symbol: 'CYBER',
            },
            {
                address: '0xa284bF7D1d941ED8dEd25f8E592003E9e5373284',
                symbol: 'ASH',
            },
        ],
        serverPools: false,
    },
];

export const DEFAULT_LIQUIDITY_CHAIN_ID = CYBERIA_CHAIN_ID;

export const liquidityChainById = (
    chainId: number | null,
): LiquidityChainConfig =>
    LIQUIDITY_CHAINS.find((c) => c.chainId === chainId) ??
    LIQUIDITY_CHAINS.find((c) => c.chainId === DEFAULT_LIQUIDITY_CHAIN_ID)!;
