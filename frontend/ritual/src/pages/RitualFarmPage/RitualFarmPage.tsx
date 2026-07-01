import React, {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { Link } from 'react-router-dom';
import { Box, Button, CircularProgress } from '@material-ui/core';
import { BigNumber, Contract } from 'ethers';
import { formatUnits, parseUnits, Interface } from 'ethers/lib/utils';
import ERC20_ABI from 'constants/abis/erc20.json';
import RITUAL_MASTERCHEF_ABI from 'constants/abis/ritual-masterchef.json';
import {
  RITUAL_MASTERCHEF_ADDRESS,
  RITUAL_MULTICALL3_ADDRESS,
  RITUAL_FARM_POOLS,
  RITUAL_TOTAL_DAILY_ASH,
  RITUAL_BLOCK_TIME_SECONDS,
  RITUAL_ASH_ADDRESS,
  RITUAL_USD_ANCHORS,
  RitualFarmPool,
} from 'constants/ritualFarms';
import { useActiveWeb3React, useConnectWallet } from 'hooks';
import { useContract } from 'hooks/useContract';
import { useIsSupportedNetwork } from 'utils';
import { useBlockNumber } from 'state/application/hooks';
import { RPC_PROVIDERS } from 'constants/providers';
import './RitualFarmPage.scss';

/**
 * Ritual emission farms page.
 *
 * Reads pool/user state from the v1 MasterChef on Cyberia.
 * Lets users approve, deposit, withdraw, and harvest (via deposit(_, 0)).
 *
 * Cyberia has no price oracle, so token/LP USD prices are derived directly
 * from the AMM pair reserves: stablecoins anchor at $1 and prices propagate
 * across pairs (see `buildPriceMap`). Those feed the APY% and USD figures.
 */

const ZERO = BigNumber.from(0);
const ACC_PRECISION = BigNumber.from('1000000000000'); // 1e12 — MasterChef internal scale

// Minimal human-readable ABI for reading AMM pair reserves.
const PAIR_ABI = [
  'function token0() view returns (address)',
  'function token1() view returns (address)',
  'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)',
  'function totalSupply() view returns (uint256)',
];

const MULTICALL3_ABI = [
  'function aggregate3((address target,bool allowFailure,bytes callData)[] calls) view returns ((bool success,bytes returnData)[] returnData)',
];

/** One batched read: an encoded call plus a sink for its decoded result. */
interface McTask {
  target: string;
  callData: string;
  /** Receives the raw returnData, or null if the sub-call reverted. */
  onResult: (data: string | null) => void;
}

/**
 * Run encoded read-only calls through Multicall3.aggregate3 (chunked so no
 * single request grows too large) and route each result back to its task.
 * Failed sub-calls resolve to null — the same effect the old per-call
 * `.catch(() => fallback)` had, but in a couple of round-trips instead of one
 * HTTP request per read.
 */
async function runMulticall(
  mcAddress: string,
  provider: unknown,
  tasks: McTask[],
): Promise<void> {
  if (tasks.length === 0) return;
  const mc = new Contract(mcAddress, MULTICALL3_ABI, provider as never);
  const CHUNK = 80;
  for (let i = 0; i < tasks.length; i += CHUNK) {
    const slice = tasks.slice(i, i + CHUNK);
    const res: Array<{
      success: boolean;
      returnData: string;
    }> = await mc.aggregate3(
      slice.map((t) => ({
        target: t.target,
        allowFailure: true,
        callData: t.callData,
      })),
    );
    res.forEach((r, j) => {
      const ok = (r.success ?? (r as never)[0]) === true;
      const data = r.returnData ?? (r as never)[1];
      slice[j].onResult(ok && data && data !== '0x' ? data : null);
    });
  }
}

/**
 * Static, never-changing metadata for a staked token / LP pair, cached across
 * refreshes so we only ever read decimals/symbols/token0/token1 once.
 */
interface PairMeta {
  lpDecimals: number;
  token0?: string;
  token1?: string;
  decimals0?: number;
  decimals1?: number;
  symbol0?: string;
  symbol1?: string;
}

interface PairInfo {
  token0: string; // lowercased
  token1: string; // lowercased
  reserve0: BigNumber;
  reserve1: BigNumber;
  decimals0: number;
  decimals1: number;
  symbol0: string;
  symbol1: string;
  totalSupply: BigNumber; // LP tokens are 18-decimal
}

interface PoolState {
  allocPoint: BigNumber;
  totalStaked: BigNumber; // chef LP balance
  userStaked: BigNumber;
  pending: BigNumber; // pending value as of lastSyncBlock (used as a fallback)
  userBalance: BigNumber;
  allowance: BigNumber;
  decimals: number;
  tokenAddress: string; // lowercased lpToken/staked-token address
  pair?: PairInfo; // present for LP pools, used for pricing
  // Fields needed to project pending forward between RPC reads.
  lastRewardBlock: BigNumber;
  accRewardPerShare: BigNumber;
  rewardDebt: BigNumber;
}

interface Globals {
  totalAllocPoint: BigNumber;
  rewardPerBlock: BigNumber;
  // Anchor for the virtual-block clock.
  lastBlockNumber: BigNumber;
  lastBlockTimestampMs: number;
  blockTimeMs: number; // estimated block interval
}

interface RitualFarmPageProps {
  /**
   * 'active' (default) lists pools that still earn ASH. 'empty' lists retired
   * pools (allocPoint zeroed on-chain) in a withdraw-only mode so stakers can
   * pull their locked tokens out.
   */
  variant?: 'active' | 'empty';
}

const RitualFarmPage: React.FC<RitualFarmPageProps> = ({
  variant = 'active',
}) => {
  const { chainId, account } = useActiveWeb3React();
  const isSupportedNetwork = useIsSupportedNetwork();
  const { connectWallet } = useConnectWallet(isSupportedNetwork);
  const blockNumber = useBlockNumber();

  const isEmptyView = variant === 'empty';

  const chefAddress = chainId ? RITUAL_MASTERCHEF_ADDRESS[chainId] : undefined;
  const pools = useMemo<RitualFarmPool[]>(() => {
    const all = chainId ? RITUAL_FARM_POOLS[chainId] ?? [] : [];
    // Active view hides retired pools; empty view shows only them.
    return all.filter((p) => (isEmptyView ? !!p.retired : !p.retired));
  }, [chainId, isEmptyView]);

  // Whether any retired pools exist at all — gates the "Retired farms" link on
  // the active page so it only appears when there is somewhere to point.
  const hasRetired = useMemo(
    () =>
      chainId
        ? (RITUAL_FARM_POOLS[chainId] ?? []).some((p) => p.retired)
        : false,
    [chainId],
  );

  const chef = useContract(chefAddress, RITUAL_MASTERCHEF_ABI);

  const [globals, setGlobals] = useState<Globals | null>(null);
  const [poolStates, setPoolStates] = useState<Record<number, PoolState>>({});
  // Static token/LP metadata (decimals, symbols, token0/token1), keyed by
  // lowercased lpToken. Populated once and reused on every refresh so the
  // per-block reload only fetches the values that actually change.
  const pairMetaRef = useRef<Record<string, PairMeta>>({});
  const [refreshKey, setRefreshKey] = useState(0);
  const [busyPid, setBusyPid] = useState<number | null>(null);
  const [harvestingAll, setHarvestingAll] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sortBy, setSortBy] = useState<'tvl' | 'apy'>('tvl');
  // Frame counter — bumped 5×/sec to drive smooth pending-reward animation.
  // Reading `tick` keeps it in the dependency graph so the projector below
  // recomputes on every interval tick.
  const [tick, setTick] = useState(0);
  void tick;

  const refresh = useCallback(() => setRefreshKey((k) => k + 1), []);

  // Refresh from chain on every new block.
  useEffect(() => {
    if (!blockNumber) return;
    setRefreshKey((k) => k + 1);
  }, [blockNumber]);

  // Smooth tick for live pending-reward projection.
  useEffect(() => {
    const id = setInterval(() => setTick((t) => t + 1), 200);
    return () => clearInterval(id);
  }, []);

  // Load globals + per-pool state from the chain's RPC, batched through
  // Multicall3. All reads for all pools go out in a couple of round-trips
  // rather than one HTTP request per read, so the page stays responsive as the
  // pool count grows. Static metadata (decimals/symbols/token0/token1) is read
  // once and cached in pairMetaRef; only the changing values are re-read.
  useEffect(() => {
    let cancelled = false;
    if (!chainId || !chefAddress) return;

    const rpc = RPC_PROVIDERS[chainId];
    const mcAddress = RITUAL_MULTICALL3_ADDRESS[chainId];
    if (!rpc || !mcAddress) return;

    const chefIface = new Interface(RITUAL_MASTERCHEF_ABI as never);
    const erc20Iface = new Interface(ERC20_ABI as never);
    const pairIface = new Interface(PAIR_ABI);
    const metaCache = pairMetaRef.current;

    // Per-pool scratch buffers the multicall result sinks write into.
    interface Scratch {
      poolInfo?: ReturnType<Interface['decodeFunctionResult']> | null;
      userInfo?: ReturnType<Interface['decodeFunctionResult']> | null;
      pending?: BigNumber;
      totalStaked?: BigNumber;
      userBalance?: BigNumber;
      allowance?: BigNumber;
      lpDecimals?: number;
      token0?: string;
      token1?: string;
      reserve0?: BigNumber;
      reserve1?: BigNumber;
      totalSupply?: BigNumber;
    }

    (async () => {
      try {
        let gAlloc: BigNumber | null = null;
        let gRpb: BigNumber | null = null;
        const scratch: Record<number, Scratch> = {};
        const tasks: McTask[] = [
          {
            target: chefAddress,
            callData: chefIface.encodeFunctionData('totalAllocPoint'),
            onResult: (d) =>
              (gAlloc = d
                ? chefIface.decodeFunctionResult('totalAllocPoint', d)[0]
                : null),
          },
          {
            target: chefAddress,
            callData: chefIface.encodeFunctionData('rewardPerBlock'),
            onResult: (d) =>
              (gRpb = d
                ? chefIface.decodeFunctionResult('rewardPerBlock', d)[0]
                : null),
          },
        ];

        for (const pool of pools) {
          const s: Scratch = {};
          scratch[pool.pid] = s;
          const lp = pool.lpToken;
          const cached = metaCache[lp.toLowerCase()];

          tasks.push({
            target: chefAddress,
            callData: chefIface.encodeFunctionData('poolInfo', [pool.pid]),
            onResult: (d) =>
              (s.poolInfo = d
                ? chefIface.decodeFunctionResult('poolInfo', d)
                : null),
          });
          tasks.push({
            target: lp,
            callData: erc20Iface.encodeFunctionData('balanceOf', [chefAddress]),
            onResult: (d) =>
              (s.totalStaked = d
                ? erc20Iface.decodeFunctionResult('balanceOf', d)[0]
                : ZERO),
          });

          if (account) {
            tasks.push({
              target: chefAddress,
              callData: chefIface.encodeFunctionData('userInfo', [
                pool.pid,
                account,
              ]),
              onResult: (d) =>
                (s.userInfo = d
                  ? chefIface.decodeFunctionResult('userInfo', d)
                  : null),
            });
            tasks.push({
              target: chefAddress,
              callData: chefIface.encodeFunctionData('pendingReward', [
                pool.pid,
                account,
              ]),
              onResult: (d) =>
                (s.pending = d
                  ? chefIface.decodeFunctionResult('pendingReward', d)[0]
                  : ZERO),
            });
            tasks.push({
              target: lp,
              callData: erc20Iface.encodeFunctionData('balanceOf', [account]),
              onResult: (d) =>
                (s.userBalance = d
                  ? erc20Iface.decodeFunctionResult('balanceOf', d)[0]
                  : ZERO),
            });
            tasks.push({
              target: lp,
              callData: erc20Iface.encodeFunctionData('allowance', [
                account,
                chefAddress,
              ]),
              onResult: (d) =>
                (s.allowance = d
                  ? erc20Iface.decodeFunctionResult('allowance', d)[0]
                  : ZERO),
            });
          }

          if (cached) {
            s.lpDecimals = cached.lpDecimals;
            s.token0 = cached.token0;
            s.token1 = cached.token1;
          } else {
            tasks.push({
              target: lp,
              callData: erc20Iface.encodeFunctionData('decimals'),
              onResult: (d) =>
                (s.lpDecimals = d
                  ? erc20Iface.decodeFunctionResult('decimals', d)[0]
                  : 18),
            });
            if (!pool.isSolo) {
              tasks.push({
                target: lp,
                callData: pairIface.encodeFunctionData('token0'),
                onResult: (d) =>
                  (s.token0 = d
                    ? pairIface.decodeFunctionResult('token0', d)[0]
                    : undefined),
              });
              tasks.push({
                target: lp,
                callData: pairIface.encodeFunctionData('token1'),
                onResult: (d) =>
                  (s.token1 = d
                    ? pairIface.decodeFunctionResult('token1', d)[0]
                    : undefined),
              });
            }
          }

          if (!pool.isSolo) {
            tasks.push({
              target: lp,
              callData: pairIface.encodeFunctionData('getReserves'),
              onResult: (d) => {
                if (!d) return;
                const r = pairIface.decodeFunctionResult('getReserves', d);
                s.reserve0 = r[0];
                s.reserve1 = r[1];
              },
            });
            tasks.push({
              target: lp,
              callData: pairIface.encodeFunctionData('totalSupply'),
              onResult: (d) =>
                (s.totalSupply = d
                  ? pairIface.decodeFunctionResult('totalSupply', d)[0]
                  : undefined),
            });
          }
        }

        // Round 1: everything above + the latest block for the virtual clock.
        const [latestBlock] = await Promise.all([
          rpc.getBlock('latest'),
          runMulticall(mcAddress, rpc, tasks),
        ]);
        if (cancelled) return;

        // Round 2: token decimals+symbols for pairs not cached yet. Their
        // target addresses (token0/token1) are only known after round 1.
        const tokenMeta: Record<
          string,
          { decimals?: number; symbol?: string }
        > = {};
        const metaTasks: McTask[] = [];
        for (const pool of pools) {
          if (pool.isSolo || metaCache[pool.lpToken.toLowerCase()]) continue;
          const s = scratch[pool.pid];
          for (const t of [s.token0, s.token1]) {
            if (!t) continue;
            const key = t.toLowerCase();
            if (tokenMeta[key]) continue;
            tokenMeta[key] = {};
            metaTasks.push({
              target: t,
              callData: erc20Iface.encodeFunctionData('decimals'),
              onResult: (d) =>
                (tokenMeta[key].decimals = d
                  ? erc20Iface.decodeFunctionResult('decimals', d)[0]
                  : 18),
            });
            metaTasks.push({
              target: t,
              callData: erc20Iface.encodeFunctionData('symbol'),
              onResult: (d) =>
                (tokenMeta[key].symbol = d
                  ? String(erc20Iface.decodeFunctionResult('symbol', d)[0])
                  : '?'),
            });
          }
        }
        if (metaTasks.length) {
          await runMulticall(mcAddress, rpc, metaTasks);
          if (cancelled) return;
        }

        setGlobals((prev) => {
          // Estimate block time from successive observations; default 1 s.
          let blockTimeMs = prev?.blockTimeMs ?? 1000;
          if (prev && latestBlock.number > prev.lastBlockNumber.toNumber()) {
            const dn = latestBlock.number - prev.lastBlockNumber.toNumber();
            const dt = Date.now() - prev.lastBlockTimestampMs;
            if (dn > 0 && dt > 0) {
              const sample = dt / dn;
              // Light EMA so a single laggy refresh doesn't ruin the estimate.
              blockTimeMs = Math.max(
                100,
                Math.min(10_000, prev.blockTimeMs * 0.7 + sample * 0.3),
              );
            }
          }
          return {
            totalAllocPoint: gAlloc ?? BigNumber.from(0),
            rewardPerBlock: gRpb ?? BigNumber.from(0),
            lastBlockNumber: BigNumber.from(latestBlock.number),
            lastBlockTimestampMs: Date.now(),
            blockTimeMs,
          };
        });

        const next: Record<number, PoolState> = {};
        for (const pool of pools) {
          const s = scratch[pool.pid];
          if (!s || !s.poolInfo) continue; // pool not on-chain yet
          const lpLower = pool.lpToken.toLowerCase();

          // Resolve + persist the static metadata for this token/pair.
          let meta = metaCache[lpLower];
          if (!meta) {
            meta = { lpDecimals: s.lpDecimals ?? 18 };
            if (!pool.isSolo && s.token0 && s.token1) {
              const k0 = s.token0.toLowerCase();
              const k1 = s.token1.toLowerCase();
              meta.token0 = s.token0;
              meta.token1 = s.token1;
              meta.decimals0 = tokenMeta[k0]?.decimals ?? 18;
              meta.decimals1 = tokenMeta[k1]?.decimals ?? 18;
              meta.symbol0 = tokenMeta[k0]?.symbol ?? '?';
              meta.symbol1 = tokenMeta[k1]?.symbol ?? '?';
              metaCache[lpLower] = meta;
            } else if (pool.isSolo) {
              metaCache[lpLower] = meta;
            }
            // LP whose pair isn't deployed: leave uncached, retry next block.
          }

          let pair: PairInfo | undefined;
          if (
            !pool.isSolo &&
            meta.token0 &&
            meta.token1 &&
            s.reserve0 != null &&
            s.reserve1 != null &&
            s.totalSupply != null
          ) {
            pair = {
              token0: meta.token0.toLowerCase(),
              token1: meta.token1.toLowerCase(),
              reserve0: s.reserve0,
              reserve1: s.reserve1,
              decimals0: meta.decimals0 ?? 18,
              decimals1: meta.decimals1 ?? 18,
              symbol0: meta.symbol0 ?? '?',
              symbol1: meta.symbol1 ?? '?',
              totalSupply: s.totalSupply,
            };
          }

          const info = s.poolInfo;
          const user = s.userInfo;
          next[pool.pid] = {
            allocPoint: info.allocPoint ?? info[1],
            totalStaked: s.totalStaked ?? ZERO,
            userStaked: user ? user.amount ?? user[0] : ZERO,
            pending: s.pending ?? ZERO,
            userBalance: s.userBalance ?? ZERO,
            allowance: s.allowance ?? ZERO,
            decimals: meta.lpDecimals,
            tokenAddress: lpLower,
            pair,
            lastRewardBlock: info.lastRewardBlock ?? info[2],
            accRewardPerShare: info.accRewardPerShare ?? info[3],
            rewardDebt: user ? user.rewardDebt ?? user[1] : ZERO,
          };
        }
        if (!cancelled) setPoolStates(next);
      } catch {
        // Network hiccup — keep the previous state; the next block retriggers.
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [chainId, chefAddress, pools, account, refreshKey]);

  // USD price map derived from the pools' AMM reserves.
  const prices = useMemo(() => buildPriceMap(poolStates), [poolStates]);
  const ashPriceUsd = prices[RITUAL_ASH_ADDRESS];

  const handleApprove = async (pool: RitualFarmPool): Promise<boolean> => {
    if (!chef || !chefAddress || !account) return false;
    setBusyPid(pool.pid);
    setError(null);
    try {
      const ethers = await import('ethers');
      const lp = new ethers.Contract(
        pool.lpToken,
        ERC20_ABI,
        chef.signer ?? chef.provider,
      );
      const tx = await lp.approve(chefAddress, ethers.constants.MaxUint256);
      await tx.wait();
      refresh();
      return true;
    } catch (e) {
      setError(e?.message ?? String(e));
      return false;
    } finally {
      setBusyPid(null);
    }
  };

  const handleDeposit = async (
    pool: RitualFarmPool,
    amount: string,
  ): Promise<boolean> => {
    if (!chef || !account || !amount) return false;
    setBusyPid(pool.pid);
    setError(null);
    try {
      const decimals = poolStates[pool.pid]?.decimals ?? 18;
      const value = parseUnits(amount, decimals);
      const tx = await chef.deposit(pool.pid, value);
      await tx.wait();
      refresh();
      return true;
    } catch (e) {
      setError(e?.message ?? String(e));
      return false;
    } finally {
      setBusyPid(null);
    }
  };

  const handleWithdraw = async (
    pool: RitualFarmPool,
    amount: string,
  ): Promise<boolean> => {
    if (!chef || !account || !amount) return false;
    setBusyPid(pool.pid);
    setError(null);
    try {
      const decimals = poolStates[pool.pid]?.decimals ?? 18;
      const value = parseUnits(amount, decimals);
      const tx = await chef.withdraw(pool.pid, value);
      await tx.wait();
      refresh();
      return true;
    } catch (e) {
      setError(e?.message ?? String(e));
      return false;
    } finally {
      setBusyPid(null);
    }
  };

  const handleHarvest = async (pool: RitualFarmPool): Promise<boolean> => {
    if (!chef || !account) return false;
    setBusyPid(pool.pid);
    setError(null);
    try {
      const tx = await chef.deposit(pool.pid, 0);
      await tx.wait();
      refresh();
      return true;
    } catch (e) {
      setError(e?.message ?? String(e));
      return false;
    } finally {
      setBusyPid(null);
    }
  };

  // Pools with a non-zero pending balance — the targets for "Harvest all".
  const harvestablePids = useMemo(
    () =>
      pools
        .filter((p) => {
          const live = computeLivePending(poolStates[p.pid], globals);
          const amt = live ?? poolStates[p.pid]?.pending;
          return amt && amt.gt(0);
        })
        .map((p) => p.pid),
    [pools, poolStates, globals],
  );

  const handleHarvestAll = async () => {
    if (!chef || !account || harvestablePids.length === 0) return;
    setHarvestingAll(true);
    setError(null);
    try {
      // MasterChef has no batch claim — harvest each pool with rewards in turn.
      for (const pid of harvestablePids) {
        const tx = await chef.deposit(pid, 0);
        await tx.wait();
      }
      refresh();
    } catch (e) {
      setError(e?.message ?? String(e));
    } finally {
      setHarvestingAll(false);
    }
  };

  const sortedPools = useMemo(() => {
    const items = pools.map((pool) => {
      const st = poolStates[pool.pid];
      const allocShare =
        globals && st && globals.totalAllocPoint.gt(0)
          ? st.allocPoint
              .mul(10000)
              .div(globals.totalAllocPoint)
              .toNumber() / 100
          : 0;
      const dailyAshForPool =
        allocShare > 0 ? (RITUAL_TOTAL_DAILY_ASH * allocShare) / 100 : 0;
      const stakedUsdPrice = pool.isSolo
        ? prices[pool.lpToken.toLowerCase()]
        : st?.pair
        ? lpUsdPrice(st.pair, prices)
        : undefined;
      const totalStakedWhole = st
        ? Number(formatUnits(st.totalStaked, st.decimals))
        : 0;
      const tvlUsd =
        stakedUsdPrice != null ? totalStakedWhole * stakedUsdPrice : undefined;
      const apy =
        tvlUsd && tvlUsd > 0 && ashPriceUsd != null
          ? ((dailyAshForPool * ashPriceUsd * 365) / tvlUsd) * 100
          : undefined;
      return {
        pool,
        state: st,
        allocShare,
        dailyAsh: dailyAshForPool,
        stakedUsdPrice,
        tvlUsd,
        apy,
      };
    });
    const key = sortBy === 'tvl' ? 'tvlUsd' : 'apy';
    return [...items].sort((a, b) => {
      const av = a[key];
      const bv = b[key];
      if (av == null && bv == null) return 0;
      if (av == null) return 1;
      if (bv == null) return -1;
      return bv - av;
    });
  }, [pools, poolStates, globals, prices, ashPriceUsd, sortBy]);

  if (!chefAddress) {
    return (
      <Box className='ritualFarmPage'>
        <Box className='ritualFarmContainer'>
          <Box className='ritualFarmHeader'>
            <h3>Ritual Farms</h3>
            <div className='subtitle'>
              Switch to Cyberia (chainId 49406) to see emission pools.
            </div>
          </Box>
        </Box>
      </Box>
    );
  }

  return (
    <Box className='ritualFarmPage'>
      <Box className='ritualFarmContainer'>
        <Box className='ritualFarmHeader'>
          <h3>{isEmptyView ? 'Retired farms' : 'Ritual Farms'}</h3>
          <div className='subtitle'>
            {isEmptyView ? (
              'These pools no longer earn ASH. Withdraw your staked tokens below.'
            ) : (
              <>
                {RITUAL_TOTAL_DAILY_ASH} ASH minted per day, split between pools
                by allocPoint. Block ≈ {RITUAL_BLOCK_TIME_SECONDS}s.
                {ashPriceUsd ? ` ASH ≈ $${formatUsdPrice(ashPriceUsd)}.` : ''}
              </>
            )}
          </div>
          {isEmptyView ? (
            <div className='retiredLinkRow'>
              <Link to='/farm' className='retiredLink'>
                ← Back to active farms
              </Link>
            </div>
          ) : (
            hasRetired && (
              <div className='retiredLinkRow'>
                <Link to='/farm-empty' className='retiredLink'>
                  Retired farms — withdraw your tokens →
                </Link>
              </div>
            )
          )}
          {!isEmptyView && (
            <Box className='sortRow'>
              <span className='sortLabel'>Sort by</span>
              <Button
                className={`sortBtn${sortBy === 'tvl' ? ' active' : ''}`}
                onClick={() => setSortBy('tvl')}
              >
                TVL
              </Button>
              <Button
                className={`sortBtn${sortBy === 'apy' ? ' active' : ''}`}
                onClick={() => setSortBy('apy')}
              >
                APY%
              </Button>
            </Box>
          )}
          {account && (
            <Box className='harvestAllRow'>
              <Button
                className='actionBtn harvestAllBtn'
                disabled={harvestingAll || harvestablePids.length === 0}
                onClick={handleHarvestAll}
              >
                {harvestingAll ? (
                  <CircularProgress size={18} />
                ) : (
                  `Harvest all${
                    harvestablePids.length ? ` (${harvestablePids.length})` : ''
                  }`
                )}
              </Button>
            </Box>
          )}
        </Box>

        {error && <Box className='ritualFarmError'>{error}</Box>}

        {sortedPools.map(
          ({
            pool,
            state: st,
            allocShare,
            dailyAsh,
            stakedUsdPrice,
            tvlUsd,
            apy,
          }) => {
            const livePending = computeLivePending(st, globals);

            return (
              <PoolCard
                key={pool.pid}
                pool={pool}
                state={st}
                livePending={livePending}
                allocSharePct={allocShare}
                dailyAsh={dailyAsh}
                apy={apy}
                stakedUsdPrice={stakedUsdPrice}
                tvlUsd={tvlUsd}
                ashPriceUsd={ashPriceUsd}
                account={account ?? null}
                busy={busyPid === pool.pid || harvestingAll}
                withdrawOnly={isEmptyView}
                onConnect={() => connectWallet()}
                onApprove={() => handleApprove(pool)}
                onDeposit={(v) => handleDeposit(pool, v)}
                onWithdraw={(v) => handleWithdraw(pool, v)}
                onHarvest={() => handleHarvest(pool)}
              />
            );
          },
        )}

        {sortedPools.length === 0 && (
          <Box className='ritualFarmEmpty'>
            {isEmptyView ? 'No retired farms.' : 'No active farms right now.'}
          </Box>
        )}

        <div className='ritualFarmFooter'>MasterChef: {chefAddress}</div>
      </Box>
    </Box>
  );
};

interface PoolCardProps {
  pool: RitualFarmPool;
  state?: PoolState;
  livePending: BigNumber | undefined;
  allocSharePct: number;
  dailyAsh: number;
  apy: number | undefined;
  stakedUsdPrice: number | undefined;
  tvlUsd: number | undefined;
  ashPriceUsd: number | undefined;
  account: string | null;
  busy: boolean;
  /** Retired pool: hide deposit, show a Retired badge, keep withdraw/harvest. */
  withdrawOnly: boolean;
  onConnect: () => void;
  onApprove: () => Promise<boolean>;
  onDeposit: (amount: string) => Promise<boolean>;
  onWithdraw: (amount: string) => Promise<boolean>;
  onHarvest: () => Promise<boolean>;
}

const PoolCard: React.FC<PoolCardProps> = ({
  pool,
  state,
  livePending,
  allocSharePct,
  dailyAsh,
  apy,
  stakedUsdPrice,
  tvlUsd,
  ashPriceUsd,
  account,
  busy,
  withdrawOnly,
  onConnect,
  onApprove,
  onDeposit,
  onWithdraw,
  onHarvest,
}) => {
  const [depositValue, setDepositValue] = useState('');
  const [withdrawValue, setWithdrawValue] = useState('');

  const decimals = state?.decimals ?? 18;
  const fmt = (b?: BigNumber) =>
    b ? Number(formatUnits(b, decimals)).toFixed(6) : '—';
  const fmtAsh = (b?: BigNumber) =>
    b ? Number(formatUnits(b, 18)).toFixed(6) : '—';

  // USD subtext for an amount of the staked token.
  const usdStaked = (b?: BigNumber): string | undefined => {
    if (!b || stakedUsdPrice == null) return undefined;
    return formatUsd(Number(formatUnits(b, decimals)) * stakedUsdPrice);
  };
  // USD subtext for an ASH amount.
  const usdAsh = (b?: BigNumber): string | undefined => {
    if (!b || ashPriceUsd == null) return undefined;
    return formatUsd(Number(formatUnits(b, 18)) * ashPriceUsd);
  };

  // For LP pools, show what each LP balance is actually composed of.
  const breakdown = (b?: BigNumber): string | undefined =>
    !pool.isSolo && state?.pair ? lpBreakdown(state.pair, b) : undefined;

  const depositBn = parseAmount(depositValue, decimals);
  const needsApprove =
    !!state && depositBn.gt(0) && state.allowance.lt(depositBn);

  return (
    <Box className='poolCard'>
      <Box className='poolCardHeader'>
        <Box className='poolTitleWrap'>
          <TokenIcons icons={pool.icons} label={pool.label} />
          <Box>
            <div className='poolTitle'>
              {pool.label}
              <span className='pidTag'>pid={pool.pid}</span>
            </div>
            <div className='poolDescription'>{pool.description}</div>
          </Box>
        </Box>
        <Box className='poolEmission'>
          {withdrawOnly ? (
            <div className='retiredBadge'>Retired</div>
          ) : (
            <>
              {apy != null && (
                <div className='apy' title='Estimated APY from ASH emissions'>
                  {formatApy(apy)} APY
                </div>
              )}
              <div className='alloc'>{allocSharePct.toFixed(0)}% emission</div>
              <div className='rate'>≈ {dailyAsh.toFixed(2)} ASH/day</div>
            </>
          )}
        </Box>
      </Box>

      <Box className='poolStats'>
        <Stat
          label='Total staked'
          value={fmt(state?.totalStaked)}
          breakdown={breakdown(state?.totalStaked)}
          sub={tvlUsd != null ? formatUsd(tvlUsd) : undefined}
        />
        <Stat
          label='Your stake'
          value={fmt(state?.userStaked)}
          breakdown={breakdown(state?.userStaked)}
          sub={usdStaked(state?.userStaked)}
        />
        <Stat
          label='Wallet balance'
          value={fmt(state?.userBalance)}
          breakdown={breakdown(state?.userBalance)}
          sub={usdStaked(state?.userBalance)}
        />
        <Stat
          label='Pending ASH'
          value={fmtAsh(livePending ?? state?.pending)}
          sub={usdAsh(livePending ?? state?.pending)}
        />
      </Box>

      {!account ? (
        <Box className='poolActionsConnect'>
          <Button
            onClick={onConnect}
            className='actionBtn'
            style={{ minWidth: 180 }}
          >
            Connect wallet
          </Button>
        </Box>
      ) : (
        <Box className='poolActions'>
          {!withdrawOnly && (
            <Box className='actionRow'>
              <Box className='amountField'>
                <input
                  type='text'
                  value={depositValue}
                  onChange={(e) => setDepositValue(sanitize(e.target.value))}
                  placeholder={`Deposit ${pool.label}`}
                  inputMode='decimal'
                />
              </Box>
              <Button
                className='secondaryBtn'
                onClick={() =>
                  state &&
                  setDepositValue(formatUnits(state.userBalance, decimals))
                }
              >
                Max
              </Button>
              {needsApprove ? (
                <Button
                  className='actionBtn'
                  disabled={busy}
                  onClick={() => onApprove()}
                >
                  {busy ? <CircularProgress size={18} /> : 'Approve'}
                </Button>
              ) : (
                <Button
                  className='actionBtn'
                  disabled={busy || depositBn.isZero()}
                  onClick={async () => {
                    const ok = await onDeposit(depositValue);
                    if (ok) setDepositValue('');
                  }}
                >
                  {busy ? <CircularProgress size={18} /> : 'Deposit'}
                </Button>
              )}
            </Box>
          )}

          <Box className='actionRow'>
            <Box className='amountField'>
              <input
                type='text'
                value={withdrawValue}
                onChange={(e) => setWithdrawValue(sanitize(e.target.value))}
                placeholder='Withdraw'
                inputMode='decimal'
              />
            </Box>
            <Button
              className='secondaryBtn'
              onClick={() =>
                state &&
                setWithdrawValue(formatUnits(state.userStaked, decimals))
              }
            >
              Max
            </Button>
            <Button
              className='secondaryBtn'
              disabled={busy || parseAmount(withdrawValue, decimals).isZero()}
              onClick={async () => {
                const ok = await onWithdraw(withdrawValue);
                if (ok) setWithdrawValue('');
              }}
            >
              {busy ? <CircularProgress size={18} /> : 'Withdraw'}
            </Button>
            <Button
              className='actionBtn'
              disabled={
                busy ||
                !(livePending ?? state?.pending) ||
                (livePending ?? state?.pending ?? ZERO).isZero()
              }
              onClick={() => onHarvest()}
            >
              {busy ? <CircularProgress size={18} /> : 'Harvest'}
            </Button>
          </Box>
        </Box>
      )}
    </Box>
  );
};

const TokenIcons: React.FC<{ icons: string[]; label: string }> = ({
  icons,
  label,
}) => {
  const [broken, setBroken] = useState<Record<number, boolean>>({});
  if (!icons.length) return null;
  return (
    <Box className='tokenIcons'>
      {icons.map((src, i) =>
        broken[i] ? (
          <span key={i} className='tokenIconFallback'>
            {label.charAt(0)}
          </span>
        ) : (
          <img
            key={i}
            src={src}
            alt=''
            className='tokenIcon'
            onError={() => setBroken((b) => ({ ...b, [i]: true }))}
          />
        ),
      )}
    </Box>
  );
};

const Stat: React.FC<{
  label: string;
  value: string;
  sub?: string;
  breakdown?: string;
}> = ({ label, value, sub, breakdown }) => (
  <Box className='statCell'>
    <div className='statLabel'>{label}</div>
    <div className='statValue'>{value}</div>
    {breakdown && <div className='statBreakdown'>{breakdown}</div>}
    {sub && <div className='statSub'>{sub}</div>}
  </Box>
);

/**
 * Build a USD price map keyed by lowercased token address. Stablecoins anchor
 * at $1; prices then propagate across the farm's AMM pairs until no new token
 * can be priced (a few passes suffice for the Cyberia token graph).
 */
function buildPriceMap(
  states: Record<number, PoolState>,
): Record<string, number> {
  const prices: Record<string, number> = { ...RITUAL_USD_ANCHORS };
  const pairs = Object.values(states)
    .map((s) => s.pair)
    .filter((p): p is PairInfo => !!p);

  for (let pass = 0; pass < 8; pass++) {
    let changed = false;
    for (const p of pairs) {
      const r0 = Number(formatUnits(p.reserve0, p.decimals0));
      const r1 = Number(formatUnits(p.reserve1, p.decimals1));
      if (r0 <= 0 || r1 <= 0) continue;
      const p0 = prices[p.token0];
      const p1 = prices[p.token1];
      if (p0 != null && p1 == null) {
        prices[p.token1] = (r0 * p0) / r1;
        changed = true;
      } else if (p1 != null && p0 == null) {
        prices[p.token0] = (r1 * p1) / r0;
        changed = true;
      }
    }
    if (!changed) break;
  }
  return prices;
}

/** USD value of a single LP token from its reserves and total supply. */
function lpUsdPrice(
  p: PairInfo,
  prices: Record<string, number>,
): number | undefined {
  const supply = Number(formatUnits(p.totalSupply, 18));
  if (supply <= 0) return undefined;
  const p0 = prices[p.token0];
  const p1 = prices[p.token1];
  const r0 = Number(formatUnits(p.reserve0, p.decimals0));
  const r1 = Number(formatUnits(p.reserve1, p.decimals1));
  // Value the sides we can price; if only one side is known, double it
  // (a constant-product pool holds equal USD value on each side).
  if (p0 != null && p1 != null) return (r0 * p0 + r1 * p1) / supply;
  if (p0 != null) return (2 * r0 * p0) / supply;
  if (p1 != null) return (2 * r1 * p1) / supply;
  return undefined;
}

/**
 * Underlying token amounts represented by `lpAmount` LP tokens, formatted as
 * e.g. "12.34 ASH · 56.78 WCYBER". Lets users see what a stake of LP tokens is
 * actually composed of without having to crack open the pair themselves.
 */
function lpBreakdown(
  pair: PairInfo,
  lpAmount: BigNumber | undefined,
): string | undefined {
  if (!lpAmount || lpAmount.isZero() || pair.totalSupply.isZero())
    return undefined;
  const amount0 = lpAmount.mul(pair.reserve0).div(pair.totalSupply);
  const amount1 = lpAmount.mul(pair.reserve1).div(pair.totalSupply);
  const a0 = Number(formatUnits(amount0, pair.decimals0));
  const a1 = Number(formatUnits(amount1, pair.decimals1));
  return `${formatTokenAmount(a0)} ${pair.symbol0} · ${formatTokenAmount(a1)} ${
    pair.symbol1
  }`;
}

function formatTokenAmount(v: number): string {
  if (!isFinite(v)) return '—';
  if (v === 0) return '0';
  if (v < 0.0001) return '<0.0001';
  if (v >= 1000)
    return v.toLocaleString(undefined, { maximumFractionDigits: 0 });
  return v.toLocaleString(undefined, { maximumFractionDigits: 4 });
}

function formatUsd(v: number): string {
  if (!isFinite(v)) return '—';
  if (v === 0) return '$0';
  if (v < 0.01) return '<$0.01';
  if (v >= 1_000_000)
    return `$${(v / 1_000_000).toLocaleString(undefined, {
      maximumFractionDigits: 2,
    })}M`;
  if (v >= 1_000)
    return `$${(v / 1_000).toLocaleString(undefined, {
      maximumFractionDigits: 2,
    })}K`;
  return `$${v.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
}

function formatUsdPrice(v: number): string {
  if (!isFinite(v) || v <= 0) return '0';
  if (v < 0.0001) return v.toExponential(2);
  if (v < 1) return v.toPrecision(3);
  return v.toLocaleString(undefined, { maximumFractionDigits: 4 });
}

function formatApy(v: number): string {
  if (!isFinite(v)) return '∞';
  if (v >= 100_000) return `${(v / 1000).toFixed(0)}K%`;
  if (v >= 1000) return `${v.toFixed(0)}%`;
  return `${v.toFixed(1)}%`;
}

/**
 * Mirror MasterChef.pendingReward but with a virtual current block,
 * interpolated from the wall clock since the last RPC sync. Lets us animate
 * the pending counter smoothly between blocks without spamming the RPC.
 */
function computeLivePending(
  st: PoolState | undefined,
  globals: Globals | null,
): BigNumber | undefined {
  if (!st || !globals) return undefined;
  if (globals.totalAllocPoint.isZero()) return st.pending;
  if (st.userStaked.isZero()) return st.pending;

  // Virtual current block = lastBlockNumber + elapsed / blockTime.
  const elapsedMs = Math.max(0, Date.now() - globals.lastBlockTimestampMs);
  const elapsedBlocks = Math.floor(elapsedMs / globals.blockTimeMs);
  const virtualBlock = globals.lastBlockNumber.add(elapsedBlocks);

  if (virtualBlock.lte(st.lastRewardBlock)) {
    // No new blocks since last on-chain pool update.
    return st.userStaked
      .mul(st.accRewardPerShare)
      .div(ACC_PRECISION)
      .sub(st.rewardDebt);
  }

  if (st.totalStaked.isZero()) {
    return st.pending;
  }

  const blocks = virtualBlock.sub(st.lastRewardBlock);
  const reward = blocks
    .mul(globals.rewardPerBlock)
    .mul(st.allocPoint)
    .div(globals.totalAllocPoint);
  const accPerShare = st.accRewardPerShare.add(
    reward.mul(ACC_PRECISION).div(st.totalStaked),
  );
  const pending = st.userStaked
    .mul(accPerShare)
    .div(ACC_PRECISION)
    .sub(st.rewardDebt);
  return pending.lt(0) ? ZERO : pending;
}

function parseAmount(v: string, decimals: number): BigNumber {
  if (!v) return BigNumber.from(0);
  try {
    return parseUnits(v, decimals);
  } catch {
    return BigNumber.from(0);
  }
}

function sanitize(v: string): string {
  // accept digits and a single dot
  return v.replace(/[^\d.]/g, '').replace(/(\..*)\./g, '$1');
}

export default RitualFarmPage;
