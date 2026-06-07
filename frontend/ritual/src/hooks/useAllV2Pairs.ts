import { ChainId, Pair, Token, TokenAmount } from '@uniswap/sdk';
import { Interface } from '@ethersproject/abi';
import { useMemo } from 'react';
import iUniswapV2Pair from '@uniswap/v2-core/build/IUniswapV2Pair.json';
import IUniswapV2Factory from '@uniswap/v2-core/build/IUniswapV2Factory.json';
import ERC20_INTERFACE from 'constants/abis/erc20';
import { V2_FACTORY_ADDRESSES } from 'constants/v3/addresses';
import {
  NEVER_RELOAD,
  useMultipleContractSingleData,
  useSingleCallResult,
  useSingleContractMultipleData,
} from 'state/multicall/hooks';
import { useActiveWeb3React } from './index';
import { useContract } from './useContract';
import { useAllTokens } from './Tokens';

const PAIR_INTERFACE = new Interface(iUniswapV2Pair.abi);

// Chains small enough to enumerate every V2 pair straight from the factory on
// the client, so swaps route through *all* on-chain liquidity instead of a
// hand-curated set of base tokens. Cyberia currently has only a few dozen
// pairs, so this is a handful of (mostly NEVER_RELOAD) multicall reads.
export const FULL_PAIR_ENUMERATION_CHAINS = new Set<number>([ChainId.CYBERIA]);

export function isFullPairEnumerationChain(chainId?: number): boolean {
  return !!chainId && FULL_PAIR_ENUMERATION_CHAINS.has(chainId);
}

/**
 * Loads every Pair registered in the V2 factory for chains in
 * {@link FULL_PAIR_ENUMERATION_CHAINS}. Pair/token metadata (addresses,
 * decimals) is immutable so it is read with NEVER_RELOAD; only reserves refresh
 * each block. Returns an empty list on chains that are too large to enumerate.
 */
export function useAllV2Pairs(): { pairs: Pair[]; loading: boolean } {
  const { chainId } = useActiveWeb3React();
  const enabled = isFullPairEnumerationChain(chainId);
  // Prefer listed tokens (symbol + logo) for route display; fall back to
  // on-chain symbol/name for tokens that are not on any list.
  const allTokens = useAllTokens();

  const factory = useContract(
    enabled ? V2_FACTORY_ADDRESSES[chainId as number] : undefined,
    IUniswapV2Factory.abi,
    false,
  );

  // 1. total number of pairs in the factory
  const lengthResult = useSingleCallResult(
    factory,
    'allPairsLength',
    undefined,
    NEVER_RELOAD,
  );
  const pairCount: number = lengthResult?.result
    ? Number(lengthResult.result[0])
    : 0;

  // 2. every pair address via allPairs(0..n-1)
  const indexInputs = useMemo(
    () => Array.from({ length: pairCount }, (_, i) => [i]),
    [pairCount],
  );
  const allPairsResults = useSingleContractMultipleData(
    factory,
    'allPairs',
    indexInputs,
    NEVER_RELOAD,
  );
  const pairAddresses = useMemo(
    () =>
      allPairsResults
        .map((r) => (r?.result ? (r.result[0] as string) : undefined))
        .filter((a): a is string => !!a),
    [allPairsResults],
  );

  // 3. token0 / token1 (immutable) and reserves (per-block) for every pair
  const token0Results = useMultipleContractSingleData(
    pairAddresses,
    PAIR_INTERFACE,
    'token0',
    undefined,
    NEVER_RELOAD,
  );
  const token1Results = useMultipleContractSingleData(
    pairAddresses,
    PAIR_INTERFACE,
    'token1',
    undefined,
    NEVER_RELOAD,
  );
  const reservesResults = useMultipleContractSingleData(
    pairAddresses,
    PAIR_INTERFACE,
    'getReserves',
  );

  // 4. decimals (+ symbol/name as a fallback) for every unique token
  const tokenAddresses = useMemo(() => {
    const set = new Set<string>();
    token0Results.forEach((r) => r?.result && set.add(r.result[0]));
    token1Results.forEach((r) => r?.result && set.add(r.result[0]));
    return Array.from(set);
  }, [token0Results, token1Results]);

  const decimalsResults = useMultipleContractSingleData(
    tokenAddresses,
    ERC20_INTERFACE,
    'decimals',
    undefined,
    NEVER_RELOAD,
  );
  const symbolResults = useMultipleContractSingleData(
    tokenAddresses,
    ERC20_INTERFACE,
    'symbol',
    undefined,
    NEVER_RELOAD,
  );
  const nameResults = useMultipleContractSingleData(
    tokenAddresses,
    ERC20_INTERFACE,
    'name',
    undefined,
    NEVER_RELOAD,
  );

  // Resolve each token to a full Token (with symbol/name), preferring the
  // app's token lists so the route display shows proper tickers.
  const tokenByAddress = useMemo(() => {
    const map: { [address: string]: Token } = {};
    if (!chainId) return map;
    tokenAddresses.forEach((addr, i) => {
      const listed = allTokens[addr];
      if (listed) {
        map[addr] = listed;
        return;
      }
      const decimalsResult = decimalsResults[i]?.result;
      if (!decimalsResult) return;
      try {
        map[addr] = new Token(
          chainId,
          addr,
          Number(decimalsResult[0]),
          symbolResults[i]?.result?.[0],
          nameResults[i]?.result?.[0],
        );
      } catch {
        // skip tokens with malformed metadata
      }
    });
    return map;
  }, [
    chainId,
    tokenAddresses,
    allTokens,
    decimalsResults,
    symbolResults,
    nameResults,
  ]);

  return useMemo(() => {
    if (!enabled || !chainId) return { pairs: [], loading: false };

    // Symbol/name reads are cosmetic, so they are intentionally left out of the
    // loading flag — the trade should not block on them.
    const loading =
      lengthResult.loading ||
      allPairsResults.some((r) => r.loading) ||
      token0Results.some((r) => r.loading) ||
      token1Results.some((r) => r.loading) ||
      reservesResults.some((r) => r.loading) ||
      decimalsResults.some((r) => r.loading);

    const pairs: Pair[] = [];
    for (let i = 0; i < pairAddresses.length; i++) {
      const t0 = token0Results[i]?.result?.[0] as string | undefined;
      const t1 = token1Results[i]?.result?.[0] as string | undefined;
      const reserves = reservesResults[i]?.result;
      if (!t0 || !t1 || !reserves) continue;
      const token0 = tokenByAddress[t0];
      const token1 = tokenByAddress[t1];
      if (!token0 || !token1) continue;
      try {
        pairs.push(
          new Pair(
            new TokenAmount(token0, reserves.reserve0.toString()),
            new TokenAmount(token1, reserves.reserve1.toString()),
          ),
        );
      } catch {
        // skip pairs with malformed token data
      }
    }
    return { pairs, loading };
  }, [
    enabled,
    chainId,
    lengthResult,
    allPairsResults,
    token0Results,
    token1Results,
    reservesResults,
    decimalsResults,
    pairAddresses,
    tokenByAddress,
  ]);
}
