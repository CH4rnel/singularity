<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    getAccount,
    getAssociatedTokenAddress,
    TOKEN_2022_PROGRAM_ID,
    TOKEN_PROGRAM_ID,
} from '@solana/spl-token';
import { Connection, PublicKey } from '@solana/web3.js';
import {
    Contract,
    JsonRpcProvider,
    MaxUint256,
    formatUnits,
    parseUnits,
} from 'ethers';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    ChevronDown,
    ExternalLink,
    Flame,
    Loader2,
    LockKeyhole,
    RefreshCw,
} from 'lucide-vue-next';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import TokenIcon from '@/components/TokenIcon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
    formatRawTokenAmount,
    rawTokenAmount,
    useSolanaStaking,
} from '@/composables/useSolanaStaking';
import type {
    SolanaStakingConfig,
    SolanaStakingPosition,
} from '@/composables/useSolanaStaking';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useWallet } from '@/composables/useWallet';
import { CYBER_SOL_ADDRESS, WCYBER_ADDRESS } from '@/lib/cyberiaTokens';
import {
    CYBERIA_CHAIN_ID,
    cyberiaReadRpcUrl,
    ensureCyberiaNetwork,
} from '@/lib/evmChains';
import { formatUsd } from '@/lib/tokenFormat';
import { login as walletLogin } from '@/routes/wallet';

const props = defineProps<{
    evm: { masterchef: string; wcyber: string; ash: string };
    solana: SolanaStakingConfig;
    position: SolanaStakingPosition | null;
}>();

const page = usePage();
const authUser = computed(
    () =>
        page.props.auth?.user as
            | {
                  wallet_address?: string | null;
                  solana_wallet_address?: string | null;
              }
            | undefined,
);
const wallet = useWallet();
const solanaWallet = useSolanaWallet();
const solanaStaking = useSolanaStaking();
solanaStaking.setInitialState(props.solana, props.position);

// Cyberia targets ~1s blocks, so daily emission ≈ rewardPerBlock × 86 400.
const BLOCKS_PER_DAY = 86400n;
const DAYS_PER_YEAR = 365;
// Live pending ticker: extrapolate accrual every second, re-anchor to the
// on-chain pendingReward every 15 s so drift never accumulates.
const PENDING_TICK_MS = 1000;
const PENDING_REFRESH_MS = 15000;
// Native CYBER left untouched by MAX so the wrap + stake txs can pay for gas.
const GAS_RESERVE = parseUnits('0.02', 18);
const EXPLORER = 'https://explorer.cyberia.church';
const SOLSCAN = 'https://solscan.io/tx/';
const DEX_URL = 'https://swap.cyberia.church';

// Ritual factory — prices tokens in CYBER via their WCYBER pools so each solo
// pool can quote TVL and APY without an off-chain indexer (mirrors Farm.vue).
const FACTORY = '0xB0aC30907c04b61F1482e62eA66eF4562a690917';
const USDC_ADDRESS = '0xdc25597B19799010047F17e9591EFE08EFd40077';
const USDT_ADDRESS = '0x94845aF24a3E431593A2b941b2b31836dE45185D';
const FACTORY_ABI = [
    'function getPair(address,address) view returns (address)',
];

const MASTERCHEF_ABI = [
    'function poolLength() view returns (uint256)',
    'function poolInfo(uint256) view returns (address lpToken, uint256 allocPoint, uint256 lastRewardBlock, uint256 accRewardPerShare)',
    'function userInfo(uint256, address) view returns (uint256 amount, uint256 rewardDebt)',
    'function pendingReward(uint256, address) view returns (uint256)',
    'function totalAllocPoint() view returns (uint256)',
    'function rewardPerBlock() view returns (uint256)',
    'function rewardToken() view returns (address)',
    'function deposit(uint256 pid, uint256 amount)',
    'function withdraw(uint256 pid, uint256 amount)',
    'function emergencyWithdraw(uint256 pid)',
];

const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
];

// A V2 pair exposes token0/token1; a single-asset staking token does not,
// which is how solo pools are told apart from LP farms.
const PAIR_ABI = [
    'function token0() view returns (address)',
    'function token1() view returns (address)',
    'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32)',
];

const WCYBER_ABI = [
    ...ERC20_ABI,
    'function deposit() payable',
    'function withdraw(uint256)',
];

type SoloPool = {
    pid: number;
    token: string;
    symbol: string;
    decimals: number;
    // WCYBER pool: the UI stakes native CYBER, wrapping/unwrapping around the
    // MasterChef transactions automatically.
    isNative: boolean;
    allocPoint: bigint;
    staked: bigint;
    pending: bigint;
    // Snapshot time + per-second accrual so the UI can tick pending between reads.
    pendingFetchedAt: number;
    pendingPerSec: bigint;
    // For the native pool this includes the wallet's unwrapped CYBER.
    walletBalance: bigint;
    allowance: bigint;
    totalStaked: bigint;
    // Priced via WCYBER pools; null when a token has no CYBER route yet.
    tvlCyber: number | null;
    tvlUsd: number | null;
    userValueUsd: number | null;
    apyPct: number | null;
};

const readProvider = new JsonRpcProvider(
    cyberiaReadRpcUrl(),
    {
        chainId: CYBERIA_CHAIN_ID,
        name: 'cyberia',
    },
    {
        // Cyberia RPC accepts at most 20 requests per JSON-RPC batch.
        batchMaxCount: 20,
    },
);

const pools = ref<SoloPool[]>([]);
const totalAllocPoint = ref<bigint>(0n);
const rewardPerBlock = ref<bigint>(0n);
const rewardSymbol = ref('ASH');
const rewardDecimals = ref(18);

const loading = ref(true);
const status = ref<string | null>(null);
const error = ref<string | null>(null);

const stakeInput = reactive<Record<number, string>>({});
const unstakeInput = reactive<Record<number, string>>({});
const busy = reactive<Record<string, boolean>>({});
// Pool rows are collapsed by default; the stake/unstake controls live in the
// expandable panel under each row.
const expandedPools = reactive<Record<number, boolean>>({});

const toggleExpanded = (pid: number): void => {
    expandedPools[pid] = !expandedPools[pid];
};

const hasWallet = computed(() => !!wallet.address.value);
const connecting = ref(false);
const hasNativePool = computed(() => pools.value.some((pool) => pool.isNative));

