import { CYBERIA_CHAIN, EVM_CHAINS, cyberiaReadRpcUrl } from '@/lib/evmChains';
import type { EvmChain } from '@/lib/evmChains';

/**
 * Where the shared NFT collection is deployed.
 *
 * `CyberiaNFT` (crypto/hardhat/contracts/CyberiaNFT.sol) is one ERC-721 that
 * anyone may mint into, with the tokenURI supplied at mint time. There is no
 * per-user collection to deploy and no allowlist to be on — the collection is
 * a public commons, and what a token *is* lives entirely in the URI its minter
 * wrote.
 *
 * Chain parameters come from the shared registry in `evmChains.ts`; this file
 * only adds the address, taken from `crypto/hardhat/deployments/*.json`.
 */
export type NftChain = {
    chain: EvmChain;
    /** CyberiaNFT address; null on a chain where none is deployed yet. */
    collection: string | null;
    /** Blockscout instance — its v2 API is what lists an address's tokens. */
    explorerUrl: string;
};

const env = (import.meta.env ?? {}) as Record<string, string | undefined>;

const evmChain = (chainId: number): EvmChain =>
    EVM_CHAINS.find((entry) => entry.chainId === chainId) ?? CYBERIA_CHAIN;

export const NFT_CHAINS: readonly NftChain[] = [
    {
        // deployments/cyberia-nft-market.json
        chain: CYBERIA_CHAIN,
        collection:
            env.VITE_NFT_COLLECTION_49406 ??
            '0x546462FAbf30734E63b64f32B30EC8ADD9B6EBa7',
        explorerUrl: 'https://explorer.cyberia.church',
    },
    {
        // Robinhood runs a Blockscout, so tokens there can be listed; nothing
        // is deployed to mint into yet, which is why this row exists at all —
        // adding the address here is the whole of turning minting on.
        chain: evmChain(4663),
        collection: env.VITE_NFT_COLLECTION_4663 ?? null,
        explorerUrl: 'https://robinhoodchain.blockscout.com',
    },
];

/** Chains this wallet can mint on — the only ones the NFT screen offers. */
export const mintableChains = (): NftChain[] =>
    NFT_CHAINS.filter((entry) => entry.collection !== null);

export const nftChain = (chainId: number): NftChain | null =>
    NFT_CHAINS.find((entry) => entry.chain.chainId === chainId) ?? null;

/**
 * The endpoint reads go through.
 *
 * Cyberia is read through this site's own proxy — the node speaks http, and a
 * browser on an https page will not talk to it directly. Every other chain
 * carries its own public RPC.
 */
export const nftReadRpcUrl = (target: NftChain): string =>
    target.chain.chainId === CYBERIA_CHAIN.chainId
        ? cyberiaReadRpcUrl()
        : target.chain.rpcUrls[0];
