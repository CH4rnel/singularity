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
import { Loader2 } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import Header from '@/components/Header.vue';
import TokenIcon from '@/components/TokenIcon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useWallet } from '@/composables/useWallet';
import { getMetaMaskProvider } from '@/lib/evmProvider';

const CYBERIA_CHAIN_ID = 49406;
const CYBERIA_CHAIN_ID_HEX = '0xc11e';
const CYBERIA_RPC = '/api/rpc/cyberia';
const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';
const EXPLORER = 'https://explorer.cyberia.church';

// Ritual DEX (QuickSwap V2 fork). deployments/cyberia-quickswap.json.
const ROUTER = '0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62';
const FACTORY = '0xB0aC30907c04b61F1482e62eA66eF4562a690917';
const WCYBER = '0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9';
// Sentinel for native CYBER in the token pickers (maps to WCYBER on-chain).
const NATIVE = 'NATIVE';

const ROUTER_ABI = [
    'function addLiquidity(address tokenA,address tokenB,uint amountADesired,uint amountBDesired,uint amountAMin,uint amountBMin,address to,uint deadline) returns (uint,uint,uint)',
    'function addLiquidityETH(address token,uint amountTokenDesired,uint amountTokenMin,uint amountETHMin,address to,uint deadline) payable returns (uint,uint,uint)',
    'function removeLiquidity(address tokenA,address tokenB,uint liquidity,uint amountAMin,uint amountBMin,address to,uint deadline) returns (uint,uint)',
    'function removeLiquidityETH(address token,uint liquidity,uint amountTokenMin,uint amountETHMin,address to,uint deadline) returns (uint,uint)',
    'function quote(uint amountA,uint reserveA,uint reserveB) pure returns (uint)',
];
const FACTORY_ABI = [
    'function getPair(address,address) view returns (address)',
];
const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
];
const PAIR_ABI = [
    'function token0() view returns (address)',
    'function token1() view returns (address)',
    'function getReserves() view returns (uint112 reserve0,uint112 reserve1,uint32)',
    'function totalSupply() view returns (uint256)',
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
];

type Token = { address: string; symbol: string; native?: boolean };
type PoolRow = {
    pair_address: string;
    token0: string;
    token1: string;
    symbol0: string;
    symbol1: string;
    reserve0: number;
    reserve1: number;
    tvl_usd: number | null;
};

const props = defineProps<{ pools: PoolRow[]; indexerReady: boolean }>();

const wallet = useWallet();
const page = usePage();
const authUser = computed(
    () =>
        page.props.auth?.user as { wallet_address?: string | null } | undefined,
);

const tab = ref<'add' | 'remove'>('add');
const slippage = ref('0.5');
const status = ref<string | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);

const readRpcUrl =
    typeof window !== 'undefined'
        ? window.location.origin + CYBERIA_RPC
        : CYBERIA_PUBLIC_RPC;
const readProvider = new JsonRpcProvider(readRpcUrl, {
    chainId: CYBERIA_CHAIN_ID,
    name: 'cyberia',
});

const shortAddr = (a: string): string =>
    a ? `${a.slice(0, 6)}…${a.slice(-4)}` : '';
const slippageBps = computed(() => {
    const v = Math.round(Number(slippage.value || '0') * 100);

    return Number.isFinite(v) && v >= 0 && v < 5000 ? v : 50;
});
const minOut = (desired: bigint): bigint =>
    (desired * BigInt(10000 - slippageBps.value)) / 10000n;
const deadline = (): bigint => BigInt(Math.floor(Date.now() / 1000) + 1200);
const resolveAddr = (a: string): string => (a === NATIVE ? WCYBER : a);

const customTokens = ref<Token[]>([]);
const tokens = computed<Token[]>(() => {
    const map = new Map<string, Token>();
    map.set(NATIVE, { address: NATIVE, symbol: 'CYBER', native: true });
    map.set(WCYBER.toLowerCase(), { address: WCYBER, symbol: 'WCYBER' });

    for (const p of props.pools ?? []) {
        map.set(p.token0.toLowerCase(), {
            address: p.token0,
            symbol: p.symbol0,
        });
        map.set(p.token1.toLowerCase(), {
            address: p.token1,
            symbol: p.symbol1,
        });
    }

    for (const t of customTokens.value) {
        map.set(t.address.toLowerCase(), t);
    }

    return Array.from(map.values());
});
const symbolOf = (addr: string): string =>
    tokens.value.find((t) => t.address.toLowerCase() === addr.toLowerCase())
        ?.symbol ?? shortAddr(addr);