// Clock driving the live pending counters (Cyberia mints ~1 block/second).
const nowMs = ref(Date.now());

const livePending = (pool: SoloPool): bigint =>
    pool.pending +
    (pool.pendingPerSec *
        BigInt(Math.max(0, nowMs.value - pool.pendingFetchedAt))) /
        1000n;

const isBusy = (pid: number, action: string): boolean =>
    busy[`${pid}:${action}`] ?? false;

const shortAddr = (a: string): string => `${a.slice(0, 6)}…${a.slice(-4)}`;

// Trim a formatUnits string to `digits` decimals without scientific notation.
const fmt = (v: bigint, decimals: number, digits = 4): string => {
    const s = formatUnits(v, decimals);
    const [int, dec = ''] = s.split('.');

    return dec ? `${int}.${dec.slice(0, digits)}` : int;
};

// Preserve meaningful digits for tiny balances instead of rendering a
// non-zero value such as 0.0000000054945825 as 0.0000.
const fmtTokenAmount = (value: bigint, decimals: number): string => {
    if (value === 0n) {
        return '0';
    }

    const [integer, rawFraction = ''] = formatUnits(value, decimals).split('.');
    const fraction = rawFraction.replace(/0+$/, '');

    if (!fraction) {
        return integer;
    }

    if (integer !== '0') {
        const visible = fraction.slice(0, 6).replace(/0+$/, '');

        return visible ? `${integer}.${visible}` : integer;
    }

    const firstSignificant = fraction.search(/[1-9]/);
    const visible = fraction
        .slice(0, Math.min(fraction.length, firstSignificant + 8))
        .replace(/0+$/, '');

    return `0.${visible}`;
};

const parseAmt = (v: unknown, decimals: number): bigint =>
    parseUnits(String(v ?? '').trim() || '0', decimals);

const dailyEmission = (pool: SoloPool): bigint => {
    if (totalAllocPoint.value === 0n) {
        return 0n;
    }

    return (
        (rewardPerBlock.value * BLOCKS_PER_DAY * pool.allocPoint) /
        totalAllocPoint.value
    );
};

const poolWeight = (pool: SoloPool): number => {
    if (totalAllocPoint.value === 0n) {
        return 0;
    }

    return Number((pool.allocPoint * 10000n) / totalAllocPoint.value) / 100;
};

// Metadata + price caches: pools share tokens, so each address is fetched once
// per page load no matter how many pools use it.
const symbolCache = new Map<string, Promise<string>>();
const decimalsCache = new Map<string, Promise<number>>();
const priceCache = new Map<string, Promise<number | null>>();

const factory = new Contract(FACTORY, FACTORY_ABI, readProvider);

const displaySymbol = (symbol: string): string =>
    symbol.toUpperCase() === 'WCYBER' ? 'CYBER' : symbol;

const symbolOf = (addr: string): Promise<string> => {
    const key = addr.toLowerCase();
    let p = symbolCache.get(key);

    if (!p) {
        p = (
            new Contract(
                addr,
                ERC20_ABI,
                readProvider,
            ).symbol() as Promise<string>
        )
            .then(displaySymbol)
            .catch(() => shortAddr(addr));
        symbolCache.set(key, p);
    }

    return p;
};

const decimalsOf = (addr: string): Promise<number> => {
    const key = addr.toLowerCase();
    let p = decimalsCache.get(key);

    if (!p) {
        p = (
            new Contract(
                addr,
                ERC20_ABI,
                readProvider,
            ).decimals() as Promise<bigint>
        )
            .then((d) => Number(d))
            .catch(() => 18);
        decimalsCache.set(key, p);
    }

    return p;
};

// Whole-unit reserves of the (token, quote) pool, or null when it doesn't exist.
const pairReserves = async (
    token: string,
    quote: string,
): Promise<{ token: number; quote: number } | null> => {
    try {
        const pairAddr = (await factory.getPair(token, quote)) as string;

        if (!pairAddr || /^0x0+$/.test(pairAddr)) {
            return null;
        }

        const pair = new Contract(pairAddr, PAIR_ABI, readProvider);
        const [t0, reserves, decT, decQ] = await Promise.all([
            pair.token0() as Promise<string>,
            pair.getReserves(),
            decimalsOf(token),
            decimalsOf(quote),
        ]);
        const tokenIs0 = String(t0).toLowerCase() === token.toLowerCase();
        const rT = tokenIs0 ? (reserves[0] as bigint) : (reserves[1] as bigint);
        const rQ = tokenIs0 ? (reserves[1] as bigint) : (reserves[0] as bigint);
        const tWhole = Number(formatUnits(rT, decT));
        const qWhole = Number(formatUnits(rQ, decQ));

        return tWhole > 0 && qWhole > 0
            ? { token: tWhole, quote: qWhole }
            : null;
    } catch {
        return null;
    }
};

// Spot price of `addr` in native CYBER: direct WCYBER pool first, then one hop
// through CYBER.sol. Dust pools (< 0.001 CYBER a side) are ignored as noise.
const priceInCyber = (addr: string): Promise<number | null> => {
    const key = addr.toLowerCase();

    if (key === WCYBER_ADDRESS.toLowerCase()) {
        return Promise.resolve(1);
    }

    let p = priceCache.get(key);

    if (!p) {
        p = (async () => {
            const direct = await pairReserves(addr, WCYBER_ADDRESS);

            if (direct && direct.quote >= 0.001) {
                return direct.quote / direct.token;
            }

            if (key !== CYBER_SOL_ADDRESS.toLowerCase()) {
                const [viaSol, solPrice] = await Promise.all([
                    pairReserves(addr, CYBER_SOL_ADDRESS),
                    priceInCyber(CYBER_SOL_ADDRESS),
                ]);

                if (viaSol && solPrice !== null && viaSol.quote >= 0.001) {
                    return (viaSol.quote / viaSol.token) * solPrice;
                }
            }

            return null;
        })();
        priceCache.set(key, p);
    }

    return p;
};

const loadCyberUsdPrice = async (): Promise<number | null> => {
    const markets = await Promise.all(
        [USDC_ADDRESS, USDT_ADDRESS].map((stable) =>
            pairReserves(WCYBER_ADDRESS, stable),
        ),
    );
    const priced = markets.filter(
        (market): market is { token: number; quote: number } => market !== null,
    );

    if (priced.length === 0) {
        return null;
    }

    const cyberLiquidity = priced.reduce(
        (sum, market) => sum + market.token,
        0,
    );
    const usdLiquidity = priced.reduce((sum, market) => sum + market.quote, 0);

    return cyberLiquidity > 0 ? usdLiquidity / cyberLiquidity : null;
};

