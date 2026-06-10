import type { BridgeTokenSymbol } from '@/lib/bridgeTokens';

export type BridgeFeeConfig = {
    flatUsd: number;
    rateBps: number;
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
};

export const priceUsd = (
    token: BridgeTokenSymbol,
    prices: TokenPrices,
): number => {
    if (token === 'USDC' || token === 'USDT') {
        return 1;
    }

    if (token === 'CYBER.sol') {
        return prices.cyberSolUsd ?? 0;
    }

    return 0;
};

export const isFeeBearing = (token: BridgeTokenSymbol): boolean =>
    token === 'USDC' || token === 'USDT';

export const computeFee = (
    token: BridgeTokenSymbol,
    amount: string,
    prices: TokenPrices,
    config: BridgeFeeConfig = DEFAULT_FEE,
): FeeResult => {
    const amt = parseFloat(amount);
    const price = priceUsd(token, prices);

    if (!isFeeBearing(token)) {
        return { feeUsd: 0, feeToken: 0, tokenPriceUsd: price };
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