// --- token metadata cache (live) --------------------------------------------
const metaCache = new Map<string, { symbol: string; decimals: number }>();
const tokenMeta = async (
    addr: string,
): Promise<{ symbol: string; decimals: number }> => {
    if (addr === NATIVE) {
        return { symbol: 'CYBER', decimals: 18 };
    }

    const key = addr.toLowerCase();
    const cached = metaCache.get(key);

    if (cached) {
        return cached;
    }

    const c = new Contract(addr, ERC20_ABI, readProvider);
    const [symbol, decimals] = await Promise.all([
        c.symbol().catch(() => '?'),
        c.decimals().catch(() => 18),
    ]);
    const meta = { symbol: String(symbol), decimals: Number(decimals) };
    metaCache.set(key, meta);

    return meta;
};

// --- Add liquidity ----------------------------------------------------------
const tokenA = ref<string>(NATIVE);
const tokenB = ref<string>('');
const amountA = ref('');
const amountB = ref('');
const balA = ref<bigint>(0n);
const balB = ref<bigint>(0n);
const decA = ref(18);
const decB = ref(18);
const pair = ref<{
    exists: boolean;
    reserveA: bigint;
    reserveB: bigint;
} | null>(null);

const nativeSelected = computed(
    () => tokenA.value === NATIVE || tokenB.value === NATIVE,
);

const loadBalance = async (token: string): Promise<bigint> => {
    const me = wallet.address.value;

    if (!me) {
        return 0n;
    }

    if (token === NATIVE) {
        return readProvider.getBalance(me);
    }

    try {
        return (await new Contract(token, ERC20_ABI, readProvider).balanceOf(
            me,
        )) as bigint;
    } catch {
        return 0n;
    }
};

const refreshPair = async (): Promise<void> => {
    pair.value = null;
    amountB.value = '';

    if (!tokenA.value || !tokenB.value || tokenA.value === tokenB.value) {
        return;
    }

    const [ma, mb] = await Promise.all([
        tokenMeta(tokenA.value),
        tokenMeta(tokenB.value),
    ]);
    decA.value = ma.decimals;
    decB.value = mb.decimals;

    const addrA = resolveAddr(tokenA.value);
    const addrB = resolveAddr(tokenB.value);

    try {
        const factory = new Contract(FACTORY, FACTORY_ABI, readProvider);
        const pairAddr: string = await factory.getPair(addrA, addrB);

        if (!pairAddr || /^0x0+$/.test(pairAddr)) {
            pair.value = { exists: false, reserveA: 0n, reserveB: 0n };

            return;
        }

        const p = new Contract(pairAddr, PAIR_ABI, readProvider);
        const [t0, reserves] = await Promise.all([p.token0(), p.getReserves()]);
        const aIsToken0 = String(t0).toLowerCase() === addrA.toLowerCase();
        pair.value = {
            exists: true,
            reserveA: aIsToken0 ? reserves[0] : reserves[1],
            reserveB: aIsToken0 ? reserves[1] : reserves[0],
        };

        if (amountA.value) {
            recomputeFromA();
        }
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    }
};

const recomputeFromA = (): void => {
    if (!pair.value?.exists || pair.value.reserveA === 0n || !amountA.value) {
        return;
    }

    try {
        const inA = parseUnits(amountA.value, decA.value);
        const outB = (inA * pair.value.reserveB) / pair.value.reserveA;
        amountB.value = formatUnits(outB, decB.value);
    } catch {
        /* partial input */
    }
};
const recomputeFromB = (): void => {
    if (!pair.value?.exists || pair.value.reserveB === 0n || !amountB.value) {
        return;
    }

    try {
        const inB = parseUnits(amountB.value, decB.value);
        const outA = (inB * pair.value.reserveA) / pair.value.reserveB;
        amountA.value = formatUnits(outA, decA.value);
    } catch {
        /* partial input */
    }
};

