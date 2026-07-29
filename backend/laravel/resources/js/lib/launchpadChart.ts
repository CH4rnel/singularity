import type { UTCTimestamp } from 'lightweight-charts';

// One candle per block of trades on a launchpad pair. Prices are quoted in
// the pair's quote asset (CYBER); volume is the CYBER side of the swaps.
export type TokenCandle = {
    time: UTCTimestamp;
    block: number;
    open: number;
    high: number;
    low: number;
    close: number;
    volumeCyber: number;
    trades: number;
};
