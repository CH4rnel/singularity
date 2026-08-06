import { formatUnits } from '@/lib/wallet/chains';

/**
 * Presentation helpers for the wallet page.
 *
 * Amounts are formatted from bigints and never from floats: a balance that
 * round-trips through a double is a balance that can be off by a wei, and the
 * send screen subtracts fees from exactly these numbers.
 */

/** Head and tail preserved — the two ends people actually check. */
export const shortAddress = (address: string, head = 6, tail = 4): string =>
    address.length > head + tail + 2
        ? `${address.slice(0, head)}…${address.slice(-tail)}`
        : address;

/** A signed, fixed-precision amount, e.g. "−120.0000". */
export const signedAmount = (
    value: bigint,
    decimals: number,
    precision = 4,
): string => {
    const magnitude = formatUnits(value < 0n ? -value : value, decimals, 12);
    const [whole, fraction = ''] = magnitude.split('.');

    return `${value < 0n ? '−' : '+'}${whole}.${fraction.padEnd(precision, '0').slice(0, precision)}`;
};

const RELATIVE_STEPS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

/** "2h ago" / "2 ч назад", from a unix-seconds timestamp. */
export const relativeTime = (
    timestamp: number | null,
    locale: string,
): string => {
    if (timestamp === null) {
        return '';
    }

    const elapsed = Math.round(Date.now() / 1000) - timestamp;
    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, seconds] of RELATIVE_STEPS) {
        if (Math.abs(elapsed) >= seconds) {
            return formatter.format(-Math.round(elapsed / seconds), unit);
        }
    }

    return formatter.format(-elapsed, 'second');
};

/**
 * A USD figure, or null when no price was available. Callers render null as a
 * dash rather than as zero — an unknown price is not a worthless balance.
 */
export const usdValue = (
    value: bigint | null,
    decimals: number,
    price: number | null,
): number | null => {
    if (value === null || price === null) {
        return null;
    }

    return Number(formatUnits(value, decimals, 12)) * price;
};

export const formatUsd = (value: number | null, locale: string): string =>
    value === null
        ? '—'
        : new Intl.NumberFormat(locale, {
              style: 'currency',
              currency: 'USD',
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          }).format(value);