watch([tokenA, tokenB, () => wallet.address.value], () => void refreshPair());

// Each token's balance loads on its own — independent of whether the *other*
// side is chosen yet — and refreshes when the connected wallet changes.
const loadSide = async (side: 'A' | 'B'): Promise<void> => {
    const token = side === 'A' ? tokenA.value : tokenB.value;

    if (!token) {
        if (side === 'A') {
            balA.value = 0n;
        } else {
            balB.value = 0n;
        }

        return;
    }

    const [meta, bal] = await Promise.all([
        tokenMeta(token),
        loadBalance(token),
    ]);

    if (side === 'A') {
        decA.value = meta.decimals;
        balA.value = bal;
    } else {
        decB.value = meta.decimals;
        balB.value = bal;
    }
};
watch([tokenA, () => wallet.address.value], () => void loadSide('A'), {
    immediate: true,
});
watch([tokenB, () => wallet.address.value], () => void loadSide('B'), {
    immediate: true,
});

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

const approveIfNeeded = async (
    signer: Awaited<ReturnType<BrowserProvider['getSigner']>>,
    token: string,
    spender: string,
    amount: bigint,
    abi: string[] = ERC20_ABI,
): Promise<void> => {
    const me = await signer.getAddress();
    const c = new Contract(token, abi, signer);
    const allowance = (await c.allowance(me, spender)) as bigint;

    if (allowance >= amount) {
        return;
    }

    status.value = `Approving ${symbolOf(token)}…`;
    const tx = await c.approve(spender, MaxUint256);
    await tx.wait();
};

const addLiquidity = async (): Promise<void> => {
    if (!wallet.isConnected.value) {
        await wallet.connect();

        if (!wallet.isConnected.value) {
            return;
        }
    }

    if (!tokenA.value || !tokenB.value || tokenA.value === tokenB.value) {
        error.value = 'Pick two different tokens';

        return;
    }

    error.value = null;
    busy.value = true;

    try {
        const desiredA = parseUnits(amountA.value || '0', decA.value);
        const desiredB = parseUnits(amountB.value || '0', decB.value);

        if (desiredA <= 0n || desiredB <= 0n) {
            throw new Error('Enter amounts for both tokens');
        }

        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const to = await signer.getAddress();
        const router = new Contract(ROUTER, ROUTER_ABI, signer);

        if (nativeSelected.value) {
            const nativeIsA = tokenA.value === NATIVE;
            const token = nativeIsA
                ? resolveAddr(tokenB.value)
                : resolveAddr(tokenA.value);
            const tokenDesired = nativeIsA ? desiredB : desiredA;
            const cyberDesired = nativeIsA ? desiredA : desiredB;
            await approveIfNeeded(signer, token, ROUTER, tokenDesired);
            status.value = 'Confirm addLiquidityETH…';
            const tx = await router.addLiquidityETH(
                token,
                tokenDesired,
                minOut(tokenDesired),
                minOut(cyberDesired),
                to,
                deadline(),
                { value: cyberDesired },
            );
            status.value = 'Waiting for block…';
            await tx.wait();
        } else {
            await approveIfNeeded(signer, tokenA.value, ROUTER, desiredA);
            await approveIfNeeded(signer, tokenB.value, ROUTER, desiredB);
            status.value = 'Confirm addLiquidity…';
            const tx = await router.addLiquidity(
                tokenA.value,
                tokenB.value,
                desiredA,
                desiredB,
                minOut(desiredA),
                minOut(desiredB),
                to,
                deadline(),
            );
            status.value = 'Waiting for block…';
            await tx.wait();
        }

        status.value = 'Liquidity added.';
        amountA.value = '';
        amountB.value = '';
        await refreshPair();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
        status.value = null;
    } finally {
        busy.value = false;
    }
};

const pickToken = (side: 'A' | 'B', val: unknown): void => {
    const v = String(val ?? '');

    if (side === 'A') {
        tokenA.value = v;
    } else {
        tokenB.value = v;
    }
};

