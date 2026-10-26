import type { UTCTimestamp } from 'lightweight-charts';
import type { MarketCandle } from '@/lib/marketCandles';

/** Public candle feeds only: no account, API key, or wallet address is sent. */
export type ExchangeListing = {
    venue: 'Binance' | 'Kraken';
    pair: string;
    quote: 'USD' | 'USDT';
};

const LISTINGS: Record<string, ExchangeListing> = {
    'BINANCE:BTCUSDT': { venue: 'Binance', pair: 'BTCUSDT', quote: 'USDT' },
    'BINANCE:ETHUSDT': { venue: 'Binance', pair: 'ETHUSDT', quote: 'USDT' },
    'BINANCE:SOLUSDT': { venue: 'Binance', pair: 'SOLUSDT', quote: 'USDT' },
    'BINANCE:BNBUSDT': { venue: 'Binance', pair: 'BNBUSDT', quote: 'USDT' },
    'BINANCE:LTCUSDT': { venue: 'Binance', pair: 'LTCUSDT', quote: 'USDT' },
    'KRAKEN:XMRUSD': { venue: 'Kraken', pair: 'XMRUSD', quote: 'USD' },
};

export const exchangeListing = (symbol?: string): ExchangeListing | null =>
    symbol && Object.hasOwn(LISTINGS, symbol) ? LISTINGS[symbol] : null;

/** A bounded year, not ALL: both public feeds cap their returned history. */
export const EXCHANGE_RANGES = [
    { key: '1D', label: '24H', seconds: 86400, interval: 15, binance: '15m' },
    { key: '7D', label: '7D', seconds: 604800, interval: 60, binance: '1h' },
    {
        key: '30D',
        label: '30D',
        seconds: 2592000,
        interval: 240,
        binance: '4h',
    },
    {
        key: '1Y',
        label: '1Y',
        seconds: 31536000,
        interval: 1440,
        binance: '1d',
    },
] as const;

export type ExchangeRangeKey = (typeof EXCHANGE_RANGES)[number]['key'];

const numeric = (value: unknown): number =>
    typeof value === 'number' ||
    (typeof value === 'string' && value.trim() !== '')
        ? Number(value)
        : NaN;

/** Normalize provider formats and reject bars that cannot describe a trade. */
export const parseExchangeCandles = (
    payload: unknown,
    venue: ExchangeListing['venue'],
    fromSec: number,
    toSec: number,
): MarketCandle[] => {
    let rows: unknown = payload;

    if (venue === 'Kraken') {
        const body = payload as { error?: unknown; result?: unknown } | null;

        if (
            !body ||
            !Array.isArray(body.error) ||
            body.error.length > 0 ||
            !body.result ||
            typeof body.result !== 'object'
        ) {
            throw new Error('Kraken candles unavailable');
        }

        // Kraken returns its canonical pair name (XXMRZUSD), not our alias.
        rows = Object.values(body.result).find(Array.isArray);
    }

    if (!Array.isArray(rows)) {
        throw new Error('Invalid exchange candles');
    }

    const candles = new Map<number, MarketCandle>();

    for (const row of rows) {
        if (!Array.isArray(row)) {
            continue;
        }

        const time = numeric(row[0]) / (venue === 'Binance' ? 1000 : 1);
        const [open, high, low, close] = row.slice(1, 5).map(numeric);
        const volume = numeric(row[venue === 'Binance' ? 5 : 6]);
        const trades = numeric(row[venue === 'Binance' ? 8 : 7]);

        if (
            !Number.isSafeInteger(time) ||
            time <= 0 ||
            time < fromSec ||
            time > toSec ||
            ![open, high, low, close].every(
                (price) => Number.isFinite(price) && price > 0,
            ) ||
            high < Math.max(open, close, low) ||
            low > Math.min(open, close) ||
            !Number.isFinite(volume) ||
            volume < 0 ||
            !Number.isSafeInteger(trades) ||
            trades < 0
        ) {
            continue;
        }

        candles.set(time, {
            time: time as UTCTimestamp,
            open,
            high,
            low,
            close,
            volume,
            trades,
        });
    }

    return [...candles.values()].sort((a, b) => a.time - b.time);
};

export const loadExchangeCandles = async (
    symbol: string,
    rangeKey: ExchangeRangeKey,
    signal: AbortSignal,
): Promise<MarketCandle[]> => {
    const listing = exchangeListing(symbol);
    const range = EXCHANGE_RANGES.find((entry) => entry.key === rangeKey);

    if (!listing || !range) {
        throw new Error('Unsupported exchange market');
    }

    const toSec = Math.floor(Date.now() / 1000);
    const bucketSec = range.interval * 60;
    const fromSec = Math.floor((toSec - range.seconds) / bucketSec) * bucketSec;
    const url = new URL(
        listing.venue === 'Binance'
            ? 'https://data-api.binance.vision/api/v3/klines'
            : 'https://api.kraken.com/0/public/OHLC',
    );

    if (listing.venue === 'Binance') {
        url.search = new URLSearchParams({
            symbol: listing.pair,
            interval: range.binance,
            startTime: String(fromSec * 1000),
            limit: '720',
        }).toString();
    } else {
        url.search = new URLSearchParams({
            pair: listing.pair,
            interval: String(range.interval),
            since: String(fromSec),
        }).toString();
    }

    const response = await fetch(url, {
        signal: AbortSignal.any([signal, AbortSignal.timeout(15000)]),
        credentials: 'omit',
        referrerPolicy: 'no-referrer',
    });

    if (!response.ok) {
        throw new Error('Exchange candles unavailable');
    }

    const candles = parseExchangeCandles(
        await response.json(),
        listing.venue,
        fromSec,
        toSec,
    );

    if (candles.length === 0) {
        throw new Error('No exchange candles');
    }

    return candles;
};
