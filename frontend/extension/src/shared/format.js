/**
 * Numbers and addresses as the popup shows them.
 *
 * Kept away from ethers so the popup bundle stays small, and pure so the
 * rounding is pinned by tests rather than by whatever a screenshot looked like:
 * a balance that reads 0.0000 when it is not zero is a wallet that lies.
 */

/** `0x9c4A…F714` — enough of both ends to compare against a block explorer. */
export const shortAddress = (address, lead = 6, tail = 4) => {
    if (typeof address !== 'string' || address.length <= lead + tail + 1) {
        return address ?? '';
    }

    return `${address.slice(0, lead)}…${address.slice(-tail)}`;
};

/** A quantity from the chain — hex, decimal string, number or bigint. */
export const toBigInt = (value) => {
    if (typeof value === 'bigint') {
        return value;
    }

    if (typeof value === 'number') {
        return BigInt(Math.trunc(value));
    }

    if (typeof value === 'string' && value.trim() !== '') {
        return BigInt(value.trim());
    }

    return 0n;
};

/**
 * Base units to a decimal string, truncated rather than rounded up: showing
 * more than the account holds is the mistake that costs someone a transaction.
 */
export const formatUnits = (value, decimals = 18, precision = 4) => {
    const amount = toBigInt(value);
    const negative = amount < 0n;
    const scale = 10n ** BigInt(decimals);
    const absolute = negative ? -amount : amount;
    const whole = absolute / scale;
    const fraction = absolute % scale;

    const digits = fraction
        .toString()
        .padStart(decimals, '0')
        .slice(0, Math.max(0, precision))
        .replace(/0+$/, '');

    const body = `${whole.toLocaleString('en-US')}${digits ? `.${digits}` : ''}`;

    return negative ? `−${body}` : body;
};

/** Base units as a JS number, for multiplying by a price and nothing else. */
export const toDecimal = (value, decimals = 18) => {
    const amount = toBigInt(value);
    const scale = 10n ** BigInt(decimals);
    const whole = Number(amount / scale);
    const fraction = Number(amount % scale) / Number(scale);

    return whole + fraction;
};

/** A decimal the user typed, back to base units — no float in the middle. */
export const parseUnits = (input, decimals = 18) => {
    const text = String(input ?? '').trim();

    if (!/^\d*(\.\d*)?$/.test(text) || text === '' || text === '.') {
        return null;
    }

    const [whole, fraction = ''] = text.split('.');

    if (fraction.length > decimals) {
        return null;
    }

    return BigInt(`${whole || '0'}${fraction.padEnd(decimals, '0')}`);
};

/**
 * A USD amount, or an em dash. A price that could not be read is null and
 * stays null all the way to the screen — never zero, which reads as "worth
 * nothing" rather than as "not known".
 */
export const formatFiat = (amount) => {
    if (amount === null || amount === undefined || !Number.isFinite(amount)) {
        return '—';
    }

    return `$${amount.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
};

export const formatPercent = (value) => {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return '—';
    }

    return `${value > 0 ? '+' : ''}${value.toFixed(2)}%`;
};

/** Two letters for a token with no icon; the wallet never fetches a logo. */
export const initials = (symbol) => (symbol ?? '??').slice(0, 2).toUpperCase();
