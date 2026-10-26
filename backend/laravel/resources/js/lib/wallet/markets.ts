import { CYBERIA_CHAIN_ID } from '@/lib/evmChains';
import { walletChain, walletChains } from '@/lib/wallet/chains';
import type { WalletChain, WalletChainId } from '@/lib/wallet/chains';
import { exchangeListing } from '@/lib/wallet/exchangeCandles';

/**
 * Exchange OHLCV and routed pool history share a bundled chart renderer.
 * Their sources stay explicit: an exchange listing is never substituted for
 * this chain's own token, and an unreadable pool never becomes a flat line.
 */

export type MarketSource =
    /** Read from the named exchange and drawn with Lightweight Charts. */
    | 'exchange'
    /** Rebuilt here from the pools' own `Sync` events. */
    | 'onchain'
    /** Neither: nobody lists it and no pool graph here can price it. */
    | 'none';

export type Market = {
    /** Stable id, used as the route parameter and the list key. */
    id: string;
    /** What is being priced. */
    symbol: string;
    /** Human name of the asset. */
    label: string;
    /** What it is priced in — always USD or a dollar stablecoin. */
    quote: string;
    source: MarketSource;
    /** The network whose colour and tag the row wears. */
    chain: WalletChainId;
    /** Verified exchange listing, also used for the public candle feed. */
    tvSymbol?: string;
    /** ERC-20 contract, for the markets priced from this chain's pools. */
    address?: string;
    /** Why there is no chart, when there is none. */
    note?: 'noListing' | 'noPool' | 'receiveOnly';
};

/**
 * Exchange listings for the coins the wallet holds natively.
 *
 * Hand-written and deliberately short. Every entry was checked to exist on the
 * venue it names, because TradingView answers an unknown symbol with a blank
 * frame rather than a 404 — a wrong guess here is indistinguishable from a
 * broken chart. The venue is part of the answer: Monero is not on Binance (it
 * was delisted in 2024), so it is read from Kraken or not at all.
 */
const EXCHANGE_SYMBOLS: Partial<Record<WalletChainId, string>> = {
    bitcoin: 'BINANCE:BTCUSDT',
    litecoin: 'BINANCE:LTCUSDT',
    solana: 'BINANCE:SOLUSDT',
    bnb: 'BINANCE:BNBUSDT',
    monero: 'KRAKEN:XMRUSD',
    // Base and Robinhood are settled in ether, and ether is what a chart of
    // them would be. Both point at the same book on purpose.
    base: 'BINANCE:ETHUSDT',
    robinhood: 'BINANCE:ETHUSDT',
};

/**
 * The exchange symbol for a network's own coin, or null.
 *
 * Only the networks in the table above are answered, and that table is short
 * because each line was checked rather than because those chains are special.
 * Any other network may well have a listed coin; nothing here has verified
 * which, and a symbol assembled from a ticker (`BINANCE:` + symbol + `USDT`) is
 * exactly the guess that renders blank.
 */
export const exchangeSymbol = (chain: WalletChainId): string | null =>
    EXCHANGE_SYMBOLS[chain] ?? null;

/** Whether this network's own coin can be charted, and how. */
const coinSource = (chain: WalletChain): MarketSource => {
    if (exchangeSymbol(chain.id)) {
        return 'exchange';
    }

    // Cyberia's coin has no listing anywhere; its price exists as this chain's
    // own pools and is charted from them.
    return chain.id === 'cyberia' ? 'onchain' : 'none';
};

/**
 * One row per network the wallet has switched on, priced in dollars.
 *
 * The list is the wallet's own networks and not a venue's catalogue: these are
 * the things the person opening this screen actually holds, and a market list
 * that opens on eight thousand pairs they own none of is a screen about
 * somebody else's portfolio.
 */
export const coinMarkets = (): Market[] =>
    walletChains().map((chain) => {
        const source = coinSource(chain);

        return {
            id: `coin:${chain.id}`,
            symbol: chain.symbol,
            label: chain.label,
            quote:
                exchangeListing(exchangeSymbol(chain.id) ?? undefined)?.quote ??
                'USD',
            source,
            chain: chain.id,
            tvSymbol: exchangeSymbol(chain.id) ?? undefined,
            note: source === 'none' ? 'noListing' : undefined,
        };
    });

/**
 * A market for one ERC-20 on Cyberia, priced against the dollar through the
 * DEX's own routing. Tokens on other chains are not offered: the routed history
 * is rebuilt from pools this wallet knows how to walk, and that graph is the
 * Cyberia fork's.
 */
export const tokenMarket = (token: {
    chain: WalletChainId;
    address: string;
    symbol: string;
    name?: string;
}): Market => {
    const onCyberia = token.chain === 'cyberia';

    return {
        id: `token:${token.chain}:${token.address.toLowerCase()}`,
        symbol: token.symbol,
        label: token.name?.trim() || token.symbol,
        quote: 'USD',
        source: onCyberia ? 'onchain' : 'none',
        chain: token.chain,
        address: token.address,
        note: onCyberia ? undefined : 'noPool',
    };
};

/** The EVM chain id a market's on-chain history is read from, when it has one. */
export const marketChainId = (market: Market): number | null => {
    if (market.source !== 'onchain') {
        return null;
    }

    try {
        return walletChain(market.chain).chainId ?? CYBERIA_CHAIN_ID;
    } catch {
        return CYBERIA_CHAIN_ID;
    }
};

/**
 * Case-insensitive search over what a person would actually type: the ticker,
 * the name, and a pasted contract address.
 */
export const searchMarkets = (
    markets: readonly Market[],
    query: string,
): Market[] => {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
        return [...markets];
    }

    return markets.filter(
        (market) =>
            market.symbol.toLowerCase().includes(needle) ||
            market.label.toLowerCase().includes(needle) ||
            (market.address ?? '').toLowerCase().includes(needle),
    );
};
