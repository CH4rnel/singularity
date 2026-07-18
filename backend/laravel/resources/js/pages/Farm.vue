<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    BrowserProvider,
    Contract,
    JsonRpcProvider,
    MaxUint256,
    formatUnits,
    parseUnits,
} from 'ethers';
import {
    ExternalLink,
    Loader2,
    RefreshCw,
    Search,
    Sparkles,
    Sprout,
    Wallet,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import TokenIcon from '@/components/TokenIcon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useWallet } from '@/composables/useWallet';
import { CYBER_SOL_ADDRESS, WCYBER_ADDRESS } from '@/lib/cyberiaTokens';
import { getSelectedEvmProvider } from '@/lib/evmProvider';
import { formatUsd } from '@/lib/tokenFormat';

const CYBERIA_CHAIN_ID = 49406;
const CYBERIA_CHAIN_ID_HEX = '0xc0fe';
const CYBERIA_RPC = '/api/rpc/cyberia';
const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';

// Ritual MasterChef — Uniswap-V2-style farm minting ASH rewards. Pools are
// enumerated on-chain (poolLength/poolInfo), so new pools the owner adds show
// up here without a code change. Deployment: deployments/cyberia-ash-emission.json.
const MASTERCHEF = '0xd540DEa828567160FFDe5e792ca359aDD1f6B03D';
// Cyberia targets ~1s blocks, so daily emission ≈ rewardPerBlock × 86 400.
const BLOCKS_PER_DAY = 86400n;
// Live pending ticker: extrapolate accrual every second, re-anchor to the
// on-chain pendingReward every 15 s so drift never accumulates.
const PENDING_TICK_MS = 1000;
const PENDING_REFRESH_MS = 15000;
const DAYS_PER_YEAR = 365;
const EXPLORER = 'https://explorer.cyberia.church';
const DEX_URL = 'https://swap.cyberia.church';

// Ritual factory — used to price tokens in CYBER via their WCYBER pools so we
// can quote each pool's TVL and APR without an off-chain indexer.
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

// A V2 pair exposes token0/token1; a single-asset staking token does not, which
// is how we tell an "LP pool" from an "ASH solo" pool.
const PAIR_ABI = [
    'function token0() view returns (address)',
    'function token1() view returns (address)',
    'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32)',
    'function totalSupply() view returns (uint256)',
];

type Pool = {
    pid: number;
    lpToken: string;
    allocPoint: bigint;
    decimals: number;
    label: string;
    symbols: string[];
    isPair: boolean;
    staked: bigint;
    pending: bigint;
    // Snapshot time + per-second accrual so the UI can tick pending between reads.
    pendingFetchedAt: number;
    pendingPerSec: bigint;
    walletBalance: bigint;
    allowance: bigint;
    totalStaked: bigint;
    // Priced via WCYBER pools; null when a token has no CYBER route yet.
    tvlCyber: number | null;
    tvlUsd: number | null;
    userValueUsd: number | null;
    apyPct: number | null;
};

const wallet = useWallet();

const page = usePage();
const authUser = computed(
    () =>
        page.props.auth?.user as { wallet_address?: string | null } | undefined,
);

const readRpcUrl =
    typeof window !== 'undefined'
        ? window.location.origin + CYBERIA_RPC
        : CYBERIA_PUBLIC_RPC;
const readProvider = new JsonRpcProvider(
    readRpcUrl,
    {
        chainId: CYBERIA_CHAIN_ID,
        name: 'cyberia',
    },
    {
        // Cyberia RPC accepts at most 20 requests per JSON-RPC batch. Farm state
        // fans out across every pool, so let ethers split larger reads for us.
        batchMaxCount: 20,
    },
);

const pools = ref<Pool[]>([]);
const totalAllocPoint = ref<bigint>(0n);
const rewardPerBlock = ref<bigint>(0n);
const rewardSymbol = ref('ASH');
const rewardDecimals = ref(18);

const loading = ref(true);
const status = ref<string | null>(null);
const error = ref<string | null>(null);
const sortBy = ref<'tvl' | 'apy'>('tvl');
const harvestAllBusy = ref(false);

// Per-pool form state, keyed by pid. `reactive` records add keys reactively, so
// v-model can write a fresh pid the first time a card renders.
const stakeInput = reactive<Record<number, string>>({});
const unstakeInput = reactive<Record<number, string>>({});
const busy = reactive<Record<string, boolean>>({});

const hasWallet = computed(() => !!wallet.address.value);
const connecting = ref(false);

// Clock driving the live pending counters (Cyberia mints ~1 block/second).
const nowMs = ref(Date.now());

// Chain-read pending plus extrapolated accrual since that read; re-anchored to
// the real pendingReward every PENDING_REFRESH_MS.
const livePending = (pool: Pool): bigint =>
    pool.pending +
    (pool.pendingPerSec *
        BigInt(Math.max(0, nowMs.value - pool.pendingFetchedAt))) /
        1000n;

const harvestablePools = computed(() =>
    pools.value.filter((pool) => livePending(pool) > 0n),
);
const totalPendingLive = computed(() =>
    harvestablePools.value.reduce((sum, pool) => sum + livePending(pool), 0n),
);
const poolSearch = ref('');
const filteredPools = computed(() => {
    const q = poolSearch.value.trim().toLowerCase();

    if (!q) {
        return sortedPools.value;
    }

    return sortedPools.value.filter(
        (pool) =>
            pool.label.toLowerCase().includes(q) ||
            pool.symbols.some((s) => s.toLowerCase().includes(q)) ||
            pool.lpToken.toLowerCase().includes(q),
    );
});
const sortedPools = computed(() =>
    [...pools.value].sort((a, b) => {
        const aValue = sortBy.value === 'tvl' ? a.tvlUsd : a.apyPct;
        const bValue = sortBy.value === 'tvl' ? b.tvlUsd : b.apyPct;

        if (aValue === null && bValue === null) {
            return a.pid - b.pid;
        }

        if (aValue === null) {
            return 1;
        }

        if (bValue === null) {
            return -1;
        }

        return bValue - aValue || a.pid - b.pid;
    }),
);

const isBusy = (pid: number, action: string): boolean =>
    busy[`${pid}:${action}`] ?? false;

const shortAddr = (a: string): string => `${a.slice(0, 6)}…${a.slice(-4)}`;

// Trim a formatUnits string to `digits` decimals without scientific notation.
const fmt = (v: bigint, decimals: number, digits = 4): string => {
    const s = formatUnits(v, decimals);
    const [int, dec = ''] = s.split('.');

    return dec ? `${int}.${dec.slice(0, digits)}` : int;
};

// Preserve meaningful digits for tiny LP balances instead of rendering a
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

const dailyEmission = (pool: Pool): bigint => {
    if (totalAllocPoint.value === 0n) {
        return 0n;
    }

    return (
        (rewardPerBlock.value * BLOCKS_PER_DAY * pool.allocPoint) /
        totalAllocPoint.value
    );
};

const poolWeight = (pool: Pool): number => {
    if (totalAllocPoint.value === 0n) {
        return 0;
    }

    return Number((pool.allocPoint * 10000n) / totalAllocPoint.value) / 100;
};

// Metadata + price caches: pools share tokens (WCYBER, ASH, …), so each
// address is fetched once per page load no matter how many pools use it.
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
            new Contract(addr, ERC20_ABI, readProvider).symbol() as Promise<string>
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
        p = (new Contract(addr, ERC20_ABI, readProvider).decimals() as Promise<bigint>)
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

        return tWhole > 0 && qWhole > 0 ? { token: tWhole, quote: qWhole } : null;
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

    const cyberLiquidity = priced.reduce((sum, market) => sum + market.token, 0);
    const usdLiquidity = priced.reduce((sum, market) => sum + market.quote, 0);

    return cyberLiquidity > 0 ? usdLiquidity / cyberLiquidity : null;
};

