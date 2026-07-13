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

export const formatNum = (n: number): string => {
    if (!isFinite(n)) {
        return '—';
    }

    if (n === 0) {
        return '0';
    }

    if (n >= 1_000_000_000) {
        return `${(n / 1_000_000_000).toFixed(2)}B`;
    }

    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toFixed(2)}M`;
    }

    if (n >= 1_000) {
        return `${(n / 1_000).toFixed(2)}K`;
    }

    return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
};

const SUBSCRIPT_DIGITS = ['₀', '₁', '₂', '₃', '₄', '₅', '₆', '₇', '₈', '₉'];

const subscriptNumber = (value: number): string =>
    String(value)
        .split('')
        .map((digit) => SUBSCRIPT_DIGITS[Number(digit)])
        .join('');

// Price display that survives launchpad-scale magnitudes without scientific
// notation: tiny prices collapse their zero run into a subscript count,
// e.g. 0.000000171 → 0.0₆171.
export const formatPrice = (n: number): string => {
    if (!isFinite(n) || n <= 0) {
        return '0';
    }

    if (n >= 1) {
        return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
    }

    // Normalize the exponent — log10 is imprecise at power-of-ten edges.
    let exp = Math.floor(Math.log10(n));

    if (n * 10 ** -exp >= 10) {
        exp += 1;
    } else if (n * 10 ** -exp < 1) {
        exp -= 1;
    }

    // Keep 4 significant digits.
    let mantissa = Math.round(n * 10 ** (-exp + 3));

    if (mantissa >= 10000) {
        mantissa = 1000;
        exp += 1;
    }

    if (exp >= 0) {
        return (mantissa / 1000).toLocaleString(undefined, {
            maximumFractionDigits: 4,
        });
    }

    const digits = String(mantissa).replace(/0+$/, '') || '0';
    const zeros = -exp - 1;

    if (zeros >= 5) {
        return `0.0${subscriptNumber(zeros)}${digits}`;
    }

    return `0.${'0'.repeat(zeros)}${digits}`;
};
