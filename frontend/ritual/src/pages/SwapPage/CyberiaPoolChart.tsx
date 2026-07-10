import { Box } from '@material-ui/core';
import { ChainId, WETH } from '@uniswap/sdk';
import { BigNumber, Contract, constants } from 'ethers';
import { formatUnits } from 'ethers/lib/utils';
import { USDC, USDT, V2_FACTORY_ADDRESSES } from 'constants/v3/addresses';
import { RPC_PROVIDERS } from 'constants/providers';
import { useActiveWeb3React } from 'hooks';
import { useAllTransactions } from 'state/transactions/hooks';
import React, { useEffect, useMemo, useRef, useState } from 'react';

const FACTORY_ABI = [
  'function getPair(address tokenA, address tokenB) view returns (address)',
];

const PAIR_ABI = [
  'function token0() view returns (address)',
  'function token1() view returns (address)',
  'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)',
  'event Swap(address indexed sender, uint amount0In, uint amount1In, uint amount0Out, uint amount1Out, address indexed to)',
];

const REFRESH_MS = 8000;
const HISTORY_DAYS = 7;
const SECONDS_PER_DAY = 86400;
const HISTORY_WINDOW_SECONDS = HISTORY_DAYS * SECONDS_PER_DAY;
const HISTORY_BLOCKS = HISTORY_WINDOW_SECONDS;
const REFRESH_OVERLAP_BLOCKS = 20;
const EXPLORER_LOGS_API = 'https://explorer.cyberia.church/api';
const EXPLORER_PAGE_SIZE = 1000;
const MAX_EXPLORER_PAGES = 5;

type ChartToken = {
  address: string;
  symbol?: string;
  decimals: number;
};

type ChartState =
  | { status: 'idle' | 'loading' | 'empty' | 'unsupported' }
  | {
      status: 'ready';
      pairAddress: string;
      reserveIn: number;
      reserveOut: number;
      spotPrice: number;
      tvlUsd?: number;
      dailyVolume: DailyVolume[];
      pricePoints: PricePoint[];
      updatedAt: number;
      lastBlock: number;
    };

function shortAddress(address: string): string {
  return `${address.slice(0, 6)}...${address.slice(-4)}`;
}

function formatNumber(value: number, maximumFractionDigits?: number): string {
  return value.toLocaleString(undefined, {
    maximumFractionDigits:
      maximumFractionDigits ?? (value >= 1000 ? 0 : value >= 1 ? 4 : 8),
  });
}

function formatUsd(value?: number): string {
  if (value === undefined || !Number.isFinite(value)) return 'n/a';

  return `$${value.toLocaleString(undefined, {
    maximumFractionDigits: value >= 1000 ? 0 : value >= 1 ? 2 : 4,
  })}`;
}

function formatDay(timestamp: number): string {
  return new Date(timestamp * 1000).toLocaleDateString(undefined, {
    month: '2-digit',
    day: '2-digit',
  });
}

