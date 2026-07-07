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
import { ArrowDownUp, Loader2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
import {
    CYBER_SOL_ADDRESS,
    KNOWN_TOKENS,
    WCYBER_ADDRESS,
    filterJunkPools,
} from '@/lib/cyberiaTokens';
import { getMetaMaskProvider } from '@/lib/evmProvider';

const CYBERIA_CHAIN_ID = 49406;
const CYBERIA_CHAIN_ID_HEX = '0xc0fe';
const CYBERIA_RPC = '/api/rpc/cyberia';
const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';
const EXPLORER = 'https://explorer.cyberia.church';

// Ritual DEX (QuickSwap V2 fork). deployments/cyberia-quickswap.json.
const ROUTER = '0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62';
const WCYBER = WCYBER_ADDRESS;
// Sentinel for native CYBER in the token pickers (maps to WCYBER on-chain).
const NATIVE = 'NATIVE';

const ROUTER_ABI = [
    'function getAmountsOut(uint amountIn, address[] path) view returns (uint[] amounts)',
    'function getAmountsIn(uint amountOut, address[] path) view returns (uint[] amounts)',
    'function swapExactTokensForTokens(uint amountIn,uint amountOutMin,address[] path,address to,uint deadline) returns (uint[])',
    'function swapTokensForExactTokens(uint amountOut,uint amountInMax,address[] path,address to,uint deadline) returns (uint[])',
    'function swapExactETHForTokens(uint amountOutMin,address[] path,address to,uint deadline) payable returns (uint[])',
    'function swapETHForExactTokens(uint amountOut,address[] path,address to,uint deadline) payable returns (uint[])',
    'function swapExactTokensForETH(uint amountIn,uint amountOutMin,address[] path,address to,uint deadline) returns (uint[])',
    'function swapTokensForExactETH(uint amountOut,uint amountInMax,address[] path,address to,uint deadline) returns (uint[])',
];

// Ritual factory — the live pair list backs routing when the indexer table lags.
const FACTORY = '0xB0aC30907c04b61F1482e62eA66eF4562a690917';
const FACTORY_ABI = [
    'function allPairsLength() view returns (uint256)',
    'function allPairs(uint256) view returns (address)',
];
const PAIR_ABI = [
    'function token0() view returns (address)',
    'function token1() view returns (address)',
];
const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
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

const status = ref<string | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);
const slippage = ref('0.5');

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
        // Cyberia RPC accepts at most 20 requests per JSON-RPC batch; path
        // quoting and pair enumeration fan out well past that.
        batchMaxCount: 20,
    },
);
const readRouter = new Contract(ROUTER, ROUTER_ABI, readProvider);

const shortAddr = (a: string): string =>
    a ? `${a.slice(0, 6)}…${a.slice(-4)}` : '';
const slippageBps = computed(() => {
    const v = Math.round(Number(slippage.value || '0') * 100);

    return Number.isFinite(v) && v >= 0 && v < 5000 ? v : 50;
});
const deadline = (): bigint => BigInt(Math.floor(Date.now() / 1000) + 1200);
const resolveAddr = (a: string): string => (a === NATIVE ? WCYBER : a);

const cleanPools = computed(() => filterJunkPools(props.pools ?? []));