const setMaxA = (): void => {
    amountA.value = formatUnits(balA.value, decA.value);
    recomputeFromA();
};
const setMaxB = (): void => {
    amountB.value = formatUnits(balB.value, decB.value);
    recomputeFromB();
};

// --- Remove liquidity -------------------------------------------------------
type Position = {
    pairAddress: string;
    token0: string;
    token1: string;
    symbol0: string;
    symbol1: string;
    lp: bigint;
    totalSupply: bigint;
    reserve0: bigint;
    reserve1: bigint;
    dec0: number;
    dec1: number;
};
const positions = ref<Position[]>([]);
const positionsLoading = ref(false);
const selected = ref<string | null>(null);
const removePct = ref(50);
const receiveNative = ref(true);

const loadPositions = async (): Promise<void> => {
    const me = wallet.address.value;
    positions.value = [];

    if (!me) {
        return;
    }

    positionsLoading.value = true;

    try {
        const found = await Promise.all(
            (props.pools ?? []).map(async (row) => {
                try {
                    const p = new Contract(
                        row.pair_address,
                        PAIR_ABI,
                        readProvider,
                    );
                    const lp = (await p.balanceOf(me)) as bigint;

                    if (lp <= 0n) {
                        return null;
                    }

                    const [ts, reserves, m0, m1] = await Promise.all([
                        p.totalSupply() as Promise<bigint>,
                        p.getReserves(),
                        tokenMeta(row.token0),
                        tokenMeta(row.token1),
                    ]);

                    return {
                        pairAddress: row.pair_address,
                        token0: row.token0,
                        token1: row.token1,
                        symbol0: m0.symbol,
                        symbol1: m1.symbol,
                        lp,
                        totalSupply: ts,
                        reserve0: reserves[0],
                        reserve1: reserves[1],
                        dec0: m0.decimals,
                        dec1: m1.decimals,
                    } as Position;
                } catch {
                    return null;
                }
            }),
        );
        positions.value = found.filter((p): p is Position => p !== null);
    } finally {
        positionsLoading.value = false;
    }
};

const selectedPosition = computed(
    () => positions.value.find((p) => p.pairAddress === selected.value) ?? null,
);
const pooledOut = computed(() => {
    const p = selectedPosition.value;

    if (!p || p.totalSupply === 0n) {
        return null;
    }

    const liq = (p.lp * BigInt(removePct.value)) / 100n;

    return {
        liq,
        amount0: (p.reserve0 * liq) / p.totalSupply,
        amount1: (p.reserve1 * liq) / p.totalSupply,
    };
});
const positionHasWcyber = (p: Position): boolean =>
    p.token0.toLowerCase() === WCYBER.toLowerCase() ||
    p.token1.toLowerCase() === WCYBER.toLowerCase();

const removeLiquidity = async (): Promise<void> => {
    const p = selectedPosition.value;
    const out = pooledOut.value;

    if (!p || !out || out.liq <= 0n) {
        return;
    }

    error.value = null;
    busy.value = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const to = await signer.getAddress();
        const router = new Contract(ROUTER, ROUTER_ABI, signer);

        await approveIfNeeded(signer, p.pairAddress, ROUTER, out.liq, PAIR_ABI);

        const asNative = receiveNative.value && positionHasWcyber(p);

        if (asNative) {
            const wIs0 = p.token0.toLowerCase() === WCYBER.toLowerCase();
            const token = wIs0 ? p.token1 : p.token0;
            const tokenMin = minOut(wIs0 ? out.amount1 : out.amount0);
            const cyberMin = minOut(wIs0 ? out.amount0 : out.amount1);
            status.value = 'Confirm removeLiquidityETH…';
            const tx = await router.removeLiquidityETH(
                token,
                out.liq,
                tokenMin,
                cyberMin,
                to,
                deadline(),
            );
            status.value = 'Waiting for block…';
            await tx.wait();
        } else {
            status.value = 'Confirm removeLiquidity…';
            const tx = await router.removeLiquidity(
                p.token0,
                p.token1,
                out.liq,
                minOut(out.amount0),
                minOut(out.amount1),
                to,
                deadline(),
            );
            status.value = 'Waiting for block…';
            await tx.wait();
        }

        status.value = 'Liquidity removed.';
        await loadPositions();
        selected.value = null;
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
        status.value = null;
    } finally {
        busy.value = false;
    }
};