// TVL of a pool's staked amount, in whole CYBER (null if unpriceable).
const poolTvlCyber = async (
    lpToken: string,
    isPair: boolean,
    totalStaked: bigint,
    decimals: number,
): Promise<number | null> => {
    if (totalStaked === 0n) {
        return 0;
    }

    const stakedWhole = Number(formatUnits(totalStaked, decimals));

    if (!isPair) {
        const price = await priceInCyber(lpToken);

        return price === null ? null : stakedWhole * price;
    }

    try {
        const pair = new Contract(lpToken, PAIR_ABI, readProvider);
        const [t0, t1, reserves, totalSupply] = await Promise.all([
            pair.token0() as Promise<string>,
            pair.token1() as Promise<string>,
            pair.getReserves(),
            pair.totalSupply() as Promise<bigint>,
        ]);

        if (totalSupply === 0n) {
            return 0;
        }

        const [d0, d1, p0, p1] = await Promise.all([
            decimalsOf(t0),
            decimalsOf(t1),
            priceInCyber(t0),
            priceInCyber(t1),
        ]);
        const r0 = Number(formatUnits(reserves[0] as bigint, d0));
        const r1 = Number(formatUnits(reserves[1] as bigint, d1));

        // One priced side is enough: in an AMM both sides hold equal value.
        let pairValue: number | null = null;

        if (p0 !== null && p1 !== null) {
            pairValue = r0 * p0 + r1 * p1;
        } else if (p0 !== null) {
            pairValue = 2 * r0 * p0;
        } else if (p1 !== null) {
            pairValue = 2 * r1 * p1;
        }

        if (pairValue === null) {
            return null;
        }

        const supplyWhole = Number(formatUnits(totalSupply, 18));

        return (stakedWhole / supplyWhole) * pairValue;
    } catch {
        return null;
    }
};

