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
    Sparkles,
    Sprout,
    Wallet,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import TokenIcon from '@/components/TokenIcon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWallet } from '@/composables/useWallet';
import { CYBER_SOL_ADDRESS, WCYBER_ADDRESS } from '@/lib/cyberiaTokens';
import { getMetaMaskProvider } from '@/lib/evmProvider';

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
const DAYS_PER_YEAR = 365;
const EXPLORER = 'https://explorer.cyberia.church';
const DEX_URL = 'https://swap.cyberia.church';

// Ritual factory — used to price tokens in CYBER via their WCYBER pools so we
// can quote each pool's TVL and APR without an off-chain indexer.
const FACTORY = '0xB0aC30907c04b61F1482e62eA66eF4562a690917';
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
    walletBalance: bigint;
    allowance: bigint;
    totalStaked: bigint;
    // Priced via WCYBER pools; null when a token has no CYBER route yet.
    tvlCyber: number | null;
    aprPct: number | null;
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
const readProvider = new JsonRpcProvider(readRpcUrl, {
    chainId: CYBERIA_CHAIN_ID,
    name: 'cyberia',
});

const pools = ref<Pool[]>([]);
const totalAllocPoint = ref<bigint>(0n);
const rewardPerBlock = ref<bigint>(0n);
const rewardSymbol = ref('ASH');
const rewardDecimals = ref(18);

const loading = ref(false);
const status = ref<string | null>(null);
const error = ref<string | null>(null);

// Per-pool form state, keyed by pid. `reactive` records add keys reactively, so
// v-model can write a fresh pid the first time a card renders.
const stakeInput = reactive<Record<number, string>>({});
const unstakeInput = reactive<Record<number, string>>({});
const busy = reactive<Record<string, boolean>>({});

const hasWallet = computed(() => !!wallet.address.value);
const connecting = ref(false);

const isBusy = (pid: number, action: string): boolean =>
    busy[`${pid}:${action}`] ?? false;

const shortAddr = (a: string): string => `${a.slice(0, 6)}…${a.slice(-4)}`;

// Trim a formatUnits string to `digits` decimals without scientific notation.
const fmt = (v: bigint, decimals: number, digits = 4): string => {
    const s = formatUnits(v, decimals);
    const [int, dec = ''] = s.split('.');

    return dec ? `${int}.${dec.slice(0, digits)}` : int;
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

const symbolOf = (addr: string): Promise<string> => {
    const key = addr.toLowerCase();
    let p = symbolCache.get(key);

    if (!p) {
        p = (
            new Contract(addr, ERC20_ABI, readProvider).symbol() as Promise<string>
        ).catch(() => shortAddr(addr));
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

        const [rSym, rDec, rewardPrice] = await Promise.all([
            symbolOf(rewardAddr),
            decimalsOf(rewardAddr),
            priceInCyber(rewardAddr),
        ]);
        rewardSymbol.value = rSym;
        rewardDecimals.value = rDec;

        const rewardPerYearWhole =
            Number(formatUnits(rpb * BLOCKS_PER_DAY, rDec)) * DAYS_PER_YEAR;

        // All pools load in parallel; ethers batches same-tick RPC calls, so
        // this is a handful of HTTP round-trips instead of ~10 per pool.
        const loadPool = async (pid: number): Promise<Pool> => {
            const info = await chef.poolInfo(pid);
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

            const tvlCyber = await poolTvlCyber(
                lpToken,
                isPair,
                totalStaked,
                decimals,
            );

            let aprPct: number | null = null;

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
                aprPct = (yearlyRewardCyber / tvlCyber) * 100;
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
                walletBalance,
                allowance,
                totalStaked,
                tvlCyber,
                aprPct,
            };
        };

        pools.value = await Promise.all(
            Array.from({ length: Number(len) }, (_, pid) => loadPool(pid)),
        );
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        loading.value = false;
    }
}

const ensureCyberiaNetwork = async (): Promise<BrowserProvider> => {
    const eth = getMetaMaskProvider();

    if (!eth) {
        throw new Error('MetaMask not found');
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
        await loadState();
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
    await run(pool.pid, 'harvest', async (signer) => {
        status.value = 'Confirm the harvest in your wallet…';
        const chef = new Contract(MASTERCHEF, MASTERCHEF_ABI, signer);
        const tx = await chef.deposit(pool.pid, 0n);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Harvested ${rewardSymbol.value} from ${pool.label}.`;
    });
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

const fmtApr = (v: number): string =>
    v >= 1000
        ? Math.round(v).toLocaleString()
        : v.toFixed(v >= 100 ? 0 : 1);

const fmtCyber = (v: number): string =>
    v >= 1000 ? Math.round(v).toLocaleString() : v.toFixed(2);

onMounted(async () => {
    // No shared layout here, so reconnect the saved wallet ourselves (mirrors
    // Bridge/Lending/CyberSolSwap) before the first read.
    await wallet.restore(authUser.value?.wallet_address ?? null);
    await loadState();
});
watch(() => wallet.address.value, loadState);
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

            <!-- POOLS -->
            <section v-if="pools.length > 0" class="grid gap-5 md:grid-cols-2">
                <div
                    v-for="pool in pools"
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
                                v-if="pool.aprPct !== null"
                                class="font-mono text-lg font-semibold text-emerald-500"
                            >
                                {{ fmtApr(pool.aprPct) }}% APR
                            </p>
                            <p
                                v-else
                                class="text-xs text-muted-foreground"
                                title="No CYBER price route for this pool's tokens yet"
                            >
                                APR —
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
                                {{ fmt(pool.totalStaked, pool.decimals) }}
                            </p>
                            <p
                                v-if="pool.tvlCyber !== null"
                                class="font-mono text-[11px] text-muted-foreground"
                            >
                                ≈ {{ fmtCyber(pool.tvlCyber) }} CYBER
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
                                        ? fmt(pool.staked, pool.decimals)
                                        : '—'
                                }}
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
                                        ? fmt(pool.pending, rewardDecimals, 6)
                                        : '—'
                                }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                !hasWallet ||
                                pool.pending <= 0n ||
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
                                    {{ fmt(pool.walletBalance, pool.decimals) }}
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
                                    {{ fmt(pool.staked, pool.decimals) }}
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
                v-else-if="!loading"
                class="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground"
            >
                <Sparkles class="mx-auto mb-2 h-5 w-5" />
                No farm pools are live yet.
            </section>
        </div>
    </main>
</template>