const customTokens = ref<Token[]>([]);
const tokens = computed<Token[]>(() => {
    const map = new Map<string, Token>();
    map.set(NATIVE, { address: NATIVE, symbol: 'CYBER', native: true });

    for (const t of KNOWN_TOKENS) {
        map.set(t.address.toLowerCase(), {
            address: t.address,
            symbol: t.symbol,
        });
    }

    for (const p of cleanPools.value) {
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

// --- token metadata cache -----------------------------------------------
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

// --- swap form state ------------------------------------------------------
const tokenIn = ref<string>(NATIVE);
const tokenOut = ref<string>('');
const amountIn = ref('');
const amountOut = ref('');
// Which side the user last edited: 'in' quotes exact-input (getAmountsOut),
// 'out' quotes exact-output (getAmountsIn) so a target buy amount can be
// entered directly.
const mode = ref<'in' | 'out'>('in');
const decIn = ref(18);
const decOut = ref(18);
const balIn = ref<bigint>(0n);
const balOut = ref<bigint>(0n);

type Quote = {
    path: string[];
    amountIn: bigint;
    amountOut: bigint;
    // Pool price move this trade causes, in % (LP fee excluded); null when
    // the spot-rate probe failed.
    impactPct: number | null;
};
const quote = ref<Quote | null>(null);
const quoting = ref(false);

const fmt = (v: bigint, dec: number, digits = 6): string => {
    const s = formatUnits(v, dec);
    const [int, frac = ''] = s.split('.');
    const trimmed = frac.slice(0, digits).replace(/0+$/, '');

    return trimmed ? `${int}.${trimmed}` : int;
};

const parseSide = (raw: string, decimals: number): bigint => {
    try {
        const v = parseUnits(raw.trim() || '0', decimals);

        return v > 0n ? v : 0n;
    } catch {
        return 0n;
    }
};

const amountInBn = computed(() => parseSide(amountIn.value, decIn.value));
const amountOutBn = computed(() => parseSide(amountOut.value, decOut.value));

const minReceived = computed(() =>
    quote.value
        ? (quote.value.amountOut * BigInt(10000 - slippageBps.value)) / 10000n
        : 0n,
);
// Exact-output ceiling on the input side (the router refunds/never pulls more).
const maxSpent = computed(() =>
    quote.value
        ? (quote.value.amountIn * BigInt(10000 + slippageBps.value)) / 10000n
        : 0n,
);

// Uniswap V2 keeps 0.3% of the in-side amount per hop for LP holders.
const lpFee = computed(() => {
    if (!quote.value) {
        return 0n;
    }

    const hops = BigInt(quote.value.path.length - 1);

    return (
        (quote.value.amountIn * (1000n ** hops - 997n ** hops)) / 1000n ** hops
    );
});

const routeSymbols = computed(() =>
    quote.value ? quote.value.path.map((a) => symbolOf(a)) : [],
);

const rate = computed(() => {
    if (!quote.value || quote.value.amountIn === 0n) {
        return null;
    }

    const inWhole = Number(formatUnits(quote.value.amountIn, decIn.value));
    const outWhole = Number(formatUnits(quote.value.amountOut, decOut.value));

    return inWhole > 0 ? outWhole / inWhole : null;
});

const impactClass = computed(() => {
    const v = quote.value?.impactPct;

    if (v === null || v === undefined) {
        return 'text-muted-foreground';
    }

    if (v >= 5) {
        return 'font-semibold text-red-500';
    }

    return v >= 1 ? 'text-amber-500' : 'text-emerald-500';
});

// --- routing: candidate paths over the known pool graph --------------------
// Fallback hubs when the pool table has no route (or is empty).
const HUBS = [
    WCYBER,
    CYBER_SOL_ADDRESS,
    '0xdc25597B19799010047F17e9591EFE08EFd40077', // USDC
    '0x94845aF24a3E431593A2b941b2b31836dE45185D', // USDT
];

// The dex_pools table is fed by an off-chain indexer and can lag the chain by
// weeks (freshly launched pools missing → routes silently ignored). Merge in
// the live factory pair list so new pools are routable immediately.
const chainEdges = ref<[string, string][]>([]);

const loadChainEdges = async (): Promise<void> => {
    try {
        const factory = new Contract(FACTORY, FACTORY_ABI, readProvider);
        const len = Number(await factory.allPairsLength());
        const addrs = (await Promise.all(
            Array.from({ length: len }, (_, i) => factory.allPairs(i)),
        )) as string[];
        chainEdges.value = await Promise.all(
            addrs.map(async (addr) => {
                const pair = new Contract(addr, PAIR_ABI, readProvider);
                const [t0, t1] = await Promise.all([
                    pair.token0() as Promise<string>,
                    pair.token1() as Promise<string>,
                ]);

                return [t0, t1] as [string, string];
            }),
        );
    } catch {
        // Server-provided pools plus hub fallbacks still route.
    }
};

const adjacency = computed(() => {
    const adj = new Map<string, Set<string>>();
    const link = (a: string, b: string): void => {
        const ka = a.toLowerCase();
        const kb = b.toLowerCase();

        if (!adj.has(ka)) {
            adj.set(ka, new Set());
        }

        adj.get(ka)!.add(kb);
    };

    for (const p of cleanPools.value) {
        link(p.token0, p.token1);
        link(p.token1, p.token0);
    }

    for (const [t0, t1] of chainEdges.value) {
        link(t0, t1);
        link(t1, t0);
    }

    return adj;
});

const candidatePaths = (from: string, to: string): string[][] => {
    const a = from.toLowerCase();
    const b = to.toLowerCase();
    const adj = adjacency.value;
    const paths: string[][] = [[a, b]];

    // One intermediate: every X with pools on both sides.
    const nA = adj.get(a) ?? new Set<string>();
    const nB = adj.get(b) ?? new Set<string>();

    for (const x of nA) {
        if (x !== b && nB.has(x)) {
            paths.push([a, x, b]);
        }
    }

    // Two intermediates: X in N(a), Y in N(b) with an X–Y pool.
    for (const x of nA) {
        if (x === b) {
            continue;
        }

        const nX = adj.get(x) ?? new Set<string>();

        for (const y of nB) {
            if (y === a || y === x || !nX.has(y)) {
                continue;
            }

            paths.push([a, x, y, b]);

            if (paths.length >= 40) {
                return paths;
            }
        }
    }

    // Hub fallbacks (dedup below) cover an empty/stale pool table.
    for (const hub of HUBS) {
        const h = hub.toLowerCase();

        if (h !== a && h !== b) {
            paths.push([a, h, b]);
        }
    }

    const seen = new Set<string>();

    return paths.filter((p) => {
        const key = p.join('>');

        if (seen.has(key)) {
            return false;
        }

        seen.add(key);

        return true;
    });
};

// Execution rate vs the marginal (spot) rate on the same path, probed with a
// 1/10000 slice of the input. The 0.3%/hop LP fee cancels out of the ratio,
// so this isolates the price move the trade itself causes.
const priceImpactPct = async (q: {
    path: string[];
    amountIn: bigint;
    amountOut: bigint;
}): Promise<number | null> => {
    const probeIn = q.amountIn / 10000n;

    if (probeIn === 0n) {
        return 0;
    }

    try {
        const amounts = (await readRouter.getAmountsOut(
            probeIn,
            q.path,
        )) as bigint[];
        const probeOut = amounts[amounts.length - 1];

        if (probeOut === 0n) {
            return null;
        }

        const execRate = Number(q.amountOut) / Number(q.amountIn);
        const spotRate = Number(probeOut) / Number(probeIn);
        const impact = (1 - execRate / spotRate) * 100;

        return impact > 0 ? impact : 0;
    } catch {
        return null;
    }
};

let quoteSeq = 0;

const refreshQuote = async (): Promise<void> => {
    quote.value = null;
    error.value = null;

    const exactIn = mode.value === 'in';
    const driving = exactIn ? amountInBn.value : amountOutBn.value;

    if (
        !tokenIn.value ||
        !tokenOut.value ||
        tokenIn.value === tokenOut.value ||
        driving === 0n
    ) {
        // Keep the counterpart field from showing a stale quote.
        if (exactIn) {
            amountOut.value = '';
        } else {
            amountIn.value = '';
        }

        return;
    }

    const seq = ++quoteSeq;
    quoting.value = true;

    try {
        const from = resolveAddr(tokenIn.value);
        const to = resolveAddr(tokenOut.value);

        if (from.toLowerCase() === to.toLowerCase()) {
            return;
        }

        const paths = candidatePaths(from, to);
        // Fired in one tick so ethers batches them (≤20 per RPC batch).
        const results = await Promise.all(
            paths.map(async (path) => {
                try {
                    if (exactIn) {
                        const amounts = (await readRouter.getAmountsOut(
                            driving,
                            path,
                        )) as bigint[];

                        return {
                            path,
                            amountIn: driving,
                            amountOut: amounts[amounts.length - 1],
                        };
                    }

                    const amounts = (await readRouter.getAmountsIn(
                        driving,
                        path,
                    )) as bigint[];

                    return { path, amountIn: amounts[0], amountOut: driving };
                } catch {
                    return null;
                }
            }),
        );

        if (seq !== quoteSeq) {
            return;
        }

        // Best route: most output for exact-in, least input for exact-out.
        let best: Omit<Quote, 'impactPct'> | null = null;

        for (const r of results) {
            if (!r || r.amountOut === 0n || r.amountIn === 0n) {
                continue;
            }

            if (
                !best ||
                (exactIn
                    ? r.amountOut > best.amountOut
                    : r.amountIn < best.amountIn)
            ) {
                best = r;
            }
        }

        if (!best) {
            error.value = 'No route with liquidity found for this pair.';

            if (exactIn) {
                amountOut.value = '';
            } else {
                amountIn.value = '';
            }

            return;
        }

        const impactPct = await priceImpactPct(best);

        if (seq !== quoteSeq) {
            return;
        }

        quote.value = { ...best, impactPct };

        // Fill the side the user did not type. Programmatic writes do not
        // emit update:modelValue, so this cannot re-trigger quoting.
        if (exactIn) {
            amountOut.value = fmt(best.amountOut, decOut.value);
        } else {
            amountIn.value = fmt(best.amountIn, decIn.value, 8);
        }
    } finally {
        if (seq === quoteSeq) {
            quoting.value = false;
        }
    }
};

let quoteTimer: ReturnType<typeof setTimeout> | null = null;
const scheduleQuote = (): void => {
    if (quoteTimer) {
        clearTimeout(quoteTimer);
    }

    quoteTimer = setTimeout(() => void refreshQuote(), 300);
};

// Typing makes that field the exact side; the opposite one shows the quote.
const onAmountEdited = (side: 'in' | 'out'): void => {
    mode.value = side;
    scheduleQuote();
};

watch([tokenIn, tokenOut], scheduleQuote);
// A late-loading pair list can unlock better routes for an existing quote.
watch(chainEdges, scheduleQuote);

// --- balances ---------------------------------------------------------------
const loadBalance = async (token: string): Promise<bigint> => {
    const me = wallet.address.value;

    if (!me || !token) {
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

const loadSide = async (side: 'in' | 'out'): Promise<void> => {
    const token = side === 'in' ? tokenIn.value : tokenOut.value;

    if (!token) {
        if (side === 'in') {
            balIn.value = 0n;
        } else {
            balOut.value = 0n;
        }

        return;
    }

    const [meta, bal] = await Promise.all([
        tokenMeta(token),
        loadBalance(token),
    ]);

    if (side === 'in') {
        decIn.value = meta.decimals;
        balIn.value = bal;
    } else {
        decOut.value = meta.decimals;
        balOut.value = bal;
    }
};
watch([tokenIn, () => wallet.address.value], () => void loadSide('in'), {
    immediate: true,
});
watch([tokenOut, () => wallet.address.value], () => void loadSide('out'), {
    immediate: true,
});

// Balances change outside this page (lending, bridge, transfers, other tabs),
// so refresh them on a slow poll while visible and immediately on return to
// the tab — no page reload needed.
const BALANCE_POLL_MS = 10000;
let balanceTimer: ReturnType<typeof setInterval> | undefined;

const refreshBalances = (): void => {
    void loadSide('in');
    void loadSide('out');
};

const onTabVisible = (): void => {
    if (!document.hidden) {
        refreshBalances();
    }
};

const flip = (): void => {
    const a = tokenIn.value;
    tokenIn.value = tokenOut.value;
    tokenOut.value = a;
    amountIn.value = '';
    amountOut.value = '';
    mode.value = 'in';
    quote.value = null;
};

const setMaxIn = (): void => {
    amountIn.value = balIn.value > 0n ? formatUnits(balIn.value, decIn.value) : '';
    mode.value = 'in';
    scheduleQuote();
};

const pickToken = (side: 'in' | 'out', val: unknown): void => {
    const v = String(val ?? '');

    if (side === 'in') {
        tokenIn.value = v;
    } else {
        tokenOut.value = v;
    }
};

// --- network / execution ------------------------------------------------
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
    amount: bigint,
): Promise<void> => {
    const me = await signer.getAddress();
    const c = new Contract(token, ERC20_ABI, signer);
    const allowance = (await c.allowance(me, ROUTER)) as bigint;

    if (allowance >= amount) {
        return;
    }

    status.value = `Approving ${symbolOf(token)}…`;
    const tx = await c.approve(ROUTER, MaxUint256);
    await tx.wait();
};

const doSwap = async (): Promise<void> => {
    if (!wallet.isConnected.value) {
        await wallet.connect();

        if (!wallet.isConnected.value) {
            return;
        }
    }

    const q = quote.value;

    if (!q || q.amountIn === 0n) {
        error.value = 'Enter an amount and wait for a quote';

        return;
    }

    error.value = null;
    busy.value = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const to = await signer.getAddress();
        const router = new Contract(ROUTER, ROUTER_ABI, signer);
        const exactIn = mode.value === 'in';
        const minOut = minReceived.value;
        const maxIn = maxSpent.value;

        let tx;

        if (tokenIn.value === NATIVE) {
            status.value = 'Confirm the swap in your wallet…';
            tx = exactIn
                ? await router.swapExactETHForTokens(
                      minOut,
                      q.path,
                      to,
                      deadline(),
                      { value: q.amountIn },
                  )
                : // The router refunds whatever part of the max-in value it
                  // does not need at execution time.
                  await router.swapETHForExactTokens(
                      q.amountOut,
                      q.path,
                      to,
                      deadline(),
                      { value: maxIn },
                  );
        } else if (tokenOut.value === NATIVE) {
            await approveIfNeeded(
                signer,
                tokenIn.value,
                exactIn ? q.amountIn : maxIn,
            );
            status.value = 'Confirm the swap in your wallet…';
            tx = exactIn
                ? await router.swapExactTokensForETH(
                      q.amountIn,
                      minOut,
                      q.path,
                      to,
                      deadline(),
                  )
                : await router.swapTokensForExactETH(
                      q.amountOut,
                      maxIn,
                      q.path,
                      to,
                      deadline(),
                  );
        } else {
            await approveIfNeeded(
                signer,
                tokenIn.value,
                exactIn ? q.amountIn : maxIn,
            );
            status.value = 'Confirm the swap in your wallet…';
            tx = exactIn
                ? await router.swapExactTokensForTokens(
                      q.amountIn,
                      minOut,
                      q.path,
                      to,
                      deadline(),
                  )
                : await router.swapTokensForExactTokens(
                      q.amountOut,
                      maxIn,
                      q.path,
                      to,
                      deadline(),
                  );
        }

        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Swapped ${fmt(q.amountIn, decIn.value)} ${symbolOf(tokenIn.value)} → ${fmt(q.amountOut, decOut.value)} ${symbolOf(tokenOut.value)}.`;
        amountIn.value = '';
        amountOut.value = '';
        quote.value = null;
        await Promise.all([loadSide('in'), loadSide('out')]);
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
        status.value = null;
    } finally {
        busy.value = false;
    }
};

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

onMounted(async () => {
    void loadChainEdges();

    balanceTimer = setInterval(() => {
        if (!document.hidden) {
            refreshBalances();
        }
    }, BALANCE_POLL_MS);
    window.addEventListener('focus', onTabVisible);
    document.addEventListener('visibilitychange', onTabVisible);

    // Silently restore the wallet (saved address + eth_accounts, no popup) so
    // balances populate without re-clicking connect, same as Liquidity/Farm.
    await wallet.restore(authUser.value?.wallet_address ?? null);
});

onBeforeUnmount(() => {
    clearInterval(balanceTimer);
    window.removeEventListener('focus', onTabVisible);
    document.removeEventListener('visibilitychange', onTabVisible);
});
</script>

<template>
    <Head title="Cyberia Swap" />

    <div class="mx-auto max-w-xl px-4 py-6">
        <header class="mb-4">
            <h1 class="text-2xl font-bold">Swap</h1>
            <p class="text-sm text-muted-foreground">
                Trade tokens on Ritual (QuickSwap V2 on Cyberia). Native CYBER
                is supported directly.
            </p>
        </header>

        <div class="mb-4 flex items-center justify-end gap-2 text-sm">
            <span class="text-muted-foreground">Slippage %</span>
            <Input v-model="slippage" class="w-16" />
        </div>

        <div class="space-y-3 rounded-lg border p-4">
            <!-- FROM -->
            <div class="rounded-md border p-3">
                <div class="mb-2 flex items-center justify-between text-sm">
                    <Select
                        :model-value="tokenIn"
                        @update:model-value="pickToken('in', $event)"
                    >
                        <SelectTrigger
                            class="h-9 w-[170px] border-0 bg-transparent px-2 shadow-none focus:ring-0"
                        >
                            <span
                                v-if="tokenIn"
                                class="flex items-center gap-2 font-medium"
                            >
                                <TokenIcon :symbol="symbolOf(tokenIn)" :size="20" />
                                {{ symbolOf(tokenIn) }}
                            </span>
                            <SelectValue v-else placeholder="Select token" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="t in tokens"
                                :key="t.address"
                                :value="t.address"
                            >
                                <span class="flex items-center gap-2">
                                    <TokenIcon :symbol="t.symbol" :size="20" />
                                    {{ t.symbol }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <button
                        class="text-xs text-muted-foreground hover:underline"
                        @click="setMaxIn"
                    >
                        Balance: {{ fmt(balIn, decIn) }} (max)
                    </button>
                </div>
                <Input
                    v-model="amountIn"
                    placeholder="0.0"
                    inputmode="decimal"
                    @update:model-value="onAmountEdited('in')"
                />
            </div>

            <!-- flip -->
            <div class="flex justify-center">
                <button
                    type="button"
                    class="rounded-full border border-border p-2 text-muted-foreground transition hover:text-foreground"
                    title="Flip direction"
                    @click="flip"
                >
                    <ArrowDownUp class="h-4 w-4" />
                </button>
            </div>

            <!-- TO -->
            <div class="rounded-md border p-3">
                <div class="mb-2 flex items-center justify-between text-sm">
                    <Select
                        :model-value="tokenOut"
                        @update:model-value="pickToken('out', $event)"
                    >
                        <SelectTrigger
                            class="h-9 w-[170px] border-0 bg-transparent px-2 shadow-none focus:ring-0"
                        >
                            <span
                                v-if="tokenOut"
                                class="flex items-center gap-2 font-medium"
                            >
                                <TokenIcon
                                    :symbol="symbolOf(tokenOut)"
                                    :size="20"
                                />
                                {{ symbolOf(tokenOut) }}
                            </span>
                            <SelectValue v-else placeholder="Select token" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="t in tokens.filter(
                                    (t) => t.address !== tokenIn,
                                )"
                                :key="t.address"
                                :value="t.address"
                            >
                                <span class="flex items-center gap-2">
                                    <TokenIcon :symbol="t.symbol" :size="20" />
                                    {{ t.symbol }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <span class="text-xs text-muted-foreground">
                        Balance: {{ fmt(balOut, decOut) }}
                    </span>
                </div>
                <div class="relative">
                    <Input
                        v-model="amountOut"
                        placeholder="0.0"
                        inputmode="decimal"
                        class="pr-8"
                        @update:model-value="onAmountEdited('out')"
                    />
                    <Loader2
                        v-if="quoting"
                        class="absolute top-1/2 right-2 h-4 w-4 -translate-y-1/2 animate-spin text-muted-foreground"
                    />
                </div>
            </div>

            <!-- quote details -->
            <div
                v-if="quote"
                class="space-y-1 rounded-md bg-muted/40 p-3 text-xs text-muted-foreground"
            >
                <p v-if="rate">
                    1 {{ symbolOf(tokenIn) }} ≈
                    <span class="font-mono">{{
                        rate.toLocaleString(undefined, {
                            maximumSignificantDigits: 6,
                        })
                    }}</span>
                    {{ symbolOf(tokenOut) }}
                </p>
                <p>
                    Price impact:
                    <span class="font-mono" :class="impactClass">
                        {{
                            quote.impactPct === null
                                ? '—'
                                : quote.impactPct < 0.01
                                  ? '<0.01%'
                                  : `${quote.impactPct.toFixed(2)}%`
                        }}
                    </span>
                </p>
                <p>
                    Liquidity Provider Fee:
                    <span class="font-mono">{{ fmt(lpFee, decIn, 8) }}</span>
                    {{ symbolOf(tokenIn) }} (0.3% per hop)
                </p>
                <p v-if="mode === 'in'">
                    Min received:
                    <span class="font-mono">{{
                        fmt(minReceived, decOut)
                    }}</span>
                    {{ symbolOf(tokenOut) }} ({{ slippage }}% slippage)
                </p>
                <p v-else>
                    Max sold:
                    <span class="font-mono">{{
                        fmt(maxSpent, decIn, 8)
                    }}</span>
                    {{ symbolOf(tokenIn) }} ({{ slippage }}% slippage)
                </p>
                <p>Route: {{ routeSymbols.join(' → ') }}</p>
            </div>

            <p
                v-if="quote && quote.impactPct !== null && quote.impactPct >= 5"
                class="rounded-md bg-red-500/10 p-2 text-xs text-red-500"
            >
                High price impact: this trade moves the pool price by
                {{ quote.impactPct.toFixed(1) }}%. Consider a smaller amount.
            </p>

            <Button
                class="w-full"
                :disabled="busy || quoting || !quote"
                @click="doSwap"
            >
                <Loader2 v-if="busy" class="mr-2 h-4 w-4 animate-spin" />
                {{ wallet.isConnected.value ? 'Swap' : 'Connect wallet' }}
            </Button>

            <div class="flex gap-2 pt-1">
                <Input
                    v-model="customAddr"
                    placeholder="Add token by 0x address"
                    class="text-xs"
                />
                <Button variant="outline" @click="addCustomToken">Add</Button>
            </div>
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
            · advanced routing on
            <a
                href="https://swap.cyberia.church/"
                target="_blank"
                class="underline"
                >Ritual DEX</a
            >
        </p>
    </div>
</template>
