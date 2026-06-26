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
import Header from '@/components/Header.vue';
import TokenIcon from '@/components/TokenIcon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWallet } from '@/composables/useWallet';
import { getMetaMaskProvider } from '@/lib/evmProvider';

const CYBERIA_CHAIN_ID = 49406;
const CYBERIA_CHAIN_ID_HEX = '0xc11e';
const CYBERIA_RPC = '/api/rpc/cyberia';
const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';

// Ritual MasterChef — Uniswap-V2-style farm minting ASH rewards. Pools are
// enumerated on-chain (poolLength/poolInfo), so new pools the owner adds show
// up here without a code change. Deployment: deployments/cyberia-ash-emission.json.
const MASTERCHEF = '0xd540DEa828567160FFDe5e792ca359aDD1f6B03D';
// Cyberia targets ~1s blocks, so daily emission ≈ rewardPerBlock × 86 400.
const BLOCKS_PER_DAY = 86400n;
const EXPLORER = 'https://explorer.cyberia.church';
const DEX_URL = 'https://swap.cyberia.church';

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

const symbolOf = async (addr: string): Promise<string> => {
    try {
        return (await new Contract(addr, ERC20_ABI, readProvider).symbol()) as string;
    } catch {
        return shortAddr(addr);
    }
};

async function loadState(): Promise<void> {
    loading.value = true;
    error.value = null;

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

        const reward = new Contract(rewardAddr, ERC20_ABI, readProvider);
        const [rSym, rDec] = await Promise.all([
            reward.symbol() as Promise<string>,
            reward.decimals() as Promise<bigint>,
        ]);
        rewardSymbol.value = rSym;
        rewardDecimals.value = Number(rDec);

        const next: Pool[] = [];

        for (let pid = 0; pid < Number(len); pid++) {
            const info = await chef.poolInfo(pid);
            const lpToken = info.lpToken as string;
            const allocPoint = info.allocPoint as bigint;
            const lp = new Contract(lpToken, ERC20_ABI, readProvider);

            let decimals = 18;

            try {
                decimals = Number(await lp.decimals());
            } catch {
                // Non-standard token; assume 18.
            }

            // Pair → "TOKEN0/TOKEN1 LP"; otherwise the token's own symbol.
            let isPair = false;
            let label: string;
            let symbols: string[];

            try {
                const pair = new Contract(lpToken, PAIR_ABI, readProvider);
                const [t0, t1] = await Promise.all([
                    pair.token0() as Promise<string>,
                    pair.token1() as Promise<string>,
                ]);
                const [s0, s1] = await Promise.all([
                    symbolOf(t0),
                    symbolOf(t1),
                ]);
                isPair = true;
                symbols = [s0, s1];
                label = `${s0}/${s1} LP`;
            } catch {
                label = await symbolOf(lpToken);
                symbols = [label];
            }

            const totalStaked = (await lp.balanceOf(MASTERCHEF)) as bigint;

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

            next.push({
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
            });
        }

        pools.value = next;
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
    fn: (signer: Awaited<ReturnType<BrowserProvider['getSigner']>>) => Promise<void>,
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
        pool.walletBalance > 0n ? formatUnits(pool.walletBalance, pool.decimals) : '';
};

const setUnstakeMax = (pool: Pool): void => {
    unstakeInput[pool.pid] =
        pool.staked > 0n ? formatUnits(pool.staked, pool.decimals) : '';
};

const explorerUrl = (addr: string): string => `${EXPLORER}/address/${addr}`;

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

    <Header />

    <main class="relative overflow-hidden">
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 -top-40 -z-10 flex justify-center"
        >
            <div
                class="h-[28rem] w-[60rem] max-w-full rounded-full bg-gradient-to-tr from-emerald-500/20 via-lime-400/10 to-amber-400/20 blur-3xl"
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
                        class="bg-gradient-to-r from-emerald-500 via-lime-500 to-amber-400 bg-clip-text text-transparent"
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
                        {{ fmt(rewardPerBlock * BLOCKS_PER_DAY, rewardDecimals, 0) }}
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
                <div class="flex items-center justify-between gap-2 sm:justify-end">
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
            <section
                v-if="pools.length > 0"
                class="grid gap-5 md:grid-cols-2"
            >
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
                            <span
                                class="inline-flex items-center gap-1 rounded-full border border-emerald-500/40 bg-emerald-500/5 px-2 py-0.5 text-xs font-medium"
                            >
                                {{ poolWeight(pool) }}% weight
                            </span>
                            <p class="mt-1 font-mono text-[11px] text-muted-foreground">
                                ≈ {{ fmt(dailyEmission(pool), rewardDecimals, 0) }}
                                {{ rewardSymbol }}/day
                            </p>
                        </div>
                    </div>

                    <!-- stats -->
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-xl border border-border bg-background/50 p-3">
                            <p class="text-xs text-muted-foreground">Total staked</p>
                            <p class="font-mono">
                                {{ fmt(pool.totalStaked, pool.decimals) }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-border bg-background/50 p-3">
                            <p class="text-xs text-muted-foreground">Your stake</p>
                            <p class="font-mono">
                                {{ hasWallet ? fmt(pool.staked, pool.decimals) : '—' }}
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
                                {{ hasWallet ? fmt(pool.pending, rewardDecimals, 6) : '—' }}
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
                                    Balance: {{ fmt(pool.walletBalance, pool.decimals) }}
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
                                    Staked: {{ fmt(pool.staked, pool.decimals) }}
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
                                        pool.staked <= 0n || isBusy(pool.pid, 'unstake')
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
                                    pool.staked <= 0n || isBusy(pool.pid, 'emergency')
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
