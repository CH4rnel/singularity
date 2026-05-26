import { useQuery } from '@tanstack/react-query';
import { ChainId } from '@uniswap/sdk';
import { Contract } from 'ethers';
import { formatUnits } from 'ethers/lib/utils';
import { V2_FACTORY_ADDRESSES } from 'constants/v3/addresses';
import { RPC_PROVIDERS } from 'constants/providers';
import { RITUAL_USD_ANCHORS } from 'constants/ritualFarms';
import ERC20_ABI from 'constants/abis/erc20.json';

/**
 * On-chain DEX statistics for Cyberia.
 *
 * Cyberia has no analytics backend (`leaderboard.available: false`), so the
 * usual `useAnalyticsGlobalData` path returns nothing and the landing-page
 * stats render $0. Volume/fees are historical and genuinely need an indexer,
 * but TVL and pair/token counts are derivable directly from the V2 factory:
 * enumerate every pair, read its reserves, and value them in USD by
 * propagating prices out from the stablecoin anchors across the pair graph
 * (same technique the farm page uses).
 */

const FACTORY_ABI = [
  'function allPairsLength() view returns (uint256)',
  'function allPairs(uint256) view returns (address)',
];

const PAIR_ABI = [
  'function token0() view returns (address)',
  'function token1() view returns (address)',
  'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)',
];

// Defensive upper bound on how many pairs we enumerate in one pass.
const MAX_PAIRS = 1000;

export interface CyberiaDexStats {
  tvlUSD: number;
  pairCount: number;
  tokenCount: number;
}

export function useCyberiaDexStats(chainId?: ChainId) {
  const factory = chainId ? V2_FACTORY_ADDRESSES[chainId] : undefined;
  const rpc = chainId ? RPC_PROVIDERS[chainId] : undefined;

  const { isLoading, data } = useQuery<CyberiaDexStats | null>({
    queryKey: ['cyberiaDexStats', chainId],
    enabled: !!factory && !!rpc,
    refetchInterval: 300000,
    queryFn: async () => {
      if (!factory || !rpc) return null;

      const factoryContract = new Contract(factory, FACTORY_ABI, rpc);
      const length: number = (await factoryContract.allPairsLength()).toNumber();
      if (length === 0) return { tvlUSD: 0, pairCount: 0, tokenCount: 0 };

      const count = Math.min(length, MAX_PAIRS);
      const pairAddrs: string[] = await Promise.all(
        Array.from({ length: count }, (_, i) => factoryContract.allPairs(i)),
      );

      const pairs = await Promise.all(
        pairAddrs.map(async (addr) => {
          const c = new Contract(addr, PAIR_ABI, rpc);
          const [t0, t1, reserves] = await Promise.all([
            c.token0(),
            c.token1(),
            c.getReserves(),
          ]);
          return {
            token0: String(t0).toLowerCase(),
            token1: String(t1).toLowerCase(),
            reserve0: reserves[0],
            reserve1: reserves[1],
          };
        }),
      );

      // Unique token set → decimals (one read per distinct token).
      const tokenSet = new Set<string>();
      pairs.forEach((p) => {
        tokenSet.add(p.token0);
        tokenSet.add(p.token1);
      });
      const tokens = Array.from(tokenSet);
      const decimalsArr = await Promise.all(
        tokens.map((addr) =>
          new Contract(addr, ERC20_ABI, rpc).decimals().catch(() => 18),
        ),
      );
      const decimals: Record<string, number> = {};
      tokens.forEach((addr, i) => {
        decimals[addr] = decimalsArr[i];
      });

      const reservesWhole = pairs.map((p) => ({
        token0: p.token0,
        token1: p.token1,
        r0: Number(formatUnits(p.reserve0, decimals[p.token0] ?? 18)),
        r1: Number(formatUnits(p.reserve1, decimals[p.token1] ?? 18)),
      }));

      // Propagate USD prices out from the stablecoin anchors.
      const prices: Record<string, number> = { ...RITUAL_USD_ANCHORS };
      for (let pass = 0; pass < 8; pass++) {
        let changed = false;
        for (const p of reservesWhole) {
          if (p.r0 <= 0 || p.r1 <= 0) continue;
          const p0 = prices[p.token0];
          const p1 = prices[p.token1];
          if (p0 != null && p1 == null) {
            prices[p.token1] = (p.r0 * p0) / p.r1;
            changed = true;
          } else if (p1 != null && p0 == null) {
            prices[p.token0] = (p.r1 * p1) / p.r0;
            changed = true;
          }
        }
        if (!changed) break;
      }

      // Sum each pair's USD value. If only one side can be priced, double it
      // (a constant-product pool holds equal value on each side).
      let tvlUSD = 0;
      for (const p of reservesWhole) {
        const p0 = prices[p.token0];
        const p1 = prices[p.token1];
        if (p0 != null && p1 != null) tvlUSD += p.r0 * p0 + p.r1 * p1;
        else if (p0 != null) tvlUSD += 2 * p.r0 * p0;
        else if (p1 != null) tvlUSD += 2 * p.r1 * p1;
      }

      return { tvlUSD, pairCount: length, tokenCount: tokens.length };
    },
  });

  return { isLoading, data };
}