async function loadState(): Promise<void> {
    loading.value = true;
    error.value = null;
    // Symbols/decimals are immutable; prices are not — refetch them per load.
    priceCache.clear();

    try {
        const chef = new Contract(MASTERCHEF, MASTERCHEF_ABI, readProvider);
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

        // Read the small pool headers first so retired pools (allocPoint 0) can
        // be excluded before their token metadata and pricing are requested.
        const poolHeaders = await Promise.all(
            Array.from({ length: Number(len) }, async (_, pid) => ({
                pid,
                info: await chef.poolInfo(pid),
            })),
        );
        const activePoolHeaders = poolHeaders.filter(
            ({ info }) => (info.allocPoint as bigint) > 0n,
        );

        // Active pools load in parallel; ethers splits same-tick calls into
        // batches of at most 20 for the Cyberia RPC.
        const loadPool = async (
            pid: number,
            info: (typeof activePoolHeaders)[number]['info'],
        ): Promise<Pool> => {
            const lpToken = info.lpToken as string;
            const allocPoint = info.allocPoint as bigint;
            const lp = new Contract(lpToken, ERC20_ABI, readProvider);
            const pair = new Contract(lpToken, PAIR_ABI, readProvider);

            const [decimals, pairTokens, totalStaked] = await Promise.all([
                decimalsOf(lpToken),
                Promise.all([
                    pair.token0() as Promise<string>,
                    pair.token1() as Promise<string>,
                ]).catch(() => null),
                lp.balanceOf(MASTERCHEF) as Promise<bigint>,
            ]);

            // Pair → "TOKEN0/TOKEN1 LP"; otherwise the token's own symbol.
            const isPair = pairTokens !== null;
            let label: string;
            let symbols: string[];

            if (pairTokens) {
                const [s0, s1] = await Promise.all([
                    symbolOf(pairTokens[0]),
                    symbolOf(pairTokens[1]),
                ]);
                symbols = [s0, s1];
                label = `${s0}/${s1} LP`;
            } else {
                label = await symbolOf(lpToken);
                symbols = [label];
            }

            let staked = 0n;
            let pending = 0n;
            let walletBalance = 0n;
            let allowance = 0n;

            if (me) {
                const [u, p, bal, allow] = await Promise.all([
                    chef.userInfo(pid, me),
                    chef.pendingReward(pid, me) as Promise<bigint>,
                    lp.balanceOf(me) as Promise<bigint>,
                    lp.allowance(me, MASTERCHEF) as Promise<bigint>,
                ]);
                staked = u.amount as bigint;
                pending = p;
                walletBalance = bal;
                allowance = allow;
            }

            // User accrual per second (~1 block/s): the user's stake share of
            // this pool's slice of the per-block emission.
            const pendingPerSec =
                staked > 0n && totAlloc > 0n && totalStaked > 0n
                    ? (rpb * allocPoint * staked) / (totAlloc * totalStaked)
                    : 0n;

            const tvlCyber = await poolTvlCyber(
                lpToken,
                isPair,
                totalStaked,
                decimals,
            );

            const tvlUsd =
                tvlCyber !== null && cyberUsd !== null
                    ? tvlCyber * cyberUsd
                    : null;
            const userShare =
                totalStaked > 0n
                    ? Number(formatUnits(staked, decimals)) /
                      Number(formatUnits(totalStaked, decimals))
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
                lpToken,
                allocPoint,
                decimals,
                label,
                symbols,
                isPair,
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

        pools.value = await Promise.all(
            activePoolHeaders.map(({ pid, info }) => loadPool(pid, info)),
        );
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        loading.value = false;
    }
}

// Lightweight live refresh, batched by ethers and without the loading state:
// re-anchors pending for the tickers and picks up balance/stake changes made
// outside this page (lending, swap, transfers, other tabs).
async function refreshLive(): Promise<void> {
    const me = wallet.address.value;

    if (!me || loading.value || pools.value.length === 0 || document.hidden) {
        return;
    }

    try {
        const chef = new Contract(MASTERCHEF, MASTERCHEF_ABI, readProvider);
        const updates = await Promise.all(
            pools.value.map(async (pool) => {
                const lp = new Contract(pool.lpToken, ERC20_ABI, readProvider);
                const [pending, user, walletBalance, allowance, totalStaked] =
                    await Promise.all([
                        chef.pendingReward(pool.pid, me) as Promise<bigint>,
                        chef.userInfo(pool.pid, me),
                        lp.balanceOf(me) as Promise<bigint>,
                        lp.allowance(me, MASTERCHEF) as Promise<bigint>,
                        lp.balanceOf(MASTERCHEF) as Promise<bigint>,
                    ]);

                return {
                    pool,
                    pending,
                    staked: user.amount as bigint,
                    walletBalance,
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

const ensureCyberiaNetwork = async (): Promise<BrowserProvider> => {
    const eth = getSelectedEvmProvider();

    if (!eth) {
        throw new Error('EVM wallet not found');
    }

    const provider = new BrowserProvider(eth);
    const net = await provider.getNetwork();

    if (Number(net.chainId) !== CYBERIA_CHAIN_ID) {
        try {
            await eth.request({
                method: 'wallet_switchEthereumChain',
                params: [{ chainId: CYBERIA_CHAIN_ID_HEX }],
            });
        } catch (e) {
            if ((e as { code?: number }).code === 4902) {
                await eth.request({
                    method: 'wallet_addEthereumChain',
                    params: [
                        {
                            chainId: CYBERIA_CHAIN_ID_HEX,
                            chainName: 'Cyberia',
                            nativeCurrency: {
                                name: 'Cyber',
                                symbol: 'CYBER',
                                decimals: 18,
                            },
                            rpcUrls: [CYBERIA_PUBLIC_RPC, CYBERIA_RPC],
                        },
                    ],
                });
            } else {
                throw e;
            }
        }

        return new BrowserProvider(eth);
    }

    return provider;
};

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
        signer: Awaited<ReturnType<BrowserProvider['getSigner']>>,
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

async function stake(pool: Pool): Promise<void> {
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
        error.value = `Insufficient ${pool.label} balance`;

        return;
    }

    await run(pool.pid, 'stake', async (signer) => {
        if (pool.allowance < amount) {
            status.value = `Approving ${pool.label}…`;
            const lp = new Contract(pool.lpToken, ERC20_ABI, signer);
            const atx = await lp.approve(MASTERCHEF, MaxUint256);
            await atx.wait();
        }

        status.value = 'Confirm the stake in your wallet…';
        const chef = new Contract(MASTERCHEF, MASTERCHEF_ABI, signer);
        const tx = await chef.deposit(pool.pid, amount);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Staked ${fmt(amount, pool.decimals)} ${pool.label}.`;
        stakeInput[pool.pid] = '';
    });
}

async function unstake(pool: Pool): Promise<void> {
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
        const chef = new Contract(MASTERCHEF, MASTERCHEF_ABI, signer);
        const tx = await chef.withdraw(pool.pid, amount);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Unstaked ${fmt(amount, pool.decimals)} ${pool.label} (rewards harvested).`;
        unstakeInput[pool.pid] = '';
    });
}

// Harvest only: depositing 0 pays out pending rewards without moving the stake.
async function harvest(pool: Pool): Promise<void> {
    const expected = livePending(pool);

    await run(pool.pid, 'harvest', async (signer) => {
        status.value = 'Confirm the harvest in your wallet…';
        const chef = new Contract(MASTERCHEF, MASTERCHEF_ABI, signer);
        const tx = await chef.deposit(pool.pid, 0n);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Harvested ≈ ${fmt(expected, rewardDecimals.value)} ${rewardSymbol.value} from ${pool.label}.`;
    });
}

async function harvestAll(): Promise<void> {
    const targets = harvestablePools.value;

    if (targets.length === 0) {
        return;
    }

    const expectedTotal = targets.reduce(
        (sum, pool) => sum + livePending(pool),
        0n,
    );

    error.value = null;
    harvestAllBusy.value = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const chef = new Contract(MASTERCHEF, MASTERCHEF_ABI, signer);

        for (const [index, pool] of targets.entries()) {
            status.value = `Harvesting ${index + 1}/${targets.length}: ${pool.label}…`;
            const tx = await chef.deposit(pool.pid, 0n);
            await tx.wait();
        }

        status.value = `Harvested ≈ ${fmt(expectedTotal, rewardDecimals.value)} ${rewardSymbol.value} from ${targets.length} farms.`;
        await loadState();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        harvestAllBusy.value = false;
    }
}

// Emergency: pull the stake out and forfeit pending rewards. Last resort.
async function emergencyUnstake(pool: Pool): Promise<void> {
    if (
        typeof window !== 'undefined' &&
        !window.confirm(
            `Emergency unstake forfeits your pending ${rewardSymbol.value} rewards from ${pool.label}. Continue?`,
        )
    ) {
        return;
    }

    await run(pool.pid, 'emergency', async (signer) => {
        status.value = 'Confirm the emergency unstake…';
        const chef = new Contract(MASTERCHEF, MASTERCHEF_ABI, signer);
        const tx = await chef.emergencyWithdraw(pool.pid);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Emergency-unstaked ${pool.label}.`;
    });
}

const setStakeMax = (pool: Pool): void => {
    stakeInput[pool.pid] =
        pool.walletBalance > 0n
            ? formatUnits(pool.walletBalance, pool.decimals)
            : '';
};

const setUnstakeMax = (pool: Pool): void => {
    unstakeInput[pool.pid] =
        pool.staked > 0n ? formatUnits(pool.staked, pool.decimals) : '';
};

const explorerUrl = (addr: string): string => `${EXPLORER}/address/${addr}`;

const fmtApy = (v: number): string =>
    v >= 1000
        ? Math.round(v).toLocaleString()
        : v.toFixed(v >= 100 ? 0 : 1);

const fmtCyber = (v: number): string =>
    v >= 1000 ? Math.round(v).toLocaleString() : v.toFixed(2);
const fmtUsdValue = (v: number): string =>
    v === 0 ? '$0.00' : formatUsd(v);

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

onMounted(async () => {
    tickTimer = setInterval(() => {
        nowMs.value = Date.now();
    }, PENDING_TICK_MS);
    liveTimer = setInterval(() => void refreshLive(), PENDING_REFRESH_MS);
    window.addEventListener('focus', onTabVisible);
    document.addEventListener('visibilitychange', onTabVisible);

    // No shared layout here, so reconnect the saved wallet ourselves (mirrors
    // Bridge/Lending/CyberSolSwap) before the first read.
    await wallet.restore(authUser.value?.wallet_address ?? null);
    await loadState();
    watchWalletChanges = true;
});

onBeforeUnmount(() => {
    clearInterval(tickTimer);
    clearInterval(liveTimer);
    window.removeEventListener('focus', onTabVisible);
    document.removeEventListener('visibilitychange', onTabVisible);
});
watch(
    () => wallet.address.value,
    () => {
        if (watchWalletChanges) {
            void loadState();
        }
    },
);
</script>

<template>
    <Head title="Farm · stake LP, earn ASH" />

    <main class="relative overflow-hidden">
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 -top-40 -z-10 flex justify-center"
        >
            <div
                class="h-[28rem] w-[60rem] max-w-full rounded-full bg-gradient-to-tr from-cyan-400/20 via-teal-300/10 to-fuchsia-400/20 blur-3xl"
            ></div>
        </div>

        <div class="mx-auto max-w-5xl space-y-10 px-4 py-12 sm:py-16">
            <!-- HERO -->
            <section class="space-y-4 text-center">
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-border bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground backdrop-blur"
                >
                    <Sprout class="h-3.5 w-3.5 text-emerald-500" />
                    Yield farming
                </span>
                <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
                    Stake &amp; earn
                    <span
                        class="bg-gradient-to-r from-cyan-400 via-teal-400 to-fuchsia-400 bg-clip-text text-transparent"
                        >{{ rewardSymbol }}</span
                    >
                </h1>
                <p class="mx-auto max-w-2xl text-base text-muted-foreground">
                    Stake your liquidity-provider tokens to farm freshly minted
                    {{ rewardSymbol }}. Rewards accrue every block and can be
                    harvested any time.
                </p>
            </section>

            <!-- GLOBAL STATS -->
            <section
                class="grid gap-4 rounded-2xl border border-border bg-card/50 p-5 sm:grid-cols-4"
            >
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
                <div>
                    <p class="text-xs text-muted-foreground">Per block</p>
                    <p class="font-mono text-lg">
                        {{ fmt(rewardPerBlock, rewardDecimals, 6) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Active pools</p>
                    <p class="font-mono text-lg">{{ pools.length || '—' }}</p>
                </div>
                <div
                    class="flex items-center justify-between gap-2 sm:justify-end"
                >
                    <a
                        :href="explorerUrl(MASTERCHEF)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 font-mono text-xs text-muted-foreground hover:text-foreground"
                    >
                        {{ shortAddr(MASTERCHEF) }}
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
                    <Wallet v-else class="mr-2 h-4 w-4" />
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
                v-if="pools.length > 0"
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="relative w-full sm:max-w-xs">
                    <Search
                        class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="poolSearch"
                        placeholder="Search pools…"
                        class="pl-9"
                    />
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <span class="text-muted-foreground">Sort by</span>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5 transition"
                        :class="
                            sortBy === 'tvl'
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card hover:border-foreground/30'
                        "
                        @click="sortBy = 'tvl'"
                    >
                        TVL
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5 transition"
                        :class="
                            sortBy === 'apy'
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card hover:border-foreground/30'
                        "
                        @click="sortBy = 'apy'"
                    >
                        APY
                    </button>
                </div>
                <Button
                    variant="outline"
                    title="One wallet transaction per farm with pending rewards"
                    :disabled="
                        !hasWallet ||
                        harvestablePools.length === 0 ||
                        harvestAllBusy
                    "
                    @click="harvestAll"
                >
                    <Loader2
                        v-if="harvestAllBusy"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    Harvest all
                    <span
                        v-if="harvestablePools.length > 0"
                        class="ml-1 font-mono"
                    >
                        ({{ harvestablePools.length }} ·
                        {{ fmt(totalPendingLive, rewardDecimals) }}
                        {{ rewardSymbol }})
                    </span>
                </Button>
            </section>

            <section
                v-if="loading && pools.length === 0"
                aria-label="Loading farms"
                class="space-y-4"
            >
                <div class="flex items-center justify-center gap-2 text-sm text-muted-foreground">
                    <Loader2 class="h-4 w-4 animate-spin" />
                    Loading farms…
                </div>
                <div class="grid gap-5 md:grid-cols-2">
                    <div
                        v-for="index in 4"
                        :key="index"
                        class="space-y-5 rounded-2xl border border-border bg-card p-5"
                    >
                        <div class="flex justify-between gap-4">
                            <div class="flex flex-1 items-center gap-3">
                                <Skeleton class="h-8 w-8 rounded-full" />
                                <div class="flex-1 space-y-2">
                                    <Skeleton class="h-4 w-32" />
                                    <Skeleton class="h-3 w-24" />
                                </div>
                            </div>
                            <Skeleton class="h-7 w-20" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <Skeleton class="h-20 rounded-xl" />
                            <Skeleton class="h-20 rounded-xl" />
                        </div>
                        <Skeleton class="h-16 rounded-xl" />
                    </div>
                </div>
            </section>

            <section
                v-if="pools.length > 0 && filteredPools.length === 0"
                class="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground"
            >
                No pools match “{{ poolSearch }}”.
            </section>

            <!-- POOLS -->
            <section
                v-if="filteredPools.length > 0"
                class="grid gap-5 md:grid-cols-2"
            >
                <div
                    v-for="pool in filteredPools"
                    :key="pool.pid"
                    class="space-y-4 rounded-2xl border border-border bg-card p-5"
                >
                    <!-- header -->
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h2 class="flex items-center gap-2 font-semibold">
                                <span class="flex -space-x-2">
                                    <TokenIcon
                                        v-for="(s, i) in pool.symbols"
                                        :key="s + i"
                                        :symbol="s"
                                        :size="24"
                                        ring
                                    />
                                </span>
                                {{ pool.label }}
                            </h2>
                            <a
                                :href="explorerUrl(pool.lpToken)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-mono text-[11px] text-muted-foreground hover:text-foreground"
                            >
                                {{ shortAddr(pool.lpToken) }}
                            </a>
                        </div>
                        <div class="text-right">
                            <p
                                v-if="pool.apyPct !== null"
                                class="font-mono text-lg font-semibold text-emerald-500"
                            >
                                {{ fmtApy(pool.apyPct) }}% APY
                            </p>
                            <p
                                v-else
                                class="text-xs text-muted-foreground"
                                title="No CYBER price route for this pool's tokens yet"
                            >
                                APY —
                            </p>
                            <span
                                class="mt-1 inline-flex items-center gap-1 rounded-full border border-emerald-500/40 bg-emerald-500/5 px-2 py-0.5 text-xs font-medium"
                            >
                                {{ poolWeight(pool) }}% weight
                            </span>
                            <p
                                class="mt-1 font-mono text-[11px] text-muted-foreground"
                            >
                                ≈
                                {{
                                    fmt(dailyEmission(pool), rewardDecimals, 0)
                                }}
                                {{ rewardSymbol }}/day
                            </p>
                        </div>
                    </div>

                    <!-- stats -->
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div
                            class="rounded-xl border border-border bg-background/50 p-3"
                        >
                            <p class="text-xs text-muted-foreground">
                                Total staked
                            </p>
                            <p class="font-mono">
                                {{ fmtTokenAmount(pool.totalStaked, pool.decimals) }}
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
                                v-if="hasWallet && pool.userValueUsd !== null"
                                class="font-mono text-xs font-medium"
                            >
                                {{ fmtUsdValue(pool.userValueUsd) }}
                            </p>
                        </div>
                    </div>

                    <!-- pending rewards -->
                    <div
                        class="flex items-center justify-between gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3"
                    >
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Pending {{ rewardSymbol }}
                            </p>
                            <p class="font-mono text-lg">
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
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                !hasWallet ||
                                livePending(pool) <= 0n ||
                                harvestAllBusy ||
                                isBusy(pool.pid, 'harvest')
                            "
                            @click="harvest(pool)"
                        >
                            <Loader2
                                v-if="isBusy(pool.pid, 'harvest')"
                                class="mr-1 h-4 w-4 animate-spin"
                            />
                            Harvest
                        </Button>
                    </div>

                    <!-- stake -->
                    <div v-if="hasWallet" class="space-y-3">
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

                        <!-- secondary actions -->
                        <div
                            class="flex items-center justify-between pt-1 text-[11px]"
                        >
                            <a
                                v-if="pool.isPair"
                                :href="DEX_URL"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
                            >
                                Get LP <ExternalLink class="h-3 w-3" />
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
                v-else-if="!loading && pools.length === 0"
                class="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground"
            >
                <Sparkles class="mx-auto mb-2 h-5 w-5" />
                No farm pools are live yet.
            </section>
        </div>
    </main>
</template>
