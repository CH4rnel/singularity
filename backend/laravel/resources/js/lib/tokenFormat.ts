// USD formatting shared by the token pages. Mirrors the analytics dashboard's
// number rules so a token's price reads the same wherever it appears.

const dollars = (value: number, digits: number): string =>
    '$' + value.toLocaleString('en-US', { maximumFractionDigits: digits });

/**
 * A token price: whole dollars for big numbers, a couple of decimals in the
 * normal range, and enough significant figures for sub-cent tokens — never
 * scientific notation (e.g. 0.00009235, not 9.235e-5).
 */
export const formatUsdPrice = (value: number): string => {
    if (value >= 1000) {
        return dollars(value, 0);
    }

    if (value >= 0.01) {
        return dollars(value, 4);
    }

    if (value <= 0) {
        return dollars(value, 2);
    }

    const digits = Math.min(20, Math.ceil(-Math.log10(value)) + 3);

    return dollars(value, digits);
};

/** A USD total (TVL, liquidity): compact whole-dollar-ish formatting. */
export const formatUsd = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return '—';
    }

    const abs = Math.abs(value);
    const digits = abs >= 1000 ? 0 : abs >= 1 ? 2 : 6;

    return dollars(value, digits);
};

/** 0x1234…abcd */
export const shortAddress = (addr: string): string =>
    addr.length > 12 ? `${addr.slice(0, 6)}…${addr.slice(-4)}` : addr;
