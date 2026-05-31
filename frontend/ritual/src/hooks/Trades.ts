import { Currency, CurrencyAmount, Pair, Token, Trade } from '@uniswap/sdk';
import {
  V2_BASES_TO_CHECK_TRADES_AGAINST,
  V2_CUSTOM_BASES,
} from 'constants/v3/addresses';
import flatMap from 'lodash.flatmap';
import { useEffect, useMemo } from 'react';
import { SwapDelay } from 'state/swap/actions';
import { PairState, usePairs } from '../data/Reserves';
import { wrappedCurrency } from '../utils/wrappedCurrency';

import { useActiveWeb3React } from './index';

interface V2TradeResult {
  trade: Trade | null;
  loading: boolean;
}

export function useAllCommonPairs(
  currencyA?: Currency,
  currencyB?: Currency,
): Pair[] {
  return useAllCommonPairsState(currencyA, currencyB).pairs;
}

function useAllCommonPairsState(
  currencyA?: Currency,
  currencyB?: Currency,
): { pairs: Pair[]; loading: boolean } {
  const { chainId } = useActiveWeb3React();

  const bases: Token[] = useMemo(
    () => (chainId ? V2_BASES_TO_CHECK_TRADES_AGAINST[chainId] : []),
    [chainId],
  );

  const [tokenA, tokenB] = chainId
    ? [wrappedCurrency(currencyA, chainId), wrappedCurrency(currencyB, chainId)]
    : [undefined, undefined];

  const basePairs: [Token, Token][] = useMemo(
    () =>
      flatMap(bases, (base): [Token, Token][] =>
        bases.map((otherBase) => [base, otherBase]),
      ).filter(([t0, t1]) => t0.address !== t1.address),
    [bases],
  );

  const allPairCombinations: [Token, Token][] = useMemo(
    () =>
      tokenA && tokenB
        ? [
            // the direct pair
            [tokenA, tokenB],
            // token A against all bases
            ...bases.map((base): [Token, Token] => [tokenA, base]),
            // token B against all bases
            ...bases.map((base): [Token, Token] => [tokenB, base]),
            // each base against all bases
            ...basePairs,
          ]
            .filter((tokens): tokens is [Token, Token] =>
              Boolean(tokens[0] && tokens[1]),
            )
            .filter(([t0, t1]) => t0.address !== t1.address)
            .filter(([tokenA, tokenB]) => {
              if (!chainId) return true;
              const customBases = V2_CUSTOM_BASES[chainId];
              if (!customBases) return true;

              const customBasesA: Token[] | undefined =
                customBases[tokenA.address];
              const customBasesB: Token[] | undefined =
                customBases[tokenB.address];

              if (!customBasesA && !customBasesB) return true;

              if (
                customBasesA &&
                !(customBasesA as Token[]).find((base) => tokenB.equals(base))
              )
                return false;
              if (
                customBasesB &&
                !(customBasesB as Token[]).find((base) => tokenA.equals(base))
              )
                return false;

              return true;
            })
        : [],
    [tokenA, tokenB, bases, basePairs, chainId],
  );

  const allPairs = usePairs(allPairCombinations);

  return useMemo(() => {
    const loading = allPairs.some(([state]) => state === PairState.LOADING);
    const pairs = Object.values(
      allPairs
        // filter out invalid pairs
        .filter((result): result is [PairState.EXISTS, Pair] =>
          Boolean(result[0] === PairState.EXISTS && result[1]),
        )
        // filter out duplicated pairs
        .reduce<{ [pairAddress: string]: Pair }>((memo, [, curr]) => {
          memo[curr.liquidityToken.address] =
            memo[curr.liquidityToken.address] ?? curr;
          return memo;
        }, {}),
    );

    return { pairs, loading };
  }, [allPairs]);
}

/**
 * Returns the best trade for the exact amount of tokens in to the given token out
 */

let bestTradeExactIn: Trade | null = null;
export function useTradeExactIn(
  currencyAmountIn?: CurrencyAmount,
  currencyOut?: Currency,
  swapDelay?: SwapDelay,
  onSetSwapDelay?: (swapDelay: SwapDelay) => void,
): V2TradeResult {
  const { pairs: allowedPairs, loading } = useAllCommonPairsState(
    currencyAmountIn?.currency,
    currencyOut,
  );
  bestTradeExactIn = useMemo(() => {
    if (!currencyAmountIn) {
      return null;
    }
    if (
      swapDelay !== SwapDelay.USER_INPUT_COMPLETE &&
      swapDelay !== SwapDelay.SWAP_REFRESH
    ) {
      return bestTradeExactIn;
    }
    if (currencyAmountIn && currencyOut && allowedPairs.length > 0) {
      const trade =
        Trade.bestTradeExactIn(allowedPairs, currencyAmountIn, currencyOut, {
          maxHops: 3,
          maxNumResults: 1,
        })[0] ?? null;
      return trade;
    }
    return null;
  }, [allowedPairs, currencyAmountIn, currencyOut, swapDelay]);

  useEffect(() => {
    if (
      swapDelay === SwapDelay.USER_INPUT_COMPLETE &&
      onSetSwapDelay &&
      currencyAmountIn &&
      currencyOut &&
      allowedPairs.length > 0
    ) {
      onSetSwapDelay(SwapDelay.SWAP_COMPLETE);
    }
  }, [
    allowedPairs.length,
    currencyAmountIn,
    currencyOut,
    onSetSwapDelay,
    swapDelay,
  ]);

  return {
    trade: bestTradeExactIn,
    loading: Boolean(currencyAmountIn && currencyOut && loading),
  };
}

/**
 * Returns the best trade for the token in to the exact amount of token out
 */
let bestTradeExactOut: Trade | null = null;
export function useTradeExactOut(
  currencyIn?: Currency,
  currencyAmountOut?: CurrencyAmount,
  swapDelay?: SwapDelay,
  onSetSwapDelay?: (swapDelay: SwapDelay) => void,
): V2TradeResult {
  const { pairs: allowedPairs, loading } = useAllCommonPairsState(
    currencyIn,
    currencyAmountOut?.currency,
  );

  bestTradeExactOut = useMemo(() => {
    if (!currencyAmountOut) return null;
    if (
      swapDelay !== SwapDelay.USER_INPUT_COMPLETE &&
      swapDelay !== SwapDelay.SWAP_REFRESH
    ) {
      return bestTradeExactOut;
    }
    if (currencyIn && currencyAmountOut && allowedPairs.length > 0) {
      const trade =
        Trade.bestTradeExactOut(allowedPairs, currencyIn, currencyAmountOut, {
          maxHops: 3,
          maxNumResults: 1,
        })[0] ?? null;
      return trade;
    }
    return null;
  }, [allowedPairs, currencyIn, currencyAmountOut, swapDelay]);

  useEffect(() => {
    if (
      swapDelay === SwapDelay.USER_INPUT_COMPLETE &&
      onSetSwapDelay &&
      currencyIn &&
      currencyAmountOut &&
      allowedPairs.length > 0
    ) {
      onSetSwapDelay(SwapDelay.SWAP_COMPLETE);
    }
  }, [
    allowedPairs.length,
    currencyIn,
    currencyAmountOut,
    onSetSwapDelay,
    swapDelay,
  ]);

  return {
    trade: bestTradeExactOut,
    loading: Boolean(currencyIn && currencyAmountOut && loading),
  };
}
