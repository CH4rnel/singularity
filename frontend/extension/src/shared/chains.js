/**
 * The EVM chains this extension can sign for.
 *
 * Mirrors `backend/laravel/resources/js/lib/evmChains.ts` and the wallet's own
 * registry: same ids, same RPCs, same currencies, so an account behaves the
 * same in the popup as it does on the site. Every entry here is also listed in
 * `manifest.json` under `host_permissions` — a chain whose RPC the extension
 * may not reach is a chain it cannot honestly offer.
 *
 * `priceKey` is the key the site's own quote endpoint uses for that chain's
 * native coin; a chain without one is simply never priced.
 */

/** Never hardcode the hex form — 49406 is 0xC0FE and typing it by hand is how
 * seven pages once ended up on 0xc11e. */
export const chainIdHex = (chainId) => `0x${chainId.toString(16)}`;

export const CYBERIA_CHAIN_ID = 49406;

export const CHAINS = [
    {
        id: CYBERIA_CHAIN_ID,
        name: 'Cyberia',
        symbol: 'CYBER',
        decimals: 18,
        rpc: 'https://rpc.cyberia.church',
        explorer: 'https://explorer.cyberia.church',
        // Keyless Blockscout index: one call returns symbol, decimals and
        // balance, which is why nothing here hardcodes a token's decimals.
        tokens: 'https://explorer.cyberia.church/api',
        priceKey: 'cyberia',
        tag: 'CY',
        color: '#2FE9E0',
    },
    {
        id: 4663,
        name: 'Robinhood Chain',
        symbol: 'ETH',
        decimals: 18,
        rpc: 'https://rpc.mainnet.chain.robinhood.com',
        explorer: 'https://robinhoodchain.blockscout.com',
        tokens: null,
        priceKey: 'robinhood',
        tag: 'RH',
        color: '#8B7BF7',
    },
    {
        id: 56,
        name: 'BNB Smart Chain',
        symbol: 'BNB',
        decimals: 18,
        rpc: 'https://bsc-dataseed.binance.org',
        explorer: 'https://bscscan.com',
        tokens: null,
        priceKey: 'bnb',
        tag: 'BN',
        color: '#E8B44A',
    },
    {
        id: 8453,
        name: 'Base',
        symbol: 'ETH',
        decimals: 18,
        rpc: 'https://mainnet.base.org',
        explorer: 'https://basescan.org',
        tokens: null,
        priceKey: 'base',
        tag: 'BA',
        color: '#6E93B8',
    },
];

export const DEFAULT_CHAIN_ID = CYBERIA_CHAIN_ID;

export const chainById = (chainId) =>
    CHAINS.find((chain) => chain.id === Number(chainId)) ?? null;

/** A chain id as a dapp writes it — `0x…` — parsed leniently, decimal or hex. */
export const parseChainId = (value) => {
    if (typeof value === 'number') {
        return Number.isSafeInteger(value) && value > 0 ? value : null;
    }

    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    const parsed = /^0[xX]/.test(value) ? Number.parseInt(value, 16) : Number(value);

    return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null;
};

/** Where the site's USD quotes come from. Its own project, not a vendor. */
export const PRICES_URL = 'https://cyberia.church/api/wallet/prices';

/** The full wallet — send screens, other ecosystems, DAO — lives on the site. */
export const SITE_URL = 'https://cyberia.church';
