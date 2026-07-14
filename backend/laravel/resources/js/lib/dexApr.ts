/**
 * Per-pool LP APR snapshot computed server-side from 24h of on-chain swap
 * volume (App\Services\DexAprService, cached by the scheduled dex:apr
 * command). Served as the `apr` Inertia prop on /swap + /liquidity and as
 * public JSON at /api/dex/apr for the landing.
 */
export type PoolApr = {
    pair_address: string;
    symbol0: string;
    symbol1: string;
    tvl_usd: number;
    volume_24h_usd: number;
    fees_24h_usd: number;
    apr: number | null;
};

export type FarmApr = {
    pid: number;
    label: string;
    staked_usd: number | null;
    reward_share: number;
    apy: number | null;
};

export type AprSnapshot = {
    updated_at: string | null;
    window_hours: number;
    pools: PoolApr[];
    farms?: FarmApr[];
};

export function aprByPair(
    snapshot: AprSnapshot | null | undefined,
): Map<string, PoolApr> {
    return new Map(
        (snapshot?.pools ?? []).map((pool) => [
            pool.pair_address.toLowerCase(),
            pool,
        ]),
    );
}

export function formatApr(apr: number | null | undefined): string {
    if (apr === null || apr === undefined) {
        return '—';
    }

    return apr >= 1000
        ? `${Math.round(apr).toLocaleString('en-US')}%`
        : `${apr.toFixed(2)}%`;
}
