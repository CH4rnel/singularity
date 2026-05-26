import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Box, Button, CircularProgress } from '@material-ui/core';
import { BigNumber, Contract } from 'ethers';
import { formatUnits, parseUnits } from 'ethers/lib/utils';
import ERC20_ABI from 'constants/abis/erc20.json';
import RITUAL_MASTERCHEF_ABI from 'constants/abis/ritual-masterchef.json';
import {
  RITUAL_MASTERCHEF_ADDRESS,
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

const RitualFarmPage: React.FC = () => {
  const { chainId, account } = useActiveWeb3React();
  const isSupportedNetwork = useIsSupportedNetwork();
  const { connectWallet } = useConnectWallet(isSupportedNetwork);
  const blockNumber = useBlockNumber();

  const chefAddress = chainId ? RITUAL_MASTERCHEF_ADDRESS[chainId] : undefined;
  const pools = useMemo<RitualFarmPool[]>(
    () => (chainId ? RITUAL_FARM_POOLS[chainId] ?? [] : []),
    [chainId],
  );

  const chef = useContract(chefAddress, RITUAL_MASTERCHEF_ABI);

  const [globals, setGlobals] = useState<Globals | null>(null);
  const [poolStates, setPoolStates] = useState<Record<number, PoolState>>({});
  const [refreshKey, setRefreshKey] = useState(0);
  const [busyPid, setBusyPid] = useState<number | null>(null);
  const [harvestingAll, setHarvestingAll] = useState(false);
  const [error, setError] = useState<string | null>(null);
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

  // Load globals + per-pool state from the chain's RPC (works irrespective of
  // which network the wallet is currently on).
  useEffect(() => {
    let cancelled = false;
    if (!chainId || !chefAddress) return;

    const rpc = RPC_PROVIDERS[chainId];
    if (!rpc) return;
    const chefRead = new Contract(chefAddress, RITUAL_MASTERCHEF_ABI, rpc);

    (async () => {
      try {
        const [totalAlloc, rpb, latestBlock] = await Promise.all([
          chefRead.totalAllocPoint(),
          chefRead.rewardPerBlock(),
          rpc.getBlock('latest'),
        ]);
        if (cancelled) return;
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
            totalAllocPoint: totalAlloc,
            rewardPerBlock: rpb,
            lastBlockNumber: BigNumber.from(latestBlock.number),
            lastBlockTimestampMs: Date.now(),
            blockTimeMs,
          };
        });
      } catch {
        // chef may not be deployed yet on this network
      }

      const next: Record<number, PoolState> = {};
      for (const pool of pools) {
        try {
          const lp = new Contract(pool.lpToken, ERC20_ABI, rpc);
          const [info, user, totalStaked, decimals] = await Promise.all([
            chefRead.poolInfo(pool.pid),
            account
              ? chefRead.userInfo(pool.pid, account)
              : Promise.resolve([ZERO, ZERO]),
            lp.balanceOf(chefAddress),
            lp.decimals().catch(() => 18),
          ]);
          const pending = account
            ? await chefRead.pendingReward(pool.pid, account).catch(() => ZERO)
            : ZERO;
          const userBalance = account
            ? await lp.balanceOf(account).catch(() => ZERO)
            : ZERO;
          const allowance = account
            ? await lp.allowance(account, chefAddress).catch(() => ZERO)
            : ZERO;

          // For LP pools, read pair reserves so we can value the LP token.
          let pair: PairInfo | undefined;
          if (!pool.isSolo) {
            try {
              const pairContract = new Contract(pool.lpToken, PAIR_ABI, rpc);
              const [t0, t1, reserves, totalSupply] = await Promise.all([
                pairContract.token0(),
                pairContract.token1(),
                pairContract.getReserves(),
                pairContract.totalSupply(),
              ]);
              const e0 = new Contract(t0, ERC20_ABI, rpc);
              const e1 = new Contract(t1, ERC20_ABI, rpc);
              const [d0, d1, s0, s1] = await Promise.all([
                e0.decimals().catch(() => 18),
                e1.decimals().catch(() => 18),
                e0.symbol().catch(() => '?'),
                e1.symbol().catch(() => '?'),
              ]);
              pair = {
                token0: String(t0).toLowerCase(),
                token1: String(t1).toLowerCase(),
                reserve0: reserves[0],
                reserve1: reserves[1],
                decimals0: d0,
                decimals1: d1,
                symbol0: String(s0),
                symbol1: String(s1),
                totalSupply,
              };
            } catch {
              // not an AMM pair / not deployed — leave unpriced
            }
          }

          next[pool.pid] = {
            allocPoint: info.allocPoint ?? info[1],
            totalStaked,
            userStaked: user.amount ?? user[0],
            pending,
            userBalance,
            allowance,
            decimals,
            tokenAddress: pool.lpToken.toLowerCase(),
            pair,
            lastRewardBlock: info.lastRewardBlock ?? info[2],
            accRewardPerShare: info.accRewardPerShare ?? info[3],
            rewardDebt: user.rewardDebt ?? user[1],
          };
        } catch {
          // pool not on-chain yet; skip
        }
      }
      if (!cancelled) setPoolStates(next);
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
          <h3>Ritual Farms</h3>
          <div className='subtitle'>
            {RITUAL_TOTAL_DAILY_ASH} ASH minted per day, split between pools by
            allocPoint. Block ≈ {RITUAL_BLOCK_TIME_SECONDS}s.
            {ashPriceUsd ? ` ASH ≈ $${formatUsdPrice(ashPriceUsd)}.` : ''}
          </div>
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

        {pools.map((pool) => {
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
          const livePending = computeLivePending(st, globals);

          // USD price of one staked unit (the solo token, or one LP token).
          const stakedUsdPrice = pool.isSolo
            ? prices[pool.lpToken.toLowerCase()]
            : st?.pair
            ? lpUsdPrice(st.pair, prices)
            : undefined;
          const totalStakedWhole = st
            ? Number(formatUnits(st.totalStaked, st.decimals))
            : 0;
          const tvlUsd =
            stakedUsdPrice != null
              ? totalStakedWhole * stakedUsdPrice
              : undefined;
          const apy =
            tvlUsd && tvlUsd > 0 && ashPriceUsd != null
              ? ((dailyAshForPool * ashPriceUsd * 365) / tvlUsd) * 100
              : undefined;

          return (
            <PoolCard
              key={pool.pid}
              pool={pool}
              state={st}
              livePending={livePending}
              allocSharePct={allocShare}
              dailyAsh={dailyAshForPool}
              apy={apy}
              stakedUsdPrice={stakedUsdPrice}
              tvlUsd={tvlUsd}
              ashPriceUsd={ashPriceUsd}
              account={account ?? null}
              busy={busyPid === pool.pid || harvestingAll}
              onConnect={() => connectWallet()}
              onApprove={() => handleApprove(pool)}
              onDeposit={(v) => handleDeposit(pool, v)}
              onWithdraw={(v) => handleWithdraw(pool, v)}
              onHarvest={() => handleHarvest(pool)}
            />
          );
        })}

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
          {apy != null && (
            <div className='apy' title='Estimated APY from ASH emissions'>
              {formatApy(apy)} APY
            </div>
          )}
          <div className='alloc'>{allocSharePct.toFixed(0)}% emission</div>
          <div className='rate'>≈ {dailyAsh.toFixed(2)} ASH/day</div>
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
  return `${formatTokenAmount(a0)} ${pair.symbol0} · ${formatTokenAmount(
    a1,
  )} ${pair.symbol1}`;
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