async function loadState(): Promise<void> {
    loading.value = true;
    error.value = null;
    // Symbols/decimals are immutable; prices are not — refetch them per load.
    priceCache.clear();

    try {
        const chef = new Contract(
            props.evm.masterchef,
            MASTERCHEF_ABI,
            readProvider,
        );
        const me = wallet.address.value;

        const [len, totAlloc, rpb, rewardAddr] = await Promise.all([
            chef.poolLength() as Promise<bigint>,
            chef.totalAllocPoint() as Promise<bigint>,
            chef.rewardPerBlock() as Promise<bigint>,
            chef.rewardToken() as Promise<string>,
        ]);
        totalAllocPoint.value = totAlloc;
        rewardPerBlock.value = rpb;

        const [rSym, rDec, rewardPrice, cyberUsd] = await Promise.all([
            symbolOf(rewardAddr),
            decimalsOf(rewardAddr),
            priceInCyber(rewardAddr),
            loadCyberUsdPrice(),
        ]);
        rewardSymbol.value = rSym;
        rewardDecimals.value = rDec;

        const rewardPerYearWhole =
            Number(formatUnits(rpb * BLOCKS_PER_DAY, rDec)) * DAYS_PER_YEAR;

        // Pool headers first, so retired pools (allocPoint 0) and LP farms are
        // excluded before token metadata and pricing are requested.
        const poolHeaders = await Promise.all(
            Array.from({ length: Number(len) }, async (_, pid) => ({
                pid,
                info: await chef.poolInfo(pid),
            })),
        );
        const activeHeaders = poolHeaders.filter(
            ({ info }) => (info.allocPoint as bigint) > 0n,
        );
        const soloHeaders = (
            await Promise.all(
                activeHeaders.map(async (header) => {
                    const pair = new Contract(
                        header.info.lpToken as string,
                        PAIR_ABI,
                        readProvider,
                    );
                    const isPair = await (pair.token0() as Promise<string>)
                        .then(() => true)
                        .catch(() => false);

                    return isPair ? null : header;
                }),
            )
        ).filter((header) => header !== null);

        const loadPool = async (
            pid: number,
            info: (typeof soloHeaders)[number]['info'],
        ): Promise<SoloPool> => {
            const token = info.lpToken as string;
            const allocPoint = info.allocPoint as bigint;
            const isNative =
                token.toLowerCase() === props.evm.wcyber.toLowerCase();
            const erc20 = new Contract(token, ERC20_ABI, readProvider);

            const [symbol, decimals, totalStaked] = await Promise.all([
                symbolOf(token),
                decimalsOf(token),
                erc20.balanceOf(props.evm.masterchef) as Promise<bigint>,
            ]);

            let staked = 0n;
            let pending = 0n;
            let walletBalance = 0n;
            let allowance = 0n;

            if (me) {
                const [u, p, bal, native, allow] = await Promise.all([
                    chef.userInfo(pid, me),
                    chef.pendingReward(pid, me) as Promise<bigint>,
                    erc20.balanceOf(me) as Promise<bigint>,
                    isNative
                        ? readProvider.getBalance(me)
                        : Promise.resolve(0n),
                    erc20.allowance(
                        me,
                        props.evm.masterchef,
                    ) as Promise<bigint>,
                ]);
                staked = u.amount as bigint;
                pending = p;
                walletBalance = bal + native;
                allowance = allow;
            }

            // User accrual per second (~1 block/s): the user's stake share of
            // this pool's slice of the per-block emission.
            const pendingPerSec =
                staked > 0n && totAlloc > 0n && totalStaked > 0n
                    ? (rpb * allocPoint * staked) / (totAlloc * totalStaked)
                    : 0n;

            const price = await priceInCyber(token);
            const stakedWhole = Number(formatUnits(totalStaked, decimals));
            const tvlCyber = price === null ? null : stakedWhole * price;
            const tvlUsd =
                tvlCyber !== null && cyberUsd !== null
                    ? tvlCyber * cyberUsd
                    : null;
            const userShare =
                totalStaked > 0n
                    ? Number(formatUnits(staked, decimals)) / stakedWhole
                    : 0;
            const userValueUsd =
                tvlUsd !== null && totalStaked > 0n
                    ? tvlUsd * userShare
                    : staked === 0n
                      ? 0
                      : null;
            let apyPct: number | null = null;

            if (
                rewardPrice !== null &&
                tvlCyber !== null &&
                tvlCyber > 0 &&
                totAlloc > 0n
            ) {
                const yearlyRewardCyber =
                    rewardPerYearWhole *
                    (Number(allocPoint) / Number(totAlloc)) *
                    rewardPrice;
                apyPct = (yearlyRewardCyber / tvlCyber) * 100;
            }

            return {
                pid,
                token,
                symbol,
                decimals,
                isNative,
                allocPoint,
                staked,
                pending,
                pendingFetchedAt: Date.now(),
                pendingPerSec,
                walletBalance,
                allowance,
                totalStaked,
                tvlCyber,
                tvlUsd,
                userValueUsd,
                apyPct,
            };
        };

        const loaded = await Promise.all(
            soloHeaders.map(({ pid, info }) => loadPool(pid, info)),
        );
        // Native CYBER leads the list; the rest keep MasterChef pid order.
        pools.value = loaded.sort(
            (a, b) => Number(b.isNative) - Number(a.isNative) || a.pid - b.pid,
        );
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        loading.value = false;
    }
}

