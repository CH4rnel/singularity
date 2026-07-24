import { CYBER_SOL_ADDRESS, WCYBER_ADDRESS } from '@/lib/cyberiaTokens';
import {
    CYBERIA_CHAIN,
    CYBERIA_CHAIN_ID,
    cyberiaReadRpcUrl,
    EVM_CHAINS,
} from '@/lib/evmChains';
import type { EvmChain } from '@/lib/evmChains';

/**
 * Per-chain wiring for the Ritual MasterChef farms. The /farm page reads pools
 * from whichever chain the wallet is on (defaulting to Cyberia), so every
 * chain-specific address lives here rather than hardcoded in the page.
 */
export type FarmChainConfig = {
    chainId: number;
    /** EVM chain descriptor used to switch the wallet before staking. */
    evmChain: EvmChain;
    /** Read-only RPC (proxied for Cyberia, public for the rest). */
    readRpcUrl: string;
    masterchef: string;
    factory: string;
    /** Wrapped native token used as the pricing quote (WCYBER / WETH). */
    quoteToken: string;
    /** Display symbol for the quote token (CYBER / ETH). */
    quoteSymbol: string;
    /** Optional relay token for a second pricing hop (CYBER.sol on Cyberia). */
    bridgeToken: string | null;
    /** Stablecoins to derive the quote token's USD price; empty ⇒ no USD line. */
    stableTokens: string[];
    explorer: string;
    /** Hosted swap UI for the "Get LP" link; null when the chain has none. */
    dexUrl: string | null;
    /**
     * Chef pools to hide from the UI, by lpToken address (lowercase). Used for
     * EmissionChannel placeholder pools — they carve a satellite chain's ASH
     * share on the Cyberia chef but are not real farms.
     */
    hiddenPools?: string[];
};

// EmissionChannel placeholder staked on the Cyberia chef to route Robinhood's
// share of ASH emission to the funding keeper — never a real farm.
const CYBERIA_ROBINHOOD_CHANNEL =
    '0x7De888cEf3CF3c24c20845E61A2964937Be6b199'.toLowerCase();

const ROBINHOOD_CHAIN = EVM_CHAINS.find((c) => c.chainId === 4663)!;

export const ROBINHOOD_CHAIN_ID = 4663;

export const FARM_CHAINS: readonly FarmChainConfig[] = [
    {
        chainId: CYBERIA_CHAIN_ID,
        evmChain: CYBERIA_CHAIN,
        readRpcUrl: cyberiaReadRpcUrl(),
        masterchef: '0xd540DEa828567160FFDe5e792ca359aDD1f6B03D',
        factory: '0xB0aC30907c04b61F1482e62eA66eF4562a690917',
        quoteToken: WCYBER_ADDRESS,
        quoteSymbol: 'CYBER',
        bridgeToken: CYBER_SOL_ADDRESS,
        stableTokens: [
            '0xdc25597B19799010047F17e9591EFE08EFd40077', // USDC
            '0x94845aF24a3E431593A2b941b2b31836dE45185D', // USDT
        ],
        explorer: 'https://explorer.cyberia.church',
        dexUrl: 'https://swap.cyberia.church',
        hiddenPools: [CYBERIA_ROBINHOOD_CHANNEL],
    },
    {
        chainId: ROBINHOOD_CHAIN_ID,
        evmChain: ROBINHOOD_CHAIN,
        readRpcUrl: 'https://rpc.mainnet.chain.robinhood.com',
        // FundedFarm (pays bridged ASH from balance, no local mint) — replaces
        // the retired standalone MasterChef 0x78272… .
        masterchef: '0x4798f67d8D741dC09Ae4409dA2d180524E72A99c',
        factory: '0xD199e6ae74B992F017f8940B26Fa18A7dD30eE86',
        quoteToken: '0x0Bd7D308f8E1639FAb988df18A8011f41EAcAD73', // canonical aeWETH
        quoteSymbol: 'ETH',
        bridgeToken: null,
        // Our factory on Robinhood has no stablecoin pool yet, so TVL is quoted
        // in ETH only (APY still works — it is quote-denominated end to end).
        stableTokens: [],
        explorer: 'https://robinhoodchain.blockscout.com',
        dexUrl: null,
    },
];

export const DEFAULT_FARM_CHAIN_ID = CYBERIA_CHAIN_ID;

export const farmChainById = (chainId: number | null): FarmChainConfig =>
    FARM_CHAINS.find((c) => c.chainId === chainId) ??
    FARM_CHAINS.find((c) => c.chainId === DEFAULT_FARM_CHAIN_ID)!;

// Wrapped-native tokens read back their own ticker (WCYBER / WETH); show the
// underlying coin instead so pools read "CYBER"/"ETH", not the wrapper name.
const DISPLAY_SYMBOL_OVERRIDES: Record<string, string> = {
    WCYBER: 'CYBER',
    WETH: 'ETH',
};

export const displayFarmSymbol = (symbol: string): string =>
    DISPLAY_SYMBOL_OVERRIDES[symbol.toUpperCase()] ?? symbol;