function formatTimestamp(timestamp: number): string {
  return new Date(timestamp * 1000).toLocaleString(undefined, {
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

type PricePoint = {
  id: string;
  timestamp: number;
  blockNumber: number;
  price: number;
};

type DailyVolume = {
  day: string;
  timestamp: number;
  volumeUsd?: number;
  fallbackVolume: number;
};

type SwapPoint = PricePoint & {
  amountIn: number;
  amountOut: number;
  token1Amount: number;
  token2Amount: number;
  volumeUsd?: number;
};

type ParsedSwapLog = {
  id: string;
  blockNumber: number;
  timestamp: number;
  args: {
    amount0In: BigNumber;
    amount1In: BigNumber;
    amount0Out: BigNumber;
    amount1Out: BigNumber;
  };
};

type ReservesSnapshot = {
  token0: string;
  reserve0: BigNumber;
  reserve1: BigNumber;
};

function chartPolyline(points: PricePoint[]): string {
  if (points.length === 0) return '';

  const prices = points.map((point) => point.price);
  const min = Math.min(...prices);
  const max = Math.max(...prices);
  const span = max - min || 1;
  const minTime = points[0].timestamp;
  const maxTime = points[points.length - 1].timestamp;
  const timeSpan = maxTime - minTime || 1;

  return points
    .map((point) => {
      const x = ((point.timestamp - minTime) / timeSpan) * 100;
      const y = 88 - ((point.price - min) / span) * 76;

      return `${x.toFixed(2)},${y.toFixed(2)}`;
    })
    .join(' ');
}

function normalizeAddress(address?: string): string {
  return (address ?? '').toLowerCase();
}

function dayKey(timestamp: number): string {
  return new Date(timestamp * 1000).toISOString().slice(0, 10);
}

function isStable(address: string, chainId: ChainId): boolean {
  const lower = normalizeAddress(address);

  return (
    lower === normalizeAddress(USDC[chainId]?.address) ||
    lower === normalizeAddress(USDT[chainId]?.address)
  );
}

function getReserveForToken(
  reserves: ReservesSnapshot,
  tokenAddress: string,
  decimals: number,
): number {
  const isToken0 =
    normalizeAddress(reserves.token0) === normalizeAddress(tokenAddress);
  return Number(
    formatUnits(isToken0 ? reserves.reserve0 : reserves.reserve1, decimals),
  );
}

function derivePairPrices(
  token1: ChartToken,
  token2: ChartToken,
  reserveIn: number,
  reserveOut: number,
  cyberUsd?: number,
): { token1Usd?: number; token2Usd?: number; tvlUsd?: number } {
  const chainId = ChainId.CYBERIA;
  let token1Usd = isStable(token1.address, chainId) ? 1 : undefined;
  let token2Usd = isStable(token2.address, chainId) ? 1 : undefined;
  const wcyberAddress = normalizeAddress(WETH[chainId]?.address);

  if (normalizeAddress(token1.address) === wcyberAddress) {
    token1Usd = cyberUsd;
  }

  if (normalizeAddress(token2.address) === wcyberAddress) {
    token2Usd = cyberUsd;
  }

  if (token1Usd === undefined && token2Usd !== undefined && reserveIn > 0) {
    token1Usd = (reserveOut * token2Usd) / reserveIn;
  }

  if (token2Usd === undefined && token1Usd !== undefined && reserveOut > 0) {
    token2Usd = (reserveIn * token1Usd) / reserveOut;
  }

  const tvlUsd =
    token1Usd !== undefined && token2Usd !== undefined
      ? reserveIn * token1Usd + reserveOut * token2Usd
      : undefined;

  return { token1Usd, token2Usd, tvlUsd };
}

async function readReserves(
  factory: Contract,
  tokenA: ChartToken,
  tokenB: ChartToken,
  provider: any,
): Promise<{ pairAddress: string; reserves?: ReservesSnapshot }> {
  const pairAddress = (await factory.getPair(
    tokenA.address,
    tokenB.address,
  )) as string;

  if (pairAddress.toLowerCase() === constants.AddressZero.toLowerCase()) {
    return { pairAddress };
  }

  const pair = new Contract(pairAddress, PAIR_ABI, provider);
  const [token0, reserves] = (await Promise.all([
    pair.token0(),
    pair.getReserves(),
  ])) as [string, [BigNumber, BigNumber, number]];

  return {
    pairAddress,
    reserves: {
      token0,
      reserve0: reserves[0],
      reserve1: reserves[1],
    },
  };
}

async function getCyberUsdPrice(
  factory: Contract,
  provider: any,
): Promise<number | undefined> {
  const chainId = ChainId.CYBERIA;
  const wcyber = WETH[chainId];
  const stableTokens = [USDC[chainId], USDT[chainId]].filter(Boolean);

  for (const stable of stableTokens) {
    try {
      const { reserves } = await readReserves(
        factory,
        wcyber,
        stable,
        provider,
      );

      if (!reserves) continue;

      const wcyberReserve = getReserveForToken(
        reserves,
        wcyber.address,
        wcyber.decimals,
      );
      const stableReserve = getReserveForToken(
        reserves,
        stable.address,
        stable.decimals,
      );

      if (wcyberReserve > 0 && stableReserve > 0) {
        return stableReserve / wcyberReserve;
      }
    } catch {
      // Keep trying the next stable pool.
    }
  }

  return undefined;
}

async function fetchExplorerSwapLogs(
  pair: Contract,
  pairAddress: string,
  fromBlock: number,
  toBlock: number,
): Promise<ParsedSwapLog[]> {
  const logs: ParsedSwapLog[] = [];
  const topic0 = pair.interface.getEventTopic('Swap');

  for (let page = 1; page <= MAX_EXPLORER_PAGES; page += 1) {
    const params = new URLSearchParams({
      module: 'logs',
      action: 'getLogs',
      address: pairAddress,
      fromBlock: String(fromBlock),
      toBlock: String(toBlock),
      topic0,
      page: String(page),
      offset: String(EXPLORER_PAGE_SIZE),
    });
    const response = await fetch(`${EXPLORER_LOGS_API}?${params.toString()}`);

    if (!response.ok) {
      throw new Error(`Explorer logs request failed: ${response.status}`);
    }

    const payload = await response.json();
    const result = Array.isArray(payload?.result) ? payload.result : [];

    for (const log of result) {
      const topics = (log.topics ?? []).filter(
        (topic: string | null) => typeof topic === 'string',
      );
      const parsed = pair.interface.parseLog({
        data: log.data,
        topics,
      });
      const blockNumber = Number.parseInt(log.blockNumber, 16);
      const timestamp = Number.parseInt(log.timeStamp, 16);
      const logIndex = Number.parseInt(log.logIndex, 16);

      logs.push({
        id: `${log.transactionHash}:${logIndex}`,
        blockNumber,
        timestamp,
        args: {
          amount0In: parsed.args.amount0In,
          amount1In: parsed.args.amount1In,
          amount0Out: parsed.args.amount0Out,
          amount1Out: parsed.args.amount1Out,
        },
      });
    }

    if (result.length < EXPLORER_PAGE_SIZE) break;
  }

  return logs;
}

function buildSwapPoints(
  logs: ParsedSwapLog[],
  token0: string,
  token1: ChartToken,
  token2: ChartToken,
  token1Usd?: number,
  token2Usd?: number,
): SwapPoint[] {
  const inputIsToken0 =
    normalizeAddress(token0) === normalizeAddress(token1.address);

  return logs
    .map((log) => {
      const args = log.args;
      const amount0In = Number(
        formatUnits(
          args.amount0In,
          inputIsToken0 ? token1.decimals : token2.decimals,
        ),
      );
      const amount1In = Number(
        formatUnits(
          args.amount1In,
          inputIsToken0 ? token2.decimals : token1.decimals,
        ),
      );
      const amount0Out = Number(
        formatUnits(
          args.amount0Out,
          inputIsToken0 ? token1.decimals : token2.decimals,
        ),
      );
      const amount1Out = Number(
        formatUnits(
          args.amount1Out,
          inputIsToken0 ? token2.decimals : token1.decimals,
        ),
      );
      const token1Amount = inputIsToken0
        ? amount0In + amount0Out
        : amount1In + amount1Out;
      const token2Amount = inputIsToken0
        ? amount1In + amount1Out
        : amount0In + amount0Out;
      const amountIn = amount0In + amount1In;
      const amountOut = amount0Out + amount1Out;
      const price = token1Amount > 0 ? token2Amount / token1Amount : 0;
      const token1Value =
        token1Usd !== undefined ? token1Amount * token1Usd : undefined;
      const token2Value =
        token2Usd !== undefined ? token2Amount * token2Usd : undefined;
      const volumeUsd =
        token1Value !== undefined && token2Value !== undefined
          ? (token1Value + token2Value) / 2
          : token1Value ?? token2Value;

      return {
        id: log.id,
        timestamp: log.timestamp,
        blockNumber: log.blockNumber,
        price,
        amountIn,
        amountOut,
        token1Amount,
        token2Amount,
        volumeUsd,
      };
    })
    .filter((point) => point.timestamp > 0 && point.price > 0)
    .sort((a, b) => a.blockNumber - b.blockNumber);
}

function aggregateDailyVolume(points: SwapPoint[]): DailyVolume[] {
  const grouped = new Map<string, DailyVolume>();

  for (const point of points) {
    const key = dayKey(point.timestamp);
    const current =
      grouped.get(key) ??
      ({
        day: key,
        timestamp: Math.floor(
          new Date(`${key}T00:00:00.000Z`).getTime() / 1000,
        ),
        fallbackVolume: 0,
      } as DailyVolume);

    if (point.volumeUsd !== undefined) {
      current.volumeUsd = (current.volumeUsd ?? 0) + point.volumeUsd;
    }

    current.fallbackVolume += point.token2Amount;
    grouped.set(key, current);
  }

  return Array.from(grouped.values()).sort((a, b) => b.timestamp - a.timestamp);
}

const CyberiaPoolChart: React.FC<{
  token1?: ChartToken;
  token2?: ChartToken;
}> = ({ token1, token2 }) => {
  const { chainId } = useActiveWeb3React();
  const chainIdToUse = chainId ?? ChainId.CYBERIA;
  const allTransactions = useAllTransactions();
  const [state, setState] = useState<ChartState>({ status: 'idle' });
  const swapsRef = useRef<SwapPoint[]>([]);
  const lastBlockRef = useRef<number | undefined>(undefined);
  const latestReceiptKey = useMemo(
    () =>
      Object.entries(allTransactions)
        .filter(([, tx]) => tx.receipt)
        .map(([hash, tx]) => `${hash}:${tx.receipt?.blockNumber}`)
        .sort()
        .join('|'),
    [allTransactions],
  );

  useEffect(() => {
    let cancelled = false;

    async function load(quiet = false) {
      if (!token1 || !token2) {
        setState({ status: 'idle' });
        return;
      }

      const factoryAddress = V2_FACTORY_ADDRESSES[chainIdToUse];
      const provider = RPC_PROVIDERS[chainIdToUse];

      if (!factoryAddress || !provider) {
        setState({ status: 'unsupported' });
        return;
      }

      if (!quiet) {
        setState({ status: 'loading' });
      }

      try {
        const factory = new Contract(factoryAddress, FACTORY_ABI, provider);
        const { pairAddress, reserves } = await readReserves(
          factory,
          token1,
          token2,
          provider,
        );

        if (!reserves) {
          if (!cancelled) setState({ status: 'empty' });
          return;
        }

        const pair = new Contract(pairAddress, PAIR_ABI, provider);
        const inputIsToken0 =
          reserves.token0.toLowerCase() === token1.address.toLowerCase();
        const reserveIn = Number(
          formatUnits(
            inputIsToken0 ? reserves.reserve0 : reserves.reserve1,
            token1.decimals,
          ),
        );
        const reserveOut = Number(
          formatUnits(
            inputIsToken0 ? reserves.reserve1 : reserves.reserve0,
            token2.decimals,
          ),
        );
        const spotPrice = reserveIn > 0 ? reserveOut / reserveIn : 0;
        const cyberUsd = await getCyberUsdPrice(factory, provider);
        const { token1Usd, token2Usd, tvlUsd } = derivePairPrices(
          token1,
          token2,
          reserveIn,
          reserveOut,
          cyberUsd,
        );
        const latestBlock = await provider.getBlockNumber();
        const cutoffTimestamp =
          Math.floor(Date.now() / 1000) - HISTORY_WINDOW_SECONDS;
        const fromBlock =
          quiet && lastBlockRef.current
            ? Math.max(0, lastBlockRef.current - REFRESH_OVERLAP_BLOCKS)
            : Math.max(0, latestBlock - HISTORY_BLOCKS);
        let logs: ParsedSwapLog[] = [];
        try {
          logs = await fetchExplorerSwapLogs(
            pair,
            pairAddress,
            fromBlock,
            latestBlock,
          );
        } catch {
          logs = [];
        }
        const newPoints = buildSwapPoints(
          logs,
          reserves.token0,
          token1,
          token2,
          token1Usd,
          token2Usd,
        );
        const merged = [...swapsRef.current, ...newPoints]
          .reduce<Map<string, SwapPoint>>((memo, point) => {
            memo.set(point.id, point);
            return memo;
          }, new Map())
          .values();
        const allPoints = Array.from(merged)
          .filter(
            (point) =>
              point.blockNumber >= latestBlock - HISTORY_BLOCKS &&
              point.timestamp >= cutoffTimestamp,
          )
          .sort((a, b) => a.blockNumber - b.blockNumber);

        swapsRef.current = allPoints;
        lastBlockRef.current = latestBlock;

        if (!cancelled) {
          setState({
            status: 'ready',
            pairAddress,
            reserveIn,
            reserveOut,
            spotPrice,
            tvlUsd,
            dailyVolume: aggregateDailyVolume(allPoints),
            pricePoints:
              allPoints.length > 0
                ? allPoints.map(({ id, timestamp, blockNumber, price }) => ({
                    id,
                    timestamp,
                    blockNumber,
                    price,
                  }))
                : [
                    {
                      id: 'spot',
                      timestamp: Math.floor(Date.now() / 1000),
                      blockNumber: latestBlock,
                      price: spotPrice,
                    },
                  ],
            updatedAt: Date.now(),
            lastBlock: latestBlock,
          });
        }
      } catch {
        if (!cancelled) setState({ status: 'empty' });
      }
    }

    swapsRef.current = [];
    lastBlockRef.current = undefined;
    load();
    const interval = window.setInterval(() => {
      load(true);
    }, REFRESH_MS);

    return () => {
      cancelled = true;
      window.clearInterval(interval);
    };
  }, [chainIdToUse, token1, token2, latestReceiptKey]);

  const line = useMemo(
    () => (state.status === 'ready' ? chartPolyline(state.pricePoints) : ''),
    [state],
  );
  const area = line ? `0,100 ${line} 100,100` : '';
  const pairLabel =
    token1 && token2
      ? `${token1.symbol ?? 'Token'}/${token2.symbol ?? 'Token'}`
      : 'Select a pair';

  return (
    <Box className='cyberiaPoolChart'>
      <Box className='cyberiaPoolChartHeader'>
        <Box>
          <p className='cyberiaPoolChartEyebrow'>Ritual market</p>
          <h3>{pairLabel}</h3>
        </Box>
        {state.status === 'ready' && (
          <span>{shortAddress(state.pairAddress)}</span>
        )}
      </Box>

      {state.status === 'ready' && line ? (
        <>
          <Box className='cyberiaPoolChartStats'>
            <div>
              <small>
                Spot
                {state.updatedAt ? ' live' : ''}
              </small>
              <strong>{formatNumber(state.spotPrice)}</strong>
            </div>
            <div>
              <small>TVL</small>
              <strong>{formatUsd(state.tvlUsd)}</strong>
            </div>
            <div>
              <small>7d swaps</small>
              <strong>{state.pricePoints.length}</strong>
            </div>
          </Box>
          <Box className='cyberiaPoolChartPlot'>
            <svg
              className='cyberiaPoolChartSvg'
              viewBox='0 0 100 100'
              preserveAspectRatio='none'
              role='img'
              aria-label='Selected Ritual pool swap price history'
            >
              <polygon points={area} className='cyberiaPoolChartArea' />
              <polyline
                points={line}
                fill='none'
                className='cyberiaPoolChartLine'
                vectorEffect='non-scaling-stroke'
              />
            </svg>
            <Box className='cyberiaPoolChartAxis'>
              <span>
                {formatTimestamp(
                  state.pricePoints[0]?.timestamp ?? Date.now() / 1000,
                )}
              </span>
              <span>
                {formatTimestamp(
                  state.pricePoints[state.pricePoints.length - 1]?.timestamp ??
                    Date.now() / 1000,
                )}
              </span>
            </Box>
          </Box>
          <Box className='cyberiaPoolChartVolume'>
            <h4>Daily volume 7d</h4>
            {state.dailyVolume.length > 0 ? (
              state.dailyVolume.slice(0, HISTORY_DAYS).map((day) => (
                <div key={day.day}>
                  <span>{formatDay(day.timestamp)}</span>
                  <b>
                    {day.volumeUsd !== undefined
                      ? formatUsd(day.volumeUsd)
                      : `${formatNumber(
                          day.fallbackVolume,
                          4,
                        )} ${token2?.symbol ?? 'quote'}`}
                  </b>
                </div>
              ))
            ) : (
              <p>No swaps indexed in the last {HISTORY_DAYS} days.</p>
            )}
          </Box>
        </>
      ) : (
        <Box className='cyberiaPoolChartEmpty'>
          {state.status === 'loading'
            ? 'Loading pool chart...'
            : state.status === 'empty'
            ? 'No direct V2 pool chart for this pair yet.'
            : 'Select both tokens to view the pool chart.'}
        </Box>
      )}
    </Box>
  );
};

export default CyberiaPoolChart;