// Lightweight live refresh, batched by ethers and without the loading state:
// re-anchors pending for the tickers and picks up balance/stake changes made
// outside this page.
async function refreshLive(): Promise<void> {
    const me = wallet.address.value;

    if (!me || loading.value || pools.value.length === 0 || document.hidden) {
        return;
    }

    try {
        const chef = new Contract(
            props.evm.masterchef,
            MASTERCHEF_ABI,
            readProvider,
        );
        const updates = await Promise.all(
            pools.value.map(async (pool) => {
                const erc20 = new Contract(pool.token, ERC20_ABI, readProvider);
                const [pending, user, balance, native, allowance, totalStaked] =
                    await Promise.all([
                        chef.pendingReward(pool.pid, me) as Promise<bigint>,
                        chef.userInfo(pool.pid, me),
                        erc20.balanceOf(me) as Promise<bigint>,
                        pool.isNative
                            ? readProvider.getBalance(me)
                            : Promise.resolve(0n),
                        erc20.allowance(
                            me,
                            props.evm.masterchef,
                        ) as Promise<bigint>,
                        erc20.balanceOf(
                            props.evm.masterchef,
                        ) as Promise<bigint>,
                    ]);

                return {
                    pool,
                    pending,
                    staked: user.amount as bigint,
                    walletBalance: balance + native,
                    allowance,
                    totalStaked,
                };
            }),
        );
        const fetchedAt = Date.now();

        for (const u of updates) {
            u.pool.pending = u.pending;
            u.pool.pendingFetchedAt = fetchedAt;
            u.pool.staked = u.staked;
            u.pool.walletBalance = u.walletBalance;
            u.pool.allowance = u.allowance;
            u.pool.totalStaked = u.totalStaked;
            u.pool.pendingPerSec =
                u.staked > 0n &&
                totalAllocPoint.value > 0n &&
                u.totalStaked > 0n
                    ? (rewardPerBlock.value * u.pool.allocPoint * u.staked) /
                      (totalAllocPoint.value * u.totalStaked)
                    : 0n;
        }
    } catch {
        // Transient RPC failure — extrapolation keeps ticking until next poll.
    }
}

async function connectWallet(): Promise<void> {
    error.value = null;
    connecting.value = true;

    try {
        await wallet.connect();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        connecting.value = false;
    }
}

// Run an on-chain action with shared busy/status/error handling, then refresh.
async function run(
    pid: number,
    action: string,
    fn: (
        signer: Awaited<
            ReturnType<
                Awaited<ReturnType<typeof ensureCyberiaNetwork>>['getSigner']
            >
        >,
    ) => Promise<void>,
): Promise<void> {
    const key = `${pid}:${action}`;
    error.value = null;
    busy[key] = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        await fn(signer);
        await loadState();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        busy[key] = false;
    }
}

async function stake(pool: SoloPool): Promise<void> {
    let amount: bigint;

    try {
        amount = parseAmt(stakeInput[pool.pid], pool.decimals);
    } catch {
        error.value = 'Invalid amount';

        return;
    }

    if (amount <= 0n) {
        error.value = 'Enter an amount to stake';

        return;
    }

    if (amount > pool.walletBalance) {
        error.value = `Insufficient ${pool.symbol} balance`;

        return;
    }

    await run(pool.pid, 'stake', async (signer) => {
        const address = await signer.getAddress();
        const token = new Contract(
            pool.token,
            pool.isNative ? WCYBER_ABI : ERC20_ABI,
            signer,
        );

        if (pool.isNative) {
            const wrapped = (await token.balanceOf(address)) as bigint;

            if (wrapped < amount) {
                status.value = 'Confirm wrapping native CYBER…';
                const wtx = await token.deposit({ value: amount - wrapped });
                await wtx.wait();
            }
        }

        if (pool.allowance < amount) {
            status.value = `Approving ${pool.symbol}…`;
            const atx = await token.approve(props.evm.masterchef, MaxUint256);
            await atx.wait();
        }

        status.value = 'Confirm the stake in your wallet…';
        const chef = new Contract(props.evm.masterchef, MASTERCHEF_ABI, signer);
        const tx = await chef.deposit(pool.pid, amount);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Staked ${fmt(amount, pool.decimals)} ${pool.symbol}.`;
        stakeInput[pool.pid] = '';
    });
}

async function unstake(pool: SoloPool): Promise<void> {
    let amount: bigint;

    try {
        amount = parseAmt(unstakeInput[pool.pid], pool.decimals);
    } catch {
        error.value = 'Invalid amount';

        return;
    }

    if (amount <= 0n) {
        error.value = 'Enter an amount to unstake';

        return;
    }

    if (amount > pool.staked) {
        error.value = 'Amount exceeds your stake';

        return;
    }

    await run(pool.pid, 'unstake', async (signer) => {
        status.value = 'Confirm the unstake in your wallet…';
        const chef = new Contract(props.evm.masterchef, MASTERCHEF_ABI, signer);
        const tx = await chef.withdraw(pool.pid, amount);
        status.value = 'Waiting for block…';
        await tx.wait();

        if (pool.isNative) {
            status.value = 'Confirm unwrapping to native CYBER…';
            const wrapped = new Contract(pool.token, WCYBER_ABI, signer);
            const utx = await wrapped.withdraw(amount);
            await utx.wait();
        }

        status.value = `Unstaked ${fmt(amount, pool.decimals)} ${pool.symbol} (rewards harvested).`;
        unstakeInput[pool.pid] = '';
    });
}

// Harvest only: depositing 0 pays out pending rewards without moving the stake.
async function harvest(pool: SoloPool): Promise<void> {
    const expected = livePending(pool);

    await run(pool.pid, 'harvest', async (signer) => {
        status.value = 'Confirm the harvest in your wallet…';
        const chef = new Contract(props.evm.masterchef, MASTERCHEF_ABI, signer);
        const tx = await chef.deposit(pool.pid, 0n);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Harvested ≈ ${fmt(expected, rewardDecimals.value)} ${rewardSymbol.value} from ${pool.symbol}.`;
    });
}