watch([() => wallet.address.value, tab], () => {
    if (tab.value === 'remove') {
        void loadPositions();
    }
});

// --- custom token by address ------------------------------------------------
const customAddr = ref('');
const addCustomToken = async (): Promise<void> => {
    const a = customAddr.value.trim();

    if (!/^0x[a-fA-F0-9]{40}$/.test(a)) {
        error.value = 'Invalid token address';

        return;
    }

    try {
        const m = await tokenMeta(a);
        customTokens.value = [
            ...customTokens.value,
            { address: a, symbol: m.symbol },
        ];
        customAddr.value = '';
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    }
};

const fmt = (v: bigint, dec: number): string => {
    const s = formatUnits(v, dec);

    return s.includes('.') ? s.replace(/\.?0+$/, '') : s;
};

onMounted(async () => {
    // Silently restore the wallet (saved address + eth_accounts, no popup) so
    // balances/positions populate without the user re-clicking connect, same as
    // Farm/Lending/Bridge. The token-balance watchers refresh once address lands.
    await wallet.restore(authUser.value?.wallet_address ?? null);
    void refreshPair();
});
</script>

<template>
    <Head title="Cyberia Liquidity" />

    <div class="liq-page">
        <Header />

        <div class="mx-auto max-w-xl px-4 py-6">
            <header class="mb-4">
                <h1 class="text-2xl font-bold">Liquidity</h1>
                <p class="text-sm text-muted-foreground">
                    Provide or withdraw liquidity on Ritual (QuickSwap V2 on
                    Cyberia). Native CYBER is supported directly.
                </p>
            </header>

            <div class="mb-4 flex gap-2">
                <Button
                    :variant="tab === 'add' ? 'default' : 'outline'"
                    @click="tab = 'add'"
                >
                    Add
                </Button>
                <Button
                    :variant="tab === 'remove' ? 'default' : 'outline'"
                    @click="((tab = 'remove'), loadPositions())"
                >
                    Remove
                </Button>
                <div class="ml-auto flex items-center gap-2 text-sm">
                    <span class="text-muted-foreground">Slippage %</span>
                    <Input v-model="slippage" class="w-16" />
                </div>
            </div>

            <!-- ADD -->
            <div v-if="tab === 'add'" class="space-y-3 rounded-lg border p-4">
                <div
                    v-for="side in ['A', 'B'] as const"
                    :key="side"
                    class="rounded-md border p-3"
                >
                    <div class="mb-2 flex items-center justify-between text-sm">
                        <Select
                            :model-value="side === 'A' ? tokenA : tokenB"
                            @update:model-value="pickToken(side, $event)"
                        >
                            <SelectTrigger
                                class="h-9 w-[170px] border-0 bg-transparent px-2 shadow-none focus:ring-0"
                            >
                                <span
                                    v-if="side === 'A' ? tokenA : tokenB"
                                    class="flex items-center gap-2 font-medium"
                                >
                                    <TokenIcon
                                        :symbol="
                                            symbolOf(
                                                side === 'A' ? tokenA : tokenB,
                                            )
                                        "
                                        :size="20"
                                    />
                                    {{
                                        symbolOf(side === 'A' ? tokenA : tokenB)
                                    }}
                                </span>
                                <SelectValue
                                    v-else
                                    placeholder="Select token"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="t in tokens"
                                    :key="t.address"
                                    :value="t.address"
                                >
                                    <span class="flex items-center gap-2">
                                        <TokenIcon
                                            :symbol="t.symbol"
                                            :size="20"
                                        />
                                        {{ t.symbol }}
                                    </span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <button
                            class="text-xs text-muted-foreground hover:underline"
                            @click="side === 'A' ? setMaxA() : setMaxB()"
                        >
                            Balance:
                            {{
                                fmt(
                                    side === 'A' ? balA : balB,
                                    side === 'A' ? decA : decB,
                                )
                            }}
                            (max)
                        </button>
                    </div>
                    <Input
                        :model-value="side === 'A' ? amountA : amountB"
                        placeholder="0.0"
                        inputmode="decimal"
                        @update:model-value="
                            side === 'A'
                                ? ((amountA = String($event)), recomputeFromA())
                                : ((amountB = String($event)), recomputeFromB())
                        "
                    />
                </div>

                <p v-if="pair && !pair.exists" class="text-xs text-amber-500">
                    New pool — you set the initial price by choosing both
                    amounts.
                </p>

                <Button class="w-full" :disabled="busy" @click="addLiquidity">
                    <Loader2 v-if="busy" class="mr-2 h-4 w-4 animate-spin" />
                    {{
                        wallet.isConnected.value
                            ? 'Add liquidity'
                            : 'Connect wallet'
                    }}
                </Button>

                <div class="flex gap-2 pt-1">
                    <Input
                        v-model="customAddr"
                        placeholder="Add token by 0x address"
                        class="text-xs"
                    />
                    <Button variant="outline" @click="addCustomToken"
                        >Add</Button
                    >
                </div>
            </div>

            <!-- REMOVE -->
            <div v-else class="space-y-3 rounded-lg border p-4">
                <Button
                    v-if="!wallet.isConnected.value"
                    class="w-full"
                    @click="wallet.connect()"
                >
                    Connect wallet
                </Button>

                <template v-else>
                    <p
                        v-if="positionsLoading"
                        class="text-sm text-muted-foreground"
                    >
                        <Loader2 class="mr-1 inline h-4 w-4 animate-spin" />
                        Scanning your positions…
                    </p>
                    <p
                        v-else-if="positions.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No LP positions found for this wallet.
                    </p>

                    <button
                        v-for="p in positions"
                        :key="p.pairAddress"
                        class="flex w-full items-center justify-between rounded-md border p-3 text-left"
                        :class="{
                            'ring-2 ring-primary': selected === p.pairAddress,
                        }"
                        @click="selected = p.pairAddress"
                    >
                        <span class="flex items-center gap-2">
                            <TokenIcon :symbol="p.symbol0" :size="20" />
                            <TokenIcon :symbol="p.symbol1" :size="20" />
                            <span class="font-medium"
                                >{{ p.symbol0 }}/{{ p.symbol1 }}</span
                            >
                        </span>
                        <span class="font-mono text-xs text-muted-foreground">
                            {{ fmt(p.lp, 18) }} LP
                        </span>
                    </button>

                    <template v-if="selectedPosition && pooledOut">
                        <div class="flex items-center gap-2">
                            <Button
                                v-for="pct in [25, 50, 75, 100]"
                                :key="pct"
                                size="sm"
                                :variant="
                                    removePct === pct ? 'default' : 'outline'
                                "
                                @click="removePct = pct"
                            >
                                {{ pct }}%
                            </Button>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            You receive ≈
                            {{ fmt(pooledOut.amount0, selectedPosition.dec0) }}
                            {{ selectedPosition.symbol0 }} +
                            {{ fmt(pooledOut.amount1, selectedPosition.dec1) }}
                            {{ selectedPosition.symbol1 }}
                        </p>
                        <label
                            v-if="positionHasWcyber(selectedPosition)"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input v-model="receiveNative" type="checkbox" />
                            Receive WCYBER as native CYBER
                        </label>
                        <Button
                            class="w-full"
                            :disabled="busy"
                            @click="removeLiquidity"
                        >
                            <Loader2
                                v-if="busy"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            Remove liquidity
                        </Button>
                    </template>
                </template>
            </div>

            <p v-if="status" class="mt-3 text-sm">{{ status }}</p>
            <p v-if="error" class="mt-3 text-sm text-red-500">{{ error }}</p>

            <p class="mt-4 text-xs text-muted-foreground">
                Router
                <a
                    :href="`${EXPLORER}/address/${ROUTER}`"
                    target="_blank"
                    class="underline"
                    >{{ shortAddr(ROUTER) }}</a
                >
            </p>
        </div>
    </div>
</template>
