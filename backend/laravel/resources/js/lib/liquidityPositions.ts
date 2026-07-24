export type OwnedLiquidityPair = {
    pairAddress: string;
    lpBalance: bigint;
};

export type LiquidityBalanceScan = {
    ownedPairs: OwnedLiquidityPair[];
    failedReads: number;
};

export const scanLiquidityBalances = async (
    pairAddresses: string[],
    readBalance: (pairAddress: string) => Promise<bigint>,
): Promise<LiquidityBalanceScan> => {
    const results = await Promise.allSettled(
        pairAddresses.map(async (pairAddress) => ({
            pairAddress,
            lpBalance: await readBalance(pairAddress),
        })),
    );

    const ownedPairs: OwnedLiquidityPair[] = [];
    let failedReads = 0;

    for (const result of results) {
        if (result.status === 'rejected') {
            failedReads++;

            continue;
        }

        if (result.value.lpBalance > 0n) {
            ownedPairs.push(result.value);
        }
    }

    return { ownedPairs, failedReads };
};
