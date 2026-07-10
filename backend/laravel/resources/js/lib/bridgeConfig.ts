/**
 * Runtime bridge configuration fed from the server (config/bridge.php →
 * ApiController@index → Inertia props). Once initBridgeConfig() runs, chains,
 * routes and tokens all come from the backend config — adding a new EVM chain
 * is a config-only change with no frontend edits. The static tables in
 * lib/addressValidation.ts and lib/bridgeTokens.ts are pre-init fallbacks.
 */
import type {
    BridgeChain,
    BridgeDirection,
    BridgeRoute,
} from '@/lib/addressValidation';
import { setBridgeRouteOverrides } from '@/lib/addressValidation';
import { BRIDGE_TOKENS, tokenBySymbol } from '@/lib/bridgeTokens';

export type PublicChain = {
    key: string;
    label: string;
    type: 'evm' | 'solana' | 'ton' | 'yenten' | (string & {});
    addressType: string;
    wallet: 'evm' | 'solana' | 'ton' | 'manual';
    evmChainId: number | null;
    rpcUrl: string | null;
    explorerTx: string | null;
    explorerTxFallbacks?: string[];
    nativeCurrency: { name: string; symbol: string; decimals: number } | null;
    depositAddress: string | null;
};

export type PublicRouteData = BridgeRoute & {
    /** Whether the server will currently accept submissions for this route. */
    operational?: boolean;
    /** Optional operator-facing reason for a visible but disabled route. */
    unavailableReason?: string | null;
    /** Token symbols available on this route (server-computed). */
    tokens: string[];
};

export type PublicTokenChain = {
    address: string | null;
    mint: string | null;
    master: string | null;
    native: boolean;
    decimals: number;
    tokenProgram: 'token' | 'token-2022' | null;
};

export type PublicToken = {
    symbol: string;
    model: 'native' | 'direct' | 'mint';
    chains: Record<string, PublicTokenChain>;
};

let chainMap: Record<string, PublicChain> | null = null;
let routeList: PublicRouteData[] | null = null;
let tokenMap: Record<string, PublicToken> | null = null;

export function initBridgeConfig(
    chains: PublicChain[] | undefined,
    routes: PublicRouteData[] | undefined,
    tokens: PublicToken[] | undefined,
): void {
    if (chains?.length) {
        chainMap = Object.fromEntries(chains.map((chain) => [chain.key, chain]));
    }

    if (routes?.length) {
        routeList = routes;
        setBridgeRouteOverrides(routes);
    }

    if (tokens?.length) {
        tokenMap = Object.fromEntries(tokens.map((token) => [token.symbol, token]));
    }
}

/** Static fallbacks for first paint / SSR before props are applied. */
const FALLBACK_EXPLORERS: Record<string, string> = {
    cyberia: 'https://explorer.cyberia.church/tx/{hash}',
    solana: 'https://solscan.io/tx/{hash}',
    ton: 'https://tonviewer.com/transaction/{hash}',
    bnb: 'https://bscscan.com/tx/{hash}',
    base: 'https://basescan.org/tx/{hash}',
    yenten: 'https://explorer.yentencoin.info/tx/{hash}',
    bitcoin: 'https://mempool.space/tx/{hash}',
    litecoin: 'https://litecoinspace.org/tx/{hash}',
    monero: 'https://xmrchain.net/tx/{hash}',
};

const FALLBACK_EXPLORER_FALLBACKS: Record<string, string[]> = {
    yenten: ['https://explorer2.yentencoin.info/tx/{hash}'],
};

const FALLBACK_CHAINS: Record<string, Partial<PublicChain>> = {
    cyberia: {
        label: 'Cyberia EVM',
        type: 'evm',
        wallet: 'evm',
        evmChainId: 49406,
        rpcUrl: 'https://rpc.cyberia.church',
        nativeCurrency: { name: 'Cyber', symbol: 'CYBER', decimals: 18 },
    },
    bnb: {
        label: 'BNB Chain',
        type: 'evm',
        wallet: 'evm',
        evmChainId: 56,
        rpcUrl: 'https://bsc-dataseed.binance.org',
        nativeCurrency: { name: 'BNB', symbol: 'BNB', decimals: 18 },
    },
    base: {
        label: 'Base',
        type: 'evm',
        wallet: 'evm',
        evmChainId: 8453,
        rpcUrl: 'https://mainnet.base.org',
        nativeCurrency: { name: 'Ethereum', symbol: 'ETH', decimals: 18 },
    },
    bitcoin: {
        label: 'Bitcoin',
        type: 'bitcoin',
        addressType: 'bitcoin',
        wallet: 'manual',
    },
    litecoin: {
        label: 'Litecoin',
        type: 'litecoin',
        addressType: 'litecoin',
        wallet: 'manual',
    },
    monero: {
        label: 'Monero',
        type: 'monero',
        addressType: 'monero',
        wallet: 'manual',
    },
};

