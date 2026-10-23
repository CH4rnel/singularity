import { fromRawUnits, toRawUnits } from '@/lib/bridgeCapacity';
import {
    bridgeConversionRate,
    bridgeRouteTokenDecimals,
} from '@/lib/bridgeConfig';
import type { BridgeConversionRate } from '@/lib/bridgeConfig';
import type { BridgeTokenSymbol } from '@/lib/bridgeTokens';

export type BridgeFeeConfig = {
    flatUsd: number;
    rateBps: number;
    nativeRouteFees?: Record<string, Record<string, number>>;
};

export type TokenPrices = {
    cyberSolUsd: number | null;
};

export type FeeResult = {
    feeUsd: number;
    feeToken: number;
    tokenPriceUsd: number;
};

export const DEFAULT_FEE: BridgeFeeConfig = {
    flatUsd: 0.1,
    rateBps: 0,
    nativeRouteFees: {},
};

export const priceUsd = (
    token: BridgeTokenSymbol,
    prices: TokenPrices,
): number => {
    if (
        token === 'USDC' ||
        token === 'USDT' ||
        token === 'USDG' ||
        token === 'JupUSD'
    ) {
        return 1;
    }

    if (token === 'CYBER.sol') {
        return prices.cyberSolUsd ?? 0;
    }

    return 0;
};

export const isFeeBearing = (
    token: BridgeTokenSymbol,
    direction?: string,
    config: BridgeFeeConfig = DEFAULT_FEE,
): boolean =>
    token === 'USDC' ||
    token === 'USDT' ||
    token === 'USDG' ||
    token === 'JupUSD' ||
    (direction !== undefined &&
        (config.nativeRouteFees?.[direction]?.[token] ?? 0) > 0);

export const computeFee = (
    token: BridgeTokenSymbol,
    amount: string,
    prices: TokenPrices,
    config: BridgeFeeConfig = DEFAULT_FEE,
    direction?: string,
): FeeResult => {
    const amt = parseFloat(amount);
    const price = priceUsd(token, prices);
    const nativeFee =
        direction !== undefined
            ? (config.nativeRouteFees?.[direction]?.[token] ?? 0)
            : 0;

    if (!isFeeBearing(token, direction, config)) {
        return { feeUsd: 0, feeToken: 0, tokenPriceUsd: price };
    }

    if (nativeFee > 0) {
        return { feeUsd: 0, feeToken: nativeFee, tokenPriceUsd: price };
    }

    if (!Number.isFinite(amt) || amt <= 0 || price <= 0) {
        return { feeUsd: 0, feeToken: 0, tokenPriceUsd: price };
    }

    const amountUsd = amt * price;
    const rateUsd = amountUsd * (config.rateBps / 10000);
    const feeUsd = Math.max(config.flatUsd, rateUsd);
    const feeToken = feeUsd / price;

    return { feeUsd, feeToken, tokenPriceUsd: price };
};

/** Convert the payout once, using the destination token's own precision. */
export const convertBridgeUnits = (
    amount: bigint,
    sourceDecimals: number,
    destinationDecimals: number,
    rate: BridgeConversionRate | null = { numerator: '1', denominator: '1' },
): bigint | null => {
    if (
        amount < 0n ||
        !Number.isInteger(sourceDecimals) ||
        !Number.isInteger(destinationDecimals) ||
        sourceDecimals < 0 ||
        sourceDecimals > 255 ||
        destinationDecimals < 0 ||
        destinationDecimals > 255 ||
        rate === null ||
        typeof rate.numerator !== 'string' ||
        typeof rate.denominator !== 'string' ||
        !/^\d+$/.test(rate.numerator) ||
        !/^\d+$/.test(rate.denominator)
    ) {
        return null;
    }

    const numerator = BigInt(rate.numerator);
    const denominator = BigInt(rate.denominator);

    if (numerator <= 0n || denominator <= 0n) {
        return null;
    }

    return (
        (amount * numerator * 10n ** BigInt(destinationDecimals)) /
        (denominator * 10n ** BigInt(sourceDecimals))
    );
};

/** Fees are source tokens; only the remaining source amount is converted. */
export const bridgePayoutAmount = (
    direction: string,
    symbol: string,
    amount: string,
    sourceFee = '0',
): string | null => {
    const decimals = bridgeRouteTokenDecimals(direction, symbol);
    const source = toRawUnits(amount, decimals.source);
    const fee = toRawUnits(sourceFee, decimals.source);

    if (source === null || fee === null) {
        return null;
    }

    const payout = convertBridgeUnits(
        source > fee ? source - fee : 0n,
        decimals.source,
        decimals.destination,
        bridgeConversionRate(direction, symbol),
    );

    return payout === null ? null : fromRawUnits(payout, decimals.destination);
};

/** A conservative source-unit ceiling for a destination-unit capacity. */
export const bridgeSourceAmount = (
    direction: string,
    symbol: string,
    destinationAmount: string,
): string | null => {
    const decimals = bridgeRouteTokenDecimals(direction, symbol);
    const destination = toRawUnits(destinationAmount, decimals.destination);
    const rate = bridgeConversionRate(direction, symbol);

    if (destination === null || rate === null) {
        return null;
    }

    const source = convertBridgeUnits(
        destination,
        decimals.destination,
        decimals.source,
        {
            numerator: rate.denominator,
            denominator: rate.numerator,
        },
    );

    return source === null ? null : fromRawUnits(source, decimals.source);
};

/** Preserve a numeric fee's decimal spelling, including very small fees. */
export const feeTokenAmount = (fee: number): string => {
    if (!Number.isFinite(fee) || fee < 0) {
        return '';
    }

    const [mantissa, exponent] = String(fee).toLowerCase().split('e');

    if (exponent === undefined) {
        return mantissa;
    }

    const [whole, fraction = ''] = mantissa.split('.');
    const digits = whole + fraction;
    const point = whole.length + Number(exponent);

    if (point <= 0) {
        return `0.${'0'.repeat(-point)}${digits}`;
    }

    return point >= digits.length
        ? digits + '0'.repeat(point - digits.length)
        : `${digits.slice(0, point)}.${digits.slice(point)}`;
};