// Emergency: pull the stake out and forfeit pending rewards. Last resort.
async function emergencyUnstake(pool: SoloPool): Promise<void> {
    if (
        typeof window !== 'undefined' &&
        !window.confirm(
            `Emergency unstake forfeits your pending ${rewardSymbol.value} rewards from ${pool.symbol}. Continue?`,
        )
    ) {
        return;
    }

    await run(pool.pid, 'emergency', async (signer) => {
        status.value = 'Confirm the emergency unstake…';
        const chef = new Contract(props.evm.masterchef, MASTERCHEF_ABI, signer);
        const tx = await chef.emergencyWithdraw(pool.pid);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Emergency-unstaked ${pool.symbol}.`;
    });
}

const setStakeMax = (pool: SoloPool): void => {
    // Native pool: keep a little unwrapped CYBER so the transactions have gas.
    const max = pool.isNative
        ? pool.walletBalance > GAS_RESERVE
            ? pool.walletBalance - GAS_RESERVE
            : 0n
        : pool.walletBalance;
    stakeInput[pool.pid] = max > 0n ? formatUnits(max, pool.decimals) : '';
};

const setUnstakeMax = (pool: SoloPool): void => {
    unstakeInput[pool.pid] =
        pool.staked > 0n ? formatUnits(pool.staked, pool.decimals) : '';
};

const explorerUrl = (addr: string): string => `${EXPLORER}/address/${addr}`;

const fmtApy = (v: number): string =>
    v >= 1000 ? Math.round(v).toLocaleString() : v.toFixed(v >= 100 ? 0 : 1);

const fmtCyber = (v: number): string =>
    v >= 1000 ? Math.round(v).toLocaleString() : v.toFixed(2);
const fmtUsdValue = (v: number): string => (v === 0 ? '$0.00' : formatUsd(v));

const solanaStakeAmount = ref('');
const solanaWithdrawAmount = ref('');
const solanaWalletBalance = ref('0');
const solanaStatus = ref<string | null>(null);
const linkedSolanaAddress = computed(
    () => authUser.value?.solana_wallet_address ?? null,
);
const activeSolanaAddress = computed(() => solanaWallet.address.value);
const solanaWalletMatches = computed(
    () =>
        !!linkedSolanaAddress.value &&
        activeSolanaAddress.value === linkedSolanaAddress.value,
);

const loadSolanaBalance = async (): Promise<void> => {
    const address = activeSolanaAddress.value;
    const config = solanaStaking.config.value;

    if (!address || !config?.rpc_url) {
        solanaWalletBalance.value = '0';

        return;
    }

    try {
        const program =
            config.token_program === 'token-2022'
                ? TOKEN_2022_PROGRAM_ID
                : TOKEN_PROGRAM_ID;
        const mint = new PublicKey(config.cyber_sol_mint);
        const owner = new PublicKey(address);
        const ata = await getAssociatedTokenAddress(
            mint,
            owner,
            false,
            program,
        );
        const account = await getAccount(
            new Connection(config.rpc_url, 'confirmed'),
            ata,
            'confirmed',
            program,
        );
        solanaWalletBalance.value = account.amount.toString();
    } catch {
        solanaWalletBalance.value = '0';
    }
};

const connectSolana = async (): Promise<void> => {
    await solanaWallet.connect();
    await loadSolanaBalance();
};

const depositCyberSol = async (): Promise<void> => {
    const config = solanaStaking.config.value;

    if (!config) {
        return;
    }

    try {
        const amountRaw = rawTokenAmount(
            solanaStakeAmount.value,
            config.cyber_sol_decimals,
        );
        const provider = solanaWallet.getTransactionProvider(config.cluster);

        if (!provider || !solanaWalletMatches.value) {
            throw new Error('Connect the Solana wallet linked to this account');
        }

        solanaStatus.value =
            'Confirm the CYBER.sol transfer to the staking treasury…';
        await solanaStaking.deposit(provider, amountRaw);
        solanaStakeAmount.value = '';
        solanaStatus.value = 'CYBER.sol stake confirmed.';
        await loadSolanaBalance();
    } catch (cause) {
        solanaStatus.value = null;
        solanaStaking.error.value =
            cause instanceof Error ? cause.message : String(cause);
    }
};

const withdrawCyberSol = async (): Promise<void> => {
    const config = solanaStaking.config.value;

    if (!config) {
        return;
    }

    try {
        await solanaStaking.withdraw(
            rawTokenAmount(
                solanaWithdrawAmount.value,
                config.cyber_sol_decimals,
            ),
        );
        solanaWithdrawAmount.value = '';
        solanaStatus.value = 'Withdrawal processed or reserved for review.';
        await loadSolanaBalance();
    } catch {
        solanaStatus.value = null;
    }
};

const claimSolanaRewards = async (): Promise<void> => {
    try {
        await solanaStaking.claim();
        solanaStatus.value = 'ASH payout processed or reserved for review.';
    } catch {
        solanaStatus.value = null;
    }
};

const resumeDeposit = async (): Promise<void> => {
    try {
        await solanaStaking.confirmPendingDeposit();
        solanaStatus.value = 'Previous deposit confirmed.';
        await loadSolanaBalance();
    } catch {
        solanaStatus.value = null;
    }
};

const fmtSol = (raw: string): string =>
    formatRawTokenAmount(
        raw,
        solanaStaking.config.value?.cyber_sol_decimals ?? 6,
    );
const fmtAsh = (raw: string): string =>
    formatRawTokenAmount(raw, solanaStaking.config.value?.ash_decimals ?? 18);

let watchWalletChanges = false;
let tickTimer: ReturnType<typeof setInterval> | undefined;
let liveTimer: ReturnType<typeof setInterval> | undefined;

// Returning to the tab refreshes balances/pending immediately instead of
// waiting out the poll interval.
const onTabVisible = (): void => {
    if (!document.hidden) {
        void refreshLive();
    }
};

watch(activeSolanaAddress, () => void loadSolanaBalance());
watch(
    () => wallet.address.value,
    () => {
        if (watchWalletChanges) {
            void loadState();
        }
    },
);

onMounted(async () => {
    tickTimer = setInterval(() => {
        nowMs.value = Date.now();
    }, PENDING_TICK_MS);
    liveTimer = setInterval(() => void refreshLive(), PENDING_REFRESH_MS);
    window.addEventListener('focus', onTabVisible);
    document.addEventListener('visibilitychange', onTabVisible);

    await Promise.all([
        wallet.restore(authUser.value?.wallet_address ?? null),
        solanaWallet.restore(linkedSolanaAddress.value),
    ]);
    await Promise.all([loadState(), loadSolanaBalance()]);
    watchWalletChanges = true;
});

onBeforeUnmount(() => {
    clearInterval(tickTimer);
    clearInterval(liveTimer);
    window.removeEventListener('focus', onTabVisible);
    document.removeEventListener('visibilitychange', onTabVisible);
});
</script>

<template>
    <Head title="Staking · solo pools" />

    <main class="relative overflow-hidden">
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 -top-40 -z-10 flex justify-center"
        >
            <div
                class="h-[28rem] w-[60rem] max-w-full rounded-full bg-gradient-to-tr from-violet-400/20 via-cyan-300/10 to-emerald-400/20 blur-3xl"
            ></div>
        </div>

        <div class="mx-auto max-w-5xl space-y-10 px-4 py-12 sm:py-16">
            <!-- HERO -->
            <section class="space-y-4 text-center">
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-border bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground backdrop-blur"
                >
                    <LockKeyhole class="h-3.5 w-3.5 text-violet-500" />
                    Single-token staking
                </span>
                <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
                    Stake one token, earn
                    <span
                        class="bg-gradient-to-r from-violet-400 via-cyan-400 to-emerald-400 bg-clip-text text-transparent"
                        >{{ rewardSymbol }}</span
                    >
                </h1>
                <p class="mx-auto max-w-2xl text-base text-muted-foreground">
                    Solo pools from the Cyberia farm — no liquidity pair
                    required. Rewards accrue every block and can be harvested
                    any time.
                </p>
            </section>

            <!-- GLOBAL STATS -->
            <section
                class="grid gap-4 rounded-2xl border border-border bg-card/50 p-5 sm:grid-cols-3"
            >
                <div>
                    <p class="text-xs text-muted-foreground">Solo pools</p>
                    <p class="font-mono text-lg">{{ pools.length || '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Emission</p>
                    <p class="font-mono text-lg">
                        {{
                            fmt(
                                rewardPerBlock * BLOCKS_PER_DAY,
                                rewardDecimals,
                                0,
                            )
                        }}
                        {{ rewardSymbol }}/day
                    </p>
                </div>
                <div
                    class="flex items-center justify-between gap-2 sm:justify-end"
                >
                    <a
                        :href="explorerUrl(evm.masterchef)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 font-mono text-xs text-muted-foreground hover:text-foreground"
                    >
                        {{ shortAddr(evm.masterchef) }}
                        <ExternalLink class="h-3 w-3" />
                    </a>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground transition hover:text-foreground disabled:opacity-50"
                        :disabled="loading"
                        @click="loadState"
                    >
                        <RefreshCw
                            class="h-3.5 w-3.5"
                            :class="loading && 'animate-spin'"
                        />
                        Refresh
                    </button>
                </div>
            </section>

            <!-- CONNECT -->
            <div v-if="!hasWallet" class="flex justify-center">
                <Button
                    class="h-12 rounded-xl px-8 text-base"
                    :disabled="connecting"
                    @click="connectWallet"
                >
                    <Loader2
                        v-if="connecting"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    Connect wallet
                </Button>
            </div>

            <!-- status / error -->
            <p
                v-if="status"
                class="rounded-lg bg-muted px-3 py-2 text-center text-xs text-muted-foreground"
            >
                {{ status }}
            </p>
            <p
                v-if="error"
                class="rounded-lg bg-destructive/10 px-3 py-2 text-center text-xs text-destructive"
            >
                {{ error }}
            </p>

            <section
                v-if="loading && pools.length === 0"
                aria-label="Loading solo pools"
                class="space-y-4"
            >
                <div
                    class="flex items-center justify-center gap-2 text-sm text-muted-foreground"
                >
                    <Loader2 class="h-4 w-4 animate-spin" />
                    Loading solo pools…
                </div>
                <div
                    class="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card"
                >
                    <div
                        v-for="index in 4"
                        :key="index"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <Skeleton class="h-6 w-6 rounded-full" />
                        <Skeleton class="h-4 w-24" />
                        <Skeleton class="ml-auto h-4 w-40" />
                    </div>
                </div>
            </section>

            <!-- SOLO POOLS: one compact row per pool, controls expand below -->
            <section
                v-if="pools.length > 0"
                class="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card"
            >
                <!-- column header (sm+) -->
                <div
                    class="hidden items-center gap-3 bg-muted/30 px-4 py-2 text-[10px] font-medium tracking-wide text-muted-foreground uppercase sm:flex"
                >
                    <span class="min-w-0 flex-1">Pool</span>
                    <span class="w-20 shrink-0">APY</span>
                    <span class="w-24 shrink-0">TVL</span>
                    <span class="hidden w-24 shrink-0 md:block">
                        Your stake
                    </span>
                    <span class="hidden w-28 shrink-0 md:block">
                        Pending {{ rewardSymbol }}
                    </span>
                    <span class="w-28 shrink-0"></span>
                </div>

                <div v-for="pool in pools" :key="pool.pid">
                    <div
                        role="button"
                        tabindex="0"
                        :aria-expanded="Boolean(expandedPools[pool.pid])"
                        class="flex cursor-pointer items-center gap-3 px-4 py-3 transition hover:bg-muted/40"
                        @click="toggleExpanded(pool.pid)"
                        @keydown.enter.prevent="toggleExpanded(pool.pid)"
                        @keydown.space.prevent="toggleExpanded(pool.pid)"
                    >
                        <span
                            class="flex min-w-0 flex-1 items-center gap-2 font-semibold"
                        >
                            <TokenIcon :symbol="pool.symbol" :size="24" ring />
                            <span class="truncate">{{ pool.symbol }}</span>
                        </span>

                        <span class="w-20 shrink-0">
                            <span
                                v-if="pool.apyPct !== null"
                                class="font-mono text-sm font-medium text-emerald-500"
                            >
                                {{ fmtApy(pool.apyPct) }}%
                            </span>
                            <span
                                v-else
                                class="text-sm text-muted-foreground"
                                title="No CYBER price route for this token yet"
                            >
                                —
                            </span>
                        </span>

                        <span
                            class="hidden w-24 shrink-0 font-mono text-sm sm:block"
                        >
                            <template v-if="pool.tvlUsd !== null">
                                {{ fmtUsdValue(pool.tvlUsd) }}
                            </template>
                            <template v-else>
                                {{
                                    fmtTokenAmount(
                                        pool.totalStaked,
                                        pool.decimals,
                                    )
                                }}
                            </template>
                        </span>

                        <span
                            class="hidden w-24 shrink-0 font-mono text-sm md:block"
                        >
                            {{
                                hasWallet
                                    ? fmtTokenAmount(pool.staked, pool.decimals)
                                    : '—'
                            }}
                        </span>

                        <span
                            class="hidden w-28 shrink-0 font-mono text-xs md:block"
                        >
                            {{
                                hasWallet
                                    ? fmt(livePending(pool), rewardDecimals, 6)
                                    : '—'
                            }}
                        </span>

                        <span
                            class="flex w-28 shrink-0 items-center justify-end gap-2"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="
                                    !hasWallet ||
                                    livePending(pool) <= 0n ||
                                    isBusy(pool.pid, 'harvest')
                                "
                                @click.stop="harvest(pool)"
                            >
                                <Loader2
                                    v-if="isBusy(pool.pid, 'harvest')"
                                    class="mr-1 h-4 w-4 animate-spin"
                                />
                                Harvest
                            </Button>
                            <ChevronDown
                                class="h-4 w-4 shrink-0 text-muted-foreground transition-transform"
                                :class="expandedPools[pool.pid] && 'rotate-180'"
                            />
                        </span>
                    </div>

                    <!-- expanded panel -->
                    <div
                        v-if="expandedPools[pool.pid]"
                        class="space-y-4 border-t border-border/60 bg-background/40 px-4 py-4"
                    >
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground"
                        >
                            <a
                                :href="explorerUrl(pool.token)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 font-mono hover:text-foreground"
                            >
                                {{ shortAddr(pool.token) }}
                                <ExternalLink class="h-3 w-3" />
                            </a>
                            <span>{{ poolWeight(pool) }}% weight</span>
                            <span class="font-mono">
                                ≈
                                {{
                                    fmt(dailyEmission(pool), rewardDecimals, 0)
                                }}
                                {{ rewardSymbol }}/day
                            </span>
                            <span v-if="pool.isNative">
                                Native CYBER is wrapped and unwrapped
                                automatically.
                            </span>
                        </div>

                        <div
                            class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3"
                        >
                            <div
                                class="rounded-xl border border-border bg-background/50 p-3"
                            >
                                <p class="text-xs text-muted-foreground">
                                    Total staked
                                </p>
                                <p class="font-mono">
                                    {{
                                        fmtTokenAmount(
                                            pool.totalStaked,
                                            pool.decimals,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="pool.tvlCyber !== null"
                                    class="font-mono text-[11px] text-muted-foreground"
                                >
                                    ≈ {{ fmtCyber(pool.tvlCyber) }} CYBER
                                </p>
                                <p
                                    v-if="pool.tvlUsd !== null"
                                    class="font-mono text-xs font-medium"
                                >
                                    {{ fmtUsdValue(pool.tvlUsd) }}
                                </p>
                            </div>
                            <div
                                class="rounded-xl border border-border bg-background/50 p-3"
                            >
                                <p class="text-xs text-muted-foreground">
                                    Your stake
                                </p>
                                <p class="font-mono">
                                    {{
                                        hasWallet
                                            ? fmtTokenAmount(
                                                  pool.staked,
                                                  pool.decimals,
                                              )
                                            : '—'
                                    }}
                                </p>
                                <p
                                    v-if="
                                        hasWallet && pool.userValueUsd !== null
                                    "
                                    class="font-mono text-xs font-medium"
                                >
                                    {{ fmtUsdValue(pool.userValueUsd) }}
                                </p>
                            </div>
                            <div
                                class="col-span-2 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3 sm:col-span-1"
                            >
                                <p class="text-xs text-muted-foreground">
                                    Pending {{ rewardSymbol }}
                                </p>
                                <p class="font-mono">
                                    {{
                                        hasWallet
                                            ? fmt(
                                                  livePending(pool),
                                                  rewardDecimals,
                                                  6,
                                              )
                                            : '—'
                                    }}
                                </p>
                            </div>
                        </div>

                        <div v-if="hasWallet" class="grid gap-3 sm:grid-cols-2">
                            <!-- stake -->
                            <div class="space-y-1">
                                <div
                                    class="flex items-center justify-between text-xs text-muted-foreground"
                                >
                                    <span>Stake</span>
                                    <span class="font-mono">
                                        Balance:
                                        {{
                                            fmtTokenAmount(
                                                pool.walletBalance,
                                                pool.decimals,
                                            )
                                        }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Input
                                        v-model="stakeInput[pool.pid]"
                                        type="number"
                                        min="0"
                                        inputmode="decimal"
                                        placeholder="0.0"
                                        class="font-mono"
                                    />
                                    <button
                                        type="button"
                                        class="rounded-md border border-border px-2 py-1 text-[11px] font-medium text-muted-foreground transition hover:border-foreground/30 hover:text-foreground"
                                        @click="setStakeMax(pool)"
                                    >
                                        MAX
                                    </button>
                                    <Button
                                        :disabled="isBusy(pool.pid, 'stake')"
                                        @click="stake(pool)"
                                    >
                                        <Loader2
                                            v-if="isBusy(pool.pid, 'stake')"
                                            class="mr-1 h-4 w-4 animate-spin"
                                        />
                                        Stake
                                    </Button>
                                </div>
                            </div>

                            <!-- unstake -->
                            <div class="space-y-1">
                                <div
                                    class="flex items-center justify-between text-xs text-muted-foreground"
                                >
                                    <span>Unstake</span>
                                    <span class="font-mono">
                                        Staked:
                                        {{
                                            fmtTokenAmount(
                                                pool.staked,
                                                pool.decimals,
                                            )
                                        }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Input
                                        v-model="unstakeInput[pool.pid]"
                                        type="number"
                                        min="0"
                                        inputmode="decimal"
                                        placeholder="0.0"
                                        class="font-mono"
                                    />
                                    <button
                                        type="button"
                                        class="rounded-md border border-border px-2 py-1 text-[11px] font-medium text-muted-foreground transition hover:border-foreground/30 hover:text-foreground"
                                        @click="setUnstakeMax(pool)"
                                    >
                                        MAX
                                    </button>
                                    <Button
                                        variant="outline"
                                        :disabled="
                                            pool.staked <= 0n ||
                                            isBusy(pool.pid, 'unstake')
                                        "
                                        @click="unstake(pool)"
                                    >
                                        <Loader2
                                            v-if="isBusy(pool.pid, 'unstake')"
                                            class="mr-1 h-4 w-4 animate-spin"
                                        />
                                        Unstake
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <!-- secondary actions -->
                        <div
                            v-if="hasWallet"
                            class="flex items-center justify-between pt-1 text-[11px]"
                        >
                            <a
                                v-if="!pool.isNative"
                                :href="DEX_URL"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
                            >
                                Get {{ pool.symbol }}
                                <ExternalLink class="h-3 w-3" />
                            </a>
                            <span v-else />
                            <button
                                type="button"
                                class="text-muted-foreground transition hover:text-destructive disabled:opacity-50"
                                :disabled="
                                    pool.staked <= 0n ||
                                    isBusy(pool.pid, 'emergency')
                                "
                                @click="emergencyUnstake(pool)"
                            >
                                Emergency unstake
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-else-if="!loading"
                class="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground"
            >
                No solo pools are live on the farm yet.
            </section>

            <p
                v-if="!loading && !hasNativePool"
                class="rounded-lg border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-center text-xs text-amber-500"
            >
                The native CYBER solo pool has not been activated on MasterChef
                yet — it will appear here automatically once added.
            </p>

            <!-- CYBER.sol CUSTODIAL -->
            <section class="space-y-5 rounded-2xl border bg-card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <TokenIcon symbol="CYBER.sol" :size="40" />
                        <div>
                            <h2 class="text-xl font-semibold">CYBER.sol</h2>
                            <p class="text-sm text-muted-foreground">
                                Custodial · Solana mainnet
                            </p>
                        </div>
                    </div>
                    <Button
                        size="sm"
                        variant="ghost"
                        :disabled="solanaStaking.busy.value"
                        @click="solanaStaking.refresh()"
                    >
                        <RefreshCw class="h-4 w-4" />
                    </Button>
                </div>

                <div
                    class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-xs text-amber-500"
                >
                    Centralized custody: CYBER.sol is transferred to the service
                    treasury. Withdrawals and ASH payouts depend on the backend
                    and its funded hot wallets.
                </div>

                <div
                    v-if="!solanaStaking.config.value?.enabled"
                    class="rounded-lg border p-3 text-sm text-muted-foreground"
                >
                    Solana staking is built but disabled until the operator sets
                    a dedicated treasury and reward rate.
                </div>
                <template v-else>
                    <div class="grid grid-cols-3 gap-3 text-sm">
                        <div class="rounded-lg bg-muted/50 p-3">
                            <div class="text-muted-foreground">Wallet</div>
                            <div class="mt-1 font-mono">
                                {{ fmtSol(solanaWalletBalance) }}
                            </div>
                        </div>
                        <div class="rounded-lg bg-muted/50 p-3">
                            <div class="text-muted-foreground">Staked</div>
                            <div class="mt-1 font-mono">
                                {{
                                    fmtSol(
                                        solanaStaking.position.value
                                            ?.principal_raw ?? '0',
                                    )
                                }}
                            </div>
                        </div>
                        <div class="rounded-lg bg-muted/50 p-3">
                            <div class="text-muted-foreground">Earned ASH</div>
                            <div class="mt-1 font-mono text-emerald-500">
                                {{
                                    fmtAsh(
                                        solanaStaking.position.value
                                            ?.accrued_ash_raw ?? '0',
                                    )
                                }}
                            </div>
                        </div>
                    </div>

                    <div class="text-xs text-muted-foreground">
                        Rate:
                        {{ solanaStaking.config.value.ash_per_cyber_per_day }}
                        ASH per CYBER.sol per day
                    </div>

                    <Link
                        v-if="!authUser"
                        :href="walletLogin().url"
                        class="block"
                    >
                        <Button class="w-full">Sign in to stake</Button>
                    </Link>
                    <Button
                        v-else-if="!solanaWalletMatches"
                        class="w-full"
                        @click="connectSolana"
                    >
                        Connect linked Solana wallet
                    </Button>
                    <template v-else>
                        <div class="flex gap-2">
                            <Input
                                v-model="solanaStakeAmount"
                                inputmode="decimal"
                                placeholder="CYBER.sol amount"
                            />
                            <Button
                                :disabled="solanaStaking.busy.value"
                                @click="depositCyberSol"
                            >
                                <ArrowDownToLine class="mr-2 h-4 w-4" /> Stake
                            </Button>
                        </div>
                        <div class="flex gap-2">
                            <Input
                                v-model="solanaWithdrawAmount"
                                inputmode="decimal"
                                placeholder="CYBER.sol to unstake"
                            />
                            <Button
                                variant="outline"
                                :disabled="
                                    solanaStaking.busy.value ||
                                    !solanaStaking.config.value
                                        .withdrawals_enabled
                                "
                                @click="withdrawCyberSol"
                            >
                                <ArrowUpFromLine class="mr-2 h-4 w-4" />
                                Unstake
                            </Button>
                        </div>
                        <Button
                            variant="secondary"
                            class="w-full"
                            :disabled="
                                solanaStaking.busy.value ||
                                !solanaStaking.config.value.claims_enabled ||
                                !authUser?.wallet_address
                            "
                            @click="claimSolanaRewards"
                        >
                            <Flame class="mr-2 h-4 w-4" /> Claim ASH on Cyberia
                        </Button>
                    </template>

                    <div
                        v-if="solanaStaking.pendingDeposit.value"
                        class="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                    >
                        <span
                            >A sent deposit still needs backend
                            confirmation.</span
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="solanaStaking.busy.value"
                            @click="resumeDeposit"
                        >
                            Retry confirmation
                        </Button>
                    </div>
                </template>

                <p v-if="solanaStatus" class="text-sm text-emerald-500">
                    {{ solanaStatus }}
                </p>
                <p
                    v-if="solanaStaking.error.value"
                    class="text-sm text-red-500"
                >
                    {{ solanaStaking.error.value }}
                </p>

                <div
                    v-if="solanaStaking.position.value?.transactions.length"
                    class="space-y-2 border-t pt-4"
                >
                    <h3 class="text-sm font-medium">Recent activity</h3>
                    <div
                        v-for="transaction in solanaStaking.position.value.transactions.slice(
                            0,
                            5,
                        )"
                        :key="transaction.uuid"
                        class="flex items-center justify-between gap-3 text-xs"
                    >
                        <span class="capitalize">
                            {{ transaction.type.replace('_', ' ') }}
                            ·
                            {{
                                transaction.type === 'reward_claim'
                                    ? fmtAsh(transaction.amount_raw)
                                    : fmtSol(transaction.amount_raw)
                            }}
                        </span>
                        <a
                            v-if="transaction.tx_hash"
                            :href="
                                transaction.type === 'reward_claim'
                                    ? `${EXPLORER}/tx/${transaction.tx_hash}`
                                    : `${SOLSCAN}${transaction.tx_hash}`
                            "
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-mono text-primary hover:underline"
                        >
                            {{ transaction.status }} ↗
                        </a>
                        <span v-else class="text-muted-foreground">
                            {{ transaction.status }}
                        </span>
                    </div>
                </div>
            </section>
        </div>
    </main>
</template>