export function bridgeChainInfo(key: BridgeChain): PublicChain | null {
    if (chainMap?.[key]) {
        return chainMap[key];
    }

    const fallback = FALLBACK_CHAINS[key];

    if (!fallback) {
        return null;
    }

    return {
        key,
        label: fallback.label ?? key,
        type: fallback.type ?? 'evm',
        addressType: fallback.addressType ?? 'evm',
        wallet: fallback.wallet ?? 'evm',
        evmChainId: fallback.evmChainId ?? null,
        rpcUrl: fallback.rpcUrl ?? null,
        explorerTx: FALLBACK_EXPLORERS[key] ?? null,
        explorerTxFallbacks: FALLBACK_EXPLORER_FALLBACKS[key] ?? [],
        nativeCurrency: fallback.nativeCurrency ?? null,
        depositAddress: null,
    };
}

export function bridgeDepositAddress(chainKey: BridgeChain): string | null {
    return chainMap?.[chainKey]?.depositAddress ?? null;
}

export function explorerTxUrl(chainKey: BridgeChain, hash: string): string {
    const template =
        chainMap?.[chainKey]?.explorerTx ??
        FALLBACK_EXPLORERS[chainKey] ??
        FALLBACK_EXPLORERS.cyberia;

    return template.replace('{hash}', hash);
}

export function explorerTxFallbackUrls(
    chainKey: BridgeChain,
    hash: string,
): string[] {
    const templates =
        chainMap?.[chainKey]?.explorerTxFallbacks ??
        FALLBACK_EXPLORER_FALLBACKS[chainKey] ??
        [];

    return templates.map((template) => template.replace('{hash}', hash));
}

/** Symbols offered on a route (server-computed availability). */
export function tokensForRoute(direction: BridgeDirection): string[] {
    const route = routeList?.find((entry) => entry.direction === direction);

    if (route) {
        return route.tokens;
    }

    // Pre-init fallback mirrors the legacy behaviour.
    if (direction === 'yenten_to_evm' || direction === 'evm_to_yenten') {
        return ['YTN'];
    }

    if (direction === 'btc_to_evm' || direction === 'evm_to_btc') {
        return ['BTC'];
    }

    if (direction === 'ltc_to_evm' || direction === 'evm_to_ltc') {
        return ['LTC'];
    }

    if (direction === 'xmr_to_evm' || direction === 'evm_to_xmr') {
        return ['XMR'];
    }

    return Object.keys(BRIDGE_TOKENS).filter(
        (symbol) => !['YTN', 'BTC', 'LTC', 'XMR'].includes(symbol),
    );
}

export function bridgeTokenInfo(symbol: string): PublicToken | null {
    return tokenMap?.[symbol] ?? null;
}

/**
 * Token identity on a chain: server config first, static BRIDGE_TOKENS
 * (cyberia/solana fields) as fallback.
 */
export function tokenOnChain(
    symbol: string,
    chainKey: BridgeChain,
): PublicTokenChain | null {
    const fromServer = tokenMap?.[symbol]?.chains[chainKey];

    if (fromServer) {
        return fromServer;
    }

    const legacy = tokenBySymbol(symbol);

    if (!legacy) {
        return null;
    }

    if (chainKey === 'cyberia') {
        return {
            address: legacy.evmAddress,
            mint: null,
            master: null,
            native: false,
            decimals: legacy.evmDecimals,
            tokenProgram: null,
        };
    }

    if (chainKey === 'solana' && legacy.solanaMint) {
        return {
            address: null,
            mint: legacy.solanaMint,
            master: null,
            native: false,
            decimals: legacy.solanaDecimals,
            tokenProgram: legacy.solanaTokenProgram,
        };
    }

    return null;
}
