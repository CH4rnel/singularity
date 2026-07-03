<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    LogarithmicScale,
    Tooltip,
} from 'chart.js';
import {
    CandlestickController,
    CandlestickElement,
} from 'chartjs-chart-financial';
import {
    BrowserProvider,
    Contract,
    Interface,
    JsonRpcProvider,
    MaxUint256,
    ZeroAddress,
    formatUnits,
    id,
    parseUnits,
} from 'ethers';
import { Loader2 } from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWallet } from '@/composables/useWallet';
import { getMetaMaskProvider } from '@/lib/evmProvider';

// Chart.js
Chart.register(
    CandlestickController,
    CandlestickElement,
    LinearScale,
    LogarithmicScale,
    CategoryScale,
    Tooltip,
    Legend,
);

const CYBERIA_CHAIN_ID = 49406;
const CYBERIA_CHAIN_ID_HEX = '0xc11e';
const CYBERIA_RPC = '/api/rpc/cyberia';
const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';

const CYBER_SOL_ADDRESS = '0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA';

const LAUNCHPAD_ADDRESS =
    ((import.meta as { env?: Record<string, string | undefined> }).env
        ?.VITE_LAUNCHPAD_ADDRESS as string | undefined) ?? '';

const LAUNCHPAD_ABI = [
    'function cyberSol() view returns (address)',
    'function minLiquidity() view returns (uint256)',
    'function allTokensLength() view returns (uint256)',
    'function allTokens(uint256) view returns (address)',
    'function launch(string,string,uint256,uint256) returns (address,address,uint256)',
    'event TokenLaunched(address indexed token, address indexed creator, address pair, string name, string symbol, uint256 tokenSupply, uint256 cyberSolLiquidity, uint256 lpBurned)',
];

const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
];

const PAIR_ABI = [
    'event Sync(uint112 reserve0, uint112 reserve1)',
    'event Swap(address indexed sender,uint256 amount0In,uint256 amount1In,uint256 amount0Out,uint256 amount1Out,address indexed to)',
    'function token0() view returns (address)',
    'function token1() view returns (address)',
    'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)',
];

const FACTORY_ABI = [
    'function getPair(address tokenA, address tokenB) view returns (address)',
];
const FACTORY_ADDRESS = '0xB0aC30907c04b61F1482e62eA66eF4562a690917';
const LIQUIDITY_PAIR_OVERRIDES: Record<
    string,
    { pair: string; quoteSymbol: string }
> = {
    '0x05cd1afd5b2df3cca6ceab80cbc21168ec981e8b': {
        pair: '0x9298d13f57D1e5bD14C443144b500aaa210a1175',
        quoteSymbol: 'WCYBER',
    },
    '0xd8c1f812add03ccde8d3c7f86fead181980cd7ec': {
        pair: '0xF18bA050eFF63B2be2D244A423691D44BDDeF60d',
        quoteSymbol: 'WCYBER',
    },
};

const ERC20_READ_ABI = [
    'function name() view returns (string)',
    'function symbol() view returns (string)',
    'function totalSupply() view returns (uint256)',
];

const SWAP_BASE_URL = 'https://swap.cyberia.church/#/swap';
const EXPLORER_BASE_URL = 'https://explorer.cyberia.church';
const SWAP_TOPIC = id('Swap(address,uint256,uint256,uint256,uint256,address)');
const SYNC_TOPIC = id('Sync(uint112,uint112)');
const TOKEN_LAUNCHED_TOPIC = id(
    'TokenLaunched(address,address,address,string,string,uint256,uint256,uint256)',
);

type LaunchedToken = {
    token: string;
    pair: string;
    creator: string;
    name: string;
    symbol: string;
    tokenSupply: bigint;
    cyberSolLiquidity: bigint;
    quoteSymbol: string;
    txHash?: string;
    // Enriched off-chain.
    description?: string | null;
    imageUrl?: string | null;
    siteUrl?: string | null;
    // Enriched from pair reserves (price quoted in the pair's quote asset).
    priceCyber?: number | null;
    marketCapCyber?: number | null;
    launchBlock?: number | null;
};

type LaunchpadMetadata = {
    address: string;
    creator: string | null;
    name: string | null;
    symbol: string | null;
    description: string | null;
    image_url: string | null;
    site_url: string | null;
};

type LaunchEventMeta = {
    creator: string;
    pair: string;
    blockNumber: number;
    txHash: string;
};

type TokenTradeCandle = {
    x: number;
    o: number;
    h: number;
    l: number;
    c: number;
    label: string;
    blockNumber: number;
    transactionHash: string;
    logIndex: number;
};

type ExplorerLog = {
    transaction_hash: string;
    block_number: number;
    index: number;
    topics: (string | null)[];
    data: string;
};

type ExplorerLogsResponse = {
    items: ExplorerLog[];
    next_page_params: Record<string, string | number> | null;
};

const wallet = useWallet();

// Chart instances map
const chartInstances = new Map<string, Chart<'candlestick'>>();
const priceHistories = ref<Record<string, TokenTradeCandle[]>>({});

const name = ref('');
const symbol = ref('');
const totalSupply = ref('1000000');
const cyberSolLiquidity = ref('10000');
const description = ref('');
const imageFile = ref<File | null>(null);
const imagePreview = ref<string | null>(null);
const htmlFile = ref<File | null>(null);
const htmlFileName = ref<string | null>(null);

const MAX_IMAGE_BYTES = 2 * 1024 * 1024;
const MAX_HTML_BYTES = 2 * 1024 * 1024;

const onHtmlChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    error.value = null;

    if (file && file.size > MAX_HTML_BYTES) {
        error.value = `HTML page must be ≤ 2 MB (got ${(file.size / 1024 / 1024).toFixed(2)} MB)`;
        target.value = '';
        htmlFile.value = null;
        htmlFileName.value = null;

        return;
    }

    htmlFile.value = file;
    htmlFileName.value = file?.name ?? null;
};

const onImageChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    error.value = null;

    if (file && file.size > MAX_IMAGE_BYTES) {
        error.value = `Image must be ≤ 2 MB (got ${(file.size / 1024 / 1024).toFixed(2)} MB)`;
        target.value = '';
        imageFile.value = null;

        return;
    }

    imageFile.value = file;

    if (imagePreview.value) {
        URL.revokeObjectURL(imagePreview.value);
        imagePreview.value = null;
    }

    if (file) {
        imagePreview.value = URL.createObjectURL(file);
    }
};

const minLiquidity = ref<bigint>(0n);
const cyberSolBalance = ref<bigint>(0n);
const cyberSolAllowance = ref<bigint>(0n);
const recent = ref<LaunchedToken[]>([]);

const busy = ref(false);
const status = ref<string | null>(null);
const error = ref<string | null>(null);
const txHash = ref<string | null>(null);

// Use the same-origin proxy at /api/rpc/cyberia — the public Cyberia RPC
// blocks browser CORS and the app may be served over HTTPS (mixed-content guard).
// ethers v6's FetchRequest needs an absolute URL, so prepend window.location.origin.
const readRpcUrl =
    typeof window !== 'undefined'
        ? window.location.origin + CYBERIA_RPC
        : CYBERIA_PUBLIC_RPC;
const readProvider = new JsonRpcProvider(readRpcUrl, {
    chainId: CYBERIA_CHAIN_ID,
    name: 'cyberia',
});

const isConfigured = computed(() => !!LAUNCHPAD_ADDRESS);

const liquidityBn = computed(() => {
    try {
        return parseUnits(cyberSolLiquidity.value || '0', 18);
    } catch {
        return 0n;
    }
});

const supplyBn = computed(() => {
    try {
        return parseUnits(totalSupply.value || '0', 18);
    } catch {
        return 0n;
    }
});

const needsApprove = computed(
    () => liquidityBn.value > 0n && cyberSolAllowance.value < liquidityBn.value,
);

const insufficientBalance = computed(
    () => liquidityBn.value > 0n && cyberSolBalance.value < liquidityBn.value,
);

const belowMin = computed(
    () => minLiquidity.value > 0n && liquidityBn.value < minLiquidity.value,
);

const canLaunch = computed(
    () =>
        !busy.value &&
        wallet.isConnected.value &&
        isConfigured.value &&
        name.value.trim().length > 0 &&
        symbol.value.trim().length > 0 &&
        supplyBn.value > 0n &&
        liquidityBn.value > 0n &&
        !insufficientBalance.value &&
        !belowMin.value,
);

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
            const code = (e as { code?: number }).code;

            if (code === 4902) {
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

const loadOnchain = async (): Promise<void> => {
    if (!isConfigured.value) {
        return;
    }

    const launchpad = new Contract(
        LAUNCHPAD_ADDRESS,
        LAUNCHPAD_ABI,
        readProvider,
    );
    const cyber = new Contract(CYBER_SOL_ADDRESS, ERC20_ABI, readProvider);

    try {
        minLiquidity.value = await launchpad.minLiquidity();
    } catch {
        minLiquidity.value = 0n;
    }

    if (wallet.address.value) {
        try {
            cyberSolBalance.value = await cyber.balanceOf(wallet.address.value);
            cyberSolAllowance.value = await cyber.allowance(
                wallet.address.value,
                LAUNCHPAD_ADDRESS,
            );
        } catch {
            // ignore
        }
    }
};

const fetchMetadata = async (): Promise<Map<string, LaunchpadMetadata>> => {
    try {
        const res = await fetch('/api/launchpad/tokens', {
            headers: { Accept: 'application/json' },
        });

        if (!res.ok) {
            return new Map();
        }

        const data = (await res.json()) as { tokens: LaunchpadMetadata[] };
        const map = new Map<string, LaunchpadMetadata>();

        for (const t of data.tokens) {
            map.set(t.address.toLowerCase(), t);
        }

        return map;
    } catch {
        return new Map();
    }
};

const fetchAddressLogs = async (
    address: string,
    topic: string,
): Promise<ExplorerLog[]> => {
    const logs: ExplorerLog[] = [];
    let nextPage: Record<string, string | number> | null = null;

    do {
        const query = new URLSearchParams({ topic });

        if (nextPage) {
            Object.entries(nextPage).forEach(([key, value]) => {
                query.set(key, String(value));
            });
        }

        const response = await fetch(
            `${EXPLORER_BASE_URL}/api/v2/addresses/${address}/logs?${query.toString()}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            throw new Error(
                `Explorer logs request failed (${response.status})`,
            );
        }

        const page = (await response.json()) as ExplorerLogsResponse;
        logs.push(...(Array.isArray(page.items) ? page.items : []));
        nextPage = page.next_page_params;
    } while (nextPage);

    return logs;
};

const fetchLaunchEvents = async (): Promise<Map<string, LaunchEventMeta>> => {
    const map = new Map<string, LaunchEventMeta>();

    if (!isConfigured.value) {
        return map;
    }

    try {
        const iface = new Interface(LAUNCHPAD_ABI);
        const logs = await fetchAddressLogs(
            LAUNCHPAD_ADDRESS,
            TOKEN_LAUNCHED_TOPIC,
        );

        for (const log of logs) {
            if (log.topics[0]?.toLowerCase() !== TOKEN_LAUNCHED_TOPIC) {
                continue;
            }

            try {
                const parsed = iface.parseLog({
                    topics: log.topics.filter(
                        (topic): topic is string => topic !== null,
                    ),
                    data: log.data,
                });

                if (parsed?.name !== 'TokenLaunched') {
                    continue;
                }

                const token = String(parsed.args.token).toLowerCase();
                map.set(token, {
                    creator: String(parsed.args.creator).toLowerCase(),
                    pair: String(parsed.args.pair),
                    blockNumber: log.block_number,
                    txHash: log.transaction_hash,
                });
            } catch {
                // Ignore logs that don't match the current ABI shape.
            }
        }
    } catch (e) {
        console.warn('[Launchpad] launch event history failed', e);
    }

    return map;
};

const priceFromReserves = (
    reserve0: bigint,
    reserve1: bigint,
    tokenIsToken0: boolean,
): {
    priceCyber: number;
    reserveCyber: bigint;
    reserveToken: bigint;
} | null => {
    const reserveToken = tokenIsToken0 ? reserve0 : reserve1;
    const reserveCyber = tokenIsToken0 ? reserve1 : reserve0;

    if (reserveToken === 0n || reserveCyber === 0n) {
        return null;
    }

    const tokenWhole = Number(formatUnits(reserveToken, 18));
    const cyberWhole = Number(formatUnits(reserveCyber, 18));

    if (!isFinite(tokenWhole) || tokenWhole <= 0 || !isFinite(cyberWhole)) {
        return null;
    }

    return {
        priceCyber: cyberWhole / tokenWhole,
        reserveCyber,
        reserveToken,
    };
};

const readPairPrice = async (
    pairAddr: string,
    tokenAddr: string,
): Promise<{
    priceCyber: number;
    reserveCyber: bigint;
    reserveToken: bigint;
    tokenIsToken0: boolean;
} | null> => {
    try {
        const pair = new Contract(pairAddr, PAIR_ABI, readProvider);
        const [token0, reserves] = await Promise.all([
            pair.token0(),
            pair.getReserves(),
        ]);
        const tokenIsToken0 =
            String(token0).toLowerCase() === tokenAddr.toLowerCase();
        const price = priceFromReserves(
            reserves[0] as bigint,
            reserves[1] as bigint,
            tokenIsToken0,
        );

        return price ? { ...price, tokenIsToken0 } : null;
    } catch {
        return null;
    }
};

const tradePriceFromSwap = (
    amount0In: bigint,
    amount1In: bigint,
    amount0Out: bigint,
    amount1Out: bigint,
    tokenIsToken0: boolean,
): number | null => {
    const tokenAmount = tokenIsToken0
        ? amount0In + amount0Out
        : amount1In + amount1Out;
    const cyberAmount = tokenIsToken0
        ? amount1In + amount1Out
        : amount0In + amount0Out;

    if (tokenAmount <= 0n || cyberAmount <= 0n) {
        return null;
    }

    const tokenWhole = Number(formatUnits(tokenAmount, 18));
    const cyberWhole = Number(formatUnits(cyberAmount, 18));

    if (
        !isFinite(tokenWhole) ||
        tokenWhole <= 0 ||
        !isFinite(cyberWhole) ||
        cyberWhole <= 0
    ) {
        return null;
    }

    return cyberWhole / tokenWhole;
};

const readPairTradeHistory = async (
    pairAddr: string,
    tokenIsToken0: boolean,
): Promise<TokenTradeCandle[]> => {
    if (!pairAddr || pairAddr === ZeroAddress) {
        return [];
    }

    try {
        const iface = new Interface(PAIR_ABI);
        const [swapLogs, syncLogs] = await Promise.all([
            fetchAddressLogs(pairAddr, SWAP_TOPIC),
            fetchAddressLogs(pairAddr, SYNC_TOPIC),
        ]);
        const logs = swapLogs
            .filter((log) => log.topics[0]?.toLowerCase() === SWAP_TOPIC)
            .sort(
                (left, right) =>
                    left.block_number - right.block_number ||
                    left.index - right.index,
            );
        const syncsByTransaction = new Map<string, ExplorerLog[]>();

        syncLogs.forEach((log) => {
            const key = log.transaction_hash.toLowerCase();
            const transactionSyncs = syncsByTransaction.get(key) ?? [];
            transactionSyncs.push(log);
            syncsByTransaction.set(key, transactionSyncs);
        });

        const candles: TokenTradeCandle[] = [];
        let previousClose: number | null = null;

        logs.forEach((log) => {
            const candle = (() => {
                try {
                    const parsed = iface.parseLog({
                        topics: log.topics.filter(
                            (topic): topic is string => topic !== null,
                        ),
                        data: log.data,
                    });

                    if (parsed?.name !== 'Swap') {
                        return null;
                    }

                    const amount0In = parsed.args.amount0In as bigint;
                    const amount1In = parsed.args.amount1In as bigint;
                    const amount0Out = parsed.args.amount0Out as bigint;
                    const amount1Out = parsed.args.amount1Out as bigint;
                    const executionPrice = tradePriceFromSwap(
                        amount0In,
                        amount1In,
                        amount0Out,
                        amount1Out,
                        tokenIsToken0,
                    );
                    const matchingSync = syncsByTransaction
                        .get(log.transaction_hash.toLowerCase())
                        ?.filter((sync) => sync.index < log.index)
                        .sort((left, right) => right.index - left.index)[0];
                    let open = previousClose ?? executionPrice;
                    let close = executionPrice;

                    if (matchingSync) {
                        const parsedSync = iface.parseLog({
                            topics: matchingSync.topics.filter(
                                (topic): topic is string => topic !== null,
                            ),
                            data: matchingSync.data,
                        });

                        if (parsedSync?.name === 'Sync') {
                            const reserve0After = parsedSync.args
                                .reserve0 as bigint;
                            const reserve1After = parsedSync.args
                                .reserve1 as bigint;
                            const reserve0Before =
                                reserve0After - amount0In + amount0Out;
                            const reserve1Before =
                                reserve1After - amount1In + amount1Out;
                            const before = priceFromReserves(
                                reserve0Before,
                                reserve1Before,
                                tokenIsToken0,
                            );
                            const after = priceFromReserves(
                                reserve0After,
                                reserve1After,
                                tokenIsToken0,
                            );

                            open = before?.priceCyber ?? open;
                            close = after?.priceCyber ?? close;
                        }
                    }

                    if (open === null || close === null) {
                        return null;
                    }

                    return {
                        x: candles.length + 1,
                        o: open,
                        h: Math.max(open, close),
                        l: Math.min(open, close),
                        c: close,
                        label: `Trade ${candles.length + 1} · #${log.block_number.toLocaleString()}`,
                        blockNumber: log.block_number,
                        transactionHash: log.transaction_hash,
                        logIndex: log.index,
                    };
                } catch {
                    return null;
                }
            })();

            if (candle) {
                candles.push(candle);
                previousClose = candle.c;
            }
        });

        return candles;
    } catch (e) {
        console.warn('[Launchpad] pair trade history failed', e);

        return [];
    }
};

const loadRecent = async (): Promise<void> => {
    if (!isConfigured.value) {
        return;
    }

    try {
        const launchpad = new Contract(
            LAUNCHPAD_ADDRESS,
            LAUNCHPAD_ABI,
            readProvider,
        );
        const factory = new Contract(
            FACTORY_ADDRESS,
            FACTORY_ABI,
            readProvider,
        );

        const lengthBn = (await launchpad.allTokensLength()) as bigint;
        const length = Number(lengthBn);

        if (length === 0) {
            recent.value = [];
            priceHistories.value = {};
            await renderTokenCharts();

            return;
        }

        // Newest first, cap at 25.
        const start = Math.max(0, length - 25);
        const indices: number[] = [];

        for (let i = length - 1; i >= start; i--) {
            indices.push(i);
        }

        const addresses = (await Promise.all(
            indices.map((i) => launchpad.allTokens(i)),
        )) as string[];

        const launchEvents = await fetchLaunchEvents();

        const perTokenData = await Promise.all(
            addresses.map(async (tokenAddr) => {
                const erc20 = new Contract(
                    tokenAddr,
                    ERC20_READ_ABI,
                    readProvider,
                );
                const launchEvent = launchEvents.get(tokenAddr.toLowerCase());
                const [name_, symbol_, totalSupply_, pairAddrFromFactory] =
                    await Promise.all([
                        erc20.name().catch(() => '') as Promise<string>,
                        erc20.symbol().catch(() => '') as Promise<string>,
                        erc20.totalSupply().catch(() => 0n) as Promise<bigint>,
                        factory
                            .getPair(tokenAddr, CYBER_SOL_ADDRESS)
                            .catch(() => ZeroAddress) as Promise<string>,
                    ]);
                const pairOverride =
                    LIQUIDITY_PAIR_OVERRIDES[tokenAddr.toLowerCase()];
                const pairAddr =
                    pairOverride?.pair ??
                    (launchEvent?.pair && launchEvent.pair !== ZeroAddress
                        ? launchEvent.pair
                        : pairAddrFromFactory);
                const quoteSymbol = pairOverride?.quoteSymbol ?? 'CYBER.sol';
                let reserveCyber = 0n;
                let priceCyber: number | null = null;
                let tokenIsToken0: boolean | null = null;

                if (pairAddr && pairAddr !== ZeroAddress) {
                    const p = await readPairPrice(pairAddr, tokenAddr);

                    if (p) {
                        priceCyber = p.priceCyber;
                        reserveCyber = p.reserveCyber;
                        tokenIsToken0 = p.tokenIsToken0;
                    }
                }

                return {
                    name_,
                    symbol_,
                    totalSupply_,
                    pairAddr,
                    quoteSymbol,
                    reserveCyber,
                    priceCyber,
                    tokenIsToken0,
                    launchBlock: launchEvent?.blockNumber ?? null,
                    eventCreator: launchEvent?.creator ?? '',
                };
            }),
        );

        const metadata = await fetchMetadata();

        recent.value = addresses.map((tokenAddr, i) => {
            const d = perTokenData[i];
            const md = metadata.get(tokenAddr.toLowerCase());
            const supplyWhole = Number(formatUnits(d.totalSupply_, 18));

            return {
                token: tokenAddr,
                pair: d.pairAddr,
                creator: md?.creator ?? d.eventCreator,
                name: d.name_ || md?.name || '',
                symbol: d.symbol_ || md?.symbol || '',
                tokenSupply: d.totalSupply_,
                cyberSolLiquidity: d.reserveCyber,
                quoteSymbol: d.quoteSymbol,
                description: md?.description ?? null,
                imageUrl: md?.image_url ?? null,
                siteUrl: md?.site_url ?? null,
                priceCyber: d.priceCyber,
                marketCapCyber:
                    d.priceCyber != null ? d.priceCyber * supplyWhole : null,
                launchBlock: d.launchBlock,
            };
        });

        const nextHistories: Record<string, TokenTradeCandle[]> = {};
        recent.value.forEach((t) => {
            nextHistories[t.token.toLowerCase()] = [];
        });
        priceHistories.value = nextHistories;
        await renderTokenCharts();

        void loadPriceHistories(
            recent.value.map((t, i) => ({
                token: t,
                tokenIsToken0: perTokenData[i].tokenIsToken0,
            })),
        );
    } catch (e) {
        console.warn('[Launchpad] loadRecent failed', e);
    }
};

const loadPriceHistories = async (
    tokens: { token: LaunchedToken; tokenIsToken0: boolean | null }[],
): Promise<void> => {
    for (const { token, tokenIsToken0 } of tokens) {
        if (tokenIsToken0 === null) {
            continue;
        }

        const points = await readPairTradeHistory(token.pair, tokenIsToken0);
        priceHistories.value = {
            ...priceHistories.value,
            [token.token.toLowerCase()]: points,
        };
        await renderTokenCharts();
    }
};

const buildMetadataMessage = (tokenAddress: string): string =>
    `Edit Cyberia Launchpad metadata for ${tokenAddress.toLowerCase()} at ${new Date().toISOString()}`;

const signMetadataMessage = async (
    tokenAddress: string,
): Promise<{ message: string; signature: string }> => {
    const provider = await ensureCyberiaNetwork();
    const signer = await provider.getSigner();
    const message = buildMetadataMessage(tokenAddress);
    const signature = await signer.signMessage(message);

    return { message, signature };
};

const submitMetadata = async (tokenAddress: string): Promise<void> => {
    if (!description.value.trim() && !imageFile.value && !htmlFile.value) {
        return;
    }

    const { message, signature } = await signMetadataMessage(tokenAddress);
    const form = new FormData();
    form.append('address', tokenAddress);
    form.append('message', message);
    form.append('signature', signature);

    if (name.value.trim()) {
        form.append('name', name.value.trim());
    }

    if (symbol.value.trim()) {
        form.append('symbol', symbol.value.trim());
    }

    if (description.value.trim()) {
        form.append('description', description.value.trim());
    }

    if (imageFile.value) {
        form.append('image', imageFile.value);
    }

    if (htmlFile.value) {
        form.append('html', htmlFile.value);
    }

    const res = await fetch('/api/launchpad/tokens', {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: form,
    });

    if (!res.ok) {
        const text = await res.text().catch(() => '');

        throw new Error(
            `Metadata upload failed: HTTP ${res.status} ${text.slice(0, 300)}`,
        );
    }
};

const swapUrlFor = (tokenAddress: string): string =>
    `${SWAP_BASE_URL}?inputCurrency=${CYBER_SOL_ADDRESS}&outputCurrency=${tokenAddress}`;

const explorerAddressUrl = (address: string): string =>
    `${EXPLORER_BASE_URL}/address/${address}`;

// Only the creator can edit. If no creator is set yet (unclaimed metadata),
// any connected wallet is allowed to take the first turn — the backend records
// the first signer as the canonical creator.
const canEdit = (t: LaunchedToken): boolean => {
    if (!wallet.isConnected.value || !wallet.address.value) {
        return false;
    }

    if (!t.creator) {
        return true;
    }

    return t.creator.toLowerCase() === wallet.address.value.toLowerCase();
};

// Per-row inline editor state for attaching metadata to already-launched tokens.
const editingToken = ref<string | null>(null);
const editDescription = ref('');
const editImageFile = ref<File | null>(null);
const editImagePreview = ref<string | null>(null);
const editHtmlFile = ref<File | null>(null);
const editHtmlFileName = ref<string | null>(null);
const editBusy = ref(false);
const editError = ref<string | null>(null);

const openEditor = (t: LaunchedToken): void => {
    editingToken.value = t.token;
    editDescription.value = t.description ?? '';
    editImageFile.value = null;
    editHtmlFile.value = null;
    editHtmlFileName.value = null;

    if (editImagePreview.value) {
        URL.revokeObjectURL(editImagePreview.value);
        editImagePreview.value = null;
    }

    editError.value = null;
};

const closeEditor = (): void => {
    editingToken.value = null;
    editDescription.value = '';
    editImageFile.value = null;
    editHtmlFile.value = null;
    editHtmlFileName.value = null;

    if (editImagePreview.value) {
        URL.revokeObjectURL(editImagePreview.value);
        editImagePreview.value = null;
    }

    editError.value = null;
};

const onEditHtmlChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    editError.value = null;

    if (file && file.size > MAX_HTML_BYTES) {
        editError.value = `HTML page must be ≤ 2 MB (got ${(file.size / 1024 / 1024).toFixed(2)} MB)`;
        target.value = '';
        editHtmlFile.value = null;
        editHtmlFileName.value = null;

        return;
    }

    editHtmlFile.value = file;
    editHtmlFileName.value = file?.name ?? null;
};

const onEditImageChange = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    editError.value = null;

    if (file && file.size > MAX_IMAGE_BYTES) {
        editError.value = `Image must be ≤ 2 MB (got ${(file.size / 1024 / 1024).toFixed(2)} MB)`;
        target.value = '';
        editImageFile.value = null;

        return;
    }

    editImageFile.value = file;

    if (editImagePreview.value) {
        URL.revokeObjectURL(editImagePreview.value);
        editImagePreview.value = null;
    }

    if (file) {
        editImagePreview.value = URL.createObjectURL(file);
    }
};

const saveEditor = async (t: LaunchedToken): Promise<void> => {
    editBusy.value = true;
    editError.value = null;

    try {
        if (!wallet.isConnected.value) {
            throw new Error(
                'Connect your wallet first — editing requires a signature from the token creator.',
            );
        }

        const { message, signature } = await signMetadataMessage(t.token);
        const form = new FormData();
        form.append('address', t.token);
        form.append('message', message);
        form.append('signature', signature);

        if (t.name) {
            form.append('name', t.name);
        }

        if (t.symbol) {
            form.append('symbol', t.symbol);
        }

        form.append('description', editDescription.value.trim());

        if (editImageFile.value) {
            form.append('image', editImageFile.value);
        }

        if (editHtmlFile.value) {
            form.append('html', editHtmlFile.value);
        }

        const res = await fetch('/api/launchpad/tokens', {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: form,
        });

        if (!res.ok) {
            const text = await res.text();

            throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
        }

        closeEditor();
        await loadRecent();
    } catch (e) {
        editError.value = (e as Error).message ?? String(e);
    } finally {
        editBusy.value = false;
    }
};

const handleApprove = async (): Promise<void> => {
    error.value = null;
    status.value = null;
    busy.value = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const cyber = new Contract(CYBER_SOL_ADDRESS, ERC20_ABI, signer);
        status.value = 'Confirm allowance in your wallet…';
        const tx = await cyber.approve(LAUNCHPAD_ADDRESS, MaxUint256);
        status.value = 'Waiting for transaction…';
        await tx.wait();
        status.value = 'Allowance confirmed.';
        await loadOnchain();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        busy.value = false;
    }
};

const handleLaunch = async (): Promise<void> => {
    error.value = null;
    status.value = null;
    txHash.value = null;
    busy.value = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const launchpad = new Contract(
            LAUNCHPAD_ADDRESS,
            LAUNCHPAD_ABI,
            signer,
        );
        const iface = new Interface(LAUNCHPAD_ABI);
        status.value = 'Confirm launch() in your wallet…';
        const tx = await launchpad.launch(
            name.value.trim(),
            symbol.value.trim(),
            supplyBn.value,
            liquidityBn.value,
        );
        txHash.value = tx.hash;
        status.value = 'Transaction sent, waiting for block…';
        const receipt = await tx.wait();

        // Pull the deployed token address out of TokenLaunched (first indexed arg).
        let newTokenAddress: string | null = null;

        for (const log of receipt?.logs ?? []) {
            try {
                const parsed = iface.parseLog(log);

                if (parsed?.name === 'TokenLaunched') {
                    newTokenAddress = parsed.args.token as string;
                    break;
                }
            } catch {
                // not our event
            }
        }

        if (description.value.trim() || imageFile.value || htmlFile.value) {
            if (!newTokenAddress) {
                throw new Error(
                    'Launch succeeded but the TokenLaunched event was not found in the receipt — metadata not uploaded.',
                );
            }

            status.value = 'Uploading metadata…';
            await submitMetadata(newTokenAddress);
        }

        status.value = 'Done! Token launched, LP burned.';
        name.value = '';
        symbol.value = '';
        description.value = '';
        imageFile.value = null;
        htmlFile.value = null;
        htmlFileName.value = null;

        if (imagePreview.value) {
            URL.revokeObjectURL(imagePreview.value);
            imagePreview.value = null;
        }

        await loadOnchain();
        await loadRecent();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        busy.value = false;
    }
};

const fmt = (v: bigint, decimals = 18): string => {
    const s = formatUnits(v, decimals);
    const n = Number(s);

    if (!isFinite(n)) {
        return s;
    }

    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toFixed(2)}M`;
    }

    if (n >= 1_000) {
        return `${(n / 1_000).toFixed(2)}K`;
    }

    return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
};

const short = (a: string): string => `${a.slice(0, 6)}…${a.slice(-4)}`;

const formatNum = (n: number): string => {
    if (!isFinite(n)) {
        return '—';
    }

    if (n === 0) {
        return '0';
    }

    if (n >= 1_000_000_000) {
        return `${(n / 1_000_000_000).toFixed(2)}B`;
    }

    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toFixed(2)}M`;
    }

    if (n >= 1_000) {
        return `${(n / 1_000).toFixed(2)}K`;
    }

    return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
};

const formatPrice = (n: number): string => {
    if (!isFinite(n) || n <= 0) {
        return '0';
    }

    if (n < 1e-6) {
        return n.toExponential(2);
    }

    if (n < 1) {
        return n.toPrecision(4);
    }

    return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
};

const chartKey = (tokenAddress: string): string => tokenAddress.toLowerCase();

const chartId = (tokenAddress: string): string =>
    `launchpad-price-chart-${chartKey(tokenAddress)}`;

const chartPointsFor = (t: LaunchedToken): TokenTradeCandle[] =>
    priceHistories.value[chartKey(t.token)] ?? [];

const destroyMissingCharts = (): void => {
    const active = new Set(recent.value.map((t) => chartKey(t.token)));

    for (const [key, chart] of chartInstances.entries()) {
        if (!active.has(key)) {
            chart.destroy();
            chartInstances.delete(key);
        }
    }
};

function updateTokenChart(
    tokenAddress: string,
    points: TokenTradeCandle[],
    quoteSymbol: string,
): void {
    const key = chartKey(tokenAddress);
    const existing = chartInstances.get(key);

    if (points.length === 0) {
        if (existing) {
            existing.destroy();
            chartInstances.delete(key);
        }

        return;
    }

    const canvas = document.getElementById(
        chartId(tokenAddress),
    ) as HTMLCanvasElement | null;

    if (!canvas) {
        return;
    }

    const candles = points.map(({ x, o, h, l, c }) => ({ x, o, h, l, c }));
    const lowest = Math.min(...points.map((point) => point.l));
    const highest = Math.max(...points.map((point) => point.h));
    const logPriceSpan = Math.log(highest / lowest) || Math.log(1.05);
    const scalePadding = Math.exp(logPriceSpan * 0.06);
    const yMin = lowest / scalePadding;
    const yMax = highest * scalePadding;

    if (existing) {
        existing.data.datasets[0].data = candles;
        existing.update('none');

        return;
    }

    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return;
    }

    const chart = new Chart<'candlestick'>(ctx, {
        type: 'candlestick',
        data: {
            datasets: [
                {
                    label: `Trade price (${quoteSymbol})`,
                    data: candles,
                    borderColors: {
                        up: '#22c55e',
                        down: '#ef4444',
                        unchanged: '#94a3b8',
                    },
                    backgroundColors: {
                        up: 'rgba(34, 197, 94, 0.55)',
                        down: 'rgba(239, 68, 68, 0.55)',
                        unchanged: 'rgba(148, 163, 184, 0.55)',
                    },
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (contexts) => {
                            const point = points[contexts[0]?.dataIndex ?? -1];

                            return point?.label ?? '';
                        },
                        label: (context) => {
                            const point = points[context.dataIndex];

                            if (!point) {
                                return '';
                            }

                            return [
                                `Open: ${formatPrice(point.o)} ${quoteSymbol}`,
                                `High: ${formatPrice(point.h)} ${quoteSymbol}`,
                                `Low: ${formatPrice(point.l)} ${quoteSymbol}`,
                                `Close: ${formatPrice(point.c)} ${quoteSymbol}`,
                                `Tx: ${short(point.transactionHash)}`,
                            ];
                        },
                    },
                },
            },
            scales: {
                x: {
                    type: 'linear',
                    display: false,
                    grid: { display: false },
                },
                y: {
                    type: 'logarithmic',
                    min: yMin,
                    max: yMax,
                    grid: { color: 'rgba(148, 163, 184, 0.12)' },
                    ticks: {
                        color: '#94a3b8',
                        maxTicksLimit: 4,
                        callback: (value) => formatPrice(Number(value)),
                    },
                },
            },
        },
    });
    chartInstances.set(key, chart);
}

const renderTokenCharts = async (): Promise<void> => {
    await nextTick();
    destroyMissingCharts();
    recent.value.forEach((t) => {
        updateTokenChart(t.token, chartPointsFor(t), t.quoteSymbol);
    });
};

// Reload balance & allowance whenever the connected address changes.
watch(
    () => wallet.address.value,
    () => {
        cyberSolBalance.value = 0n;
        cyberSolAllowance.value = 0n;
        loadOnchain();
    },
);

const handleConnect = async (): Promise<void> => {
    error.value = null;

    try {
        const addr = await wallet.connect();

        if (!addr) {
            error.value = wallet.error.value || 'Failed to connect wallet';

            return;
        }

        // wallet.address watcher will refresh balance/allowance.
        await loadOnchain();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    }
};

onMounted(async () => {
    await loadOnchain();
    await loadRecent();
});

onBeforeUnmount(() => {
    for (const chart of chartInstances.values()) {
        chart.destroy();
    }

    chartInstances.clear();
});
</script>

<template>
    <Head title="Launchpad" />

    <div class="launchpad-page">
        <div class="launchpad">
            <header class="intro">
                <h1>Launchpad</h1>
                <p>
                    Launch your own ERC-20 on Cyberia, paired with your
                    CYBER.sol. 100% of the supply goes to the LP, and LP tokens
                    are burned — liquidity can't be pulled.
                </p>
            </header>

            <div v-if="!isConfigured" class="banner banner--warn">
                Launchpad contract address is not configured. Set
                <code>VITE_LAUNCHPAD_ADDRESS</code> in <code>.env</code> and
                rebuild the frontend.
            </div>

            <section class="card">
                <h2>Create token</h2>

                <div class="grid">
                    <label>
                        <span>Name</span>
                        <Input v-model="name" placeholder="e.g. MyToken" />
                    </label>
                    <label>
                        <span>Symbol</span>
                        <Input
                            v-model="symbol"
                            placeholder="e.g. MYT"
                            maxlength="11"
                        />
                    </label>
                    <label>
                        <span>Total supply</span>
                        <Input
                            v-model="totalSupply"
                            type="text"
                            inputmode="decimal"
                        />
                    </label>
                    <label>
                        <span>CYBER.sol liquidity</span>
                        <Input
                            v-model="cyberSolLiquidity"
                            type="text"
                            inputmode="decimal"
                        />
                    </label>
                    <label class="full">
                        <span>Description (optional)</span>
                        <textarea
                            v-model="description"
                            rows="3"
                            maxlength="2000"
                            placeholder="Tell people what your token is about"
                            class="textarea"
                        ></textarea>
                    </label>
                    <label class="full">
                        <span>Image (optional, ≤ 2 MB)</span>
                        <input
                            type="file"
                            accept="image/*"
                            class="file"
                            @change="onImageChange"
                        />
                        <img
                            v-if="imagePreview"
                            :src="imagePreview"
                            class="preview"
                        />
                    </label>
                    <label class="full">
                        <span
                            >HTML page (optional, ≤ 2 MB) — sandboxed static
                            site</span
                        >
                        <input
                            type="file"
                            accept=".html,.htm,text/html"
                            class="file"
                            @change="onHtmlChange"
                        />
                        <span v-if="htmlFileName" class="small muted">{{
                            htmlFileName
                        }}</span>
                    </label>
                </div>

                <div class="meta">
                    <div>
                        Your CYBER.sol balance:
                        <strong>{{ fmt(cyberSolBalance) }}</strong>
                    </div>
                    <div v-if="minLiquidity > 0n">
                        Minimum:
                        <strong>{{ fmt(minLiquidity) }}</strong> CYBER.sol
                    </div>
                    <div>
                        Allowance:
                        <strong>{{ fmt(cyberSolAllowance) }}</strong>
                    </div>
                </div>

                <div class="actions">
                    <Button
                        v-if="!wallet.isConnected.value"
                        :disabled="wallet.isConnecting.value"
                        @click="handleConnect"
                    >
                        <Loader2
                            v-if="wallet.isConnecting.value"
                            class="spin"
                        />
                        Connect MetaMask
                    </Button>
                    <template v-else>
                        <Button
                            v-if="needsApprove"
                            :disabled="busy || insufficientBalance || belowMin"
                            @click="handleApprove"
                        >
                            <Loader2 v-if="busy" class="spin" />
                            Approve CYBER.sol
                        </Button>
                        <Button
                            v-else
                            :disabled="!canLaunch"
                            @click="handleLaunch"
                        >
                            <Loader2 v-if="busy" class="spin" />
                            Launch token
                        </Button>
                    </template>
                </div>

                <div v-if="insufficientBalance" class="hint hint--err">
                    Not enough CYBER.sol for the chosen liquidity.
                </div>
                <div v-else-if="belowMin" class="hint hint--err">
                    Liquidity below the minimum ({{ fmt(minLiquidity) }}
                    CYBER.sol).
                </div>
                <div v-if="status" class="hint">{{ status }}</div>
                <div v-if="error" class="hint hint--err">{{ error }}</div>
                <div v-if="txHash" class="hint">
                    tx: <code>{{ short(txHash) }}</code>
                </div>
            </section>

            <section v-if="recent.length" class="card">
                <h2>Launched tokens</h2>
                <ul class="tokenList">
                    <li v-for="t in recent" :key="t.token" class="tokenItem">
                        <div class="tokenImage">
                            <img
                                v-if="t.imageUrl"
                                :src="t.imageUrl"
                                :alt="t.symbol"
                            />
                            <span v-else class="tokenImageFallback">
                                {{
                                    (t.symbol || '?').slice(0, 2).toUpperCase()
                                }}
                            </span>
                        </div>
                        <div class="tokenBody">
                            <div class="tokenHead">
                                <div>
                                    <div class="tokenTitle">
                                        <strong>{{ t.symbol }}</strong>
                                        <span class="muted">
                                            · {{ t.name }}</span
                                        >
                                    </div>
                                    <div class="muted small">
                                        <a
                                            class="addrLink"
                                            :href="explorerAddressUrl(t.token)"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <code>{{ short(t.token) }}</code>
                                        </a>
                                    </div>
                                </div>
                                <div class="rowActions">
                                    <button
                                        v-if="canEdit(t)"
                                        class="editBtn"
                                        type="button"
                                        @click="openEditor(t)"
                                    >
                                        Edit
                                    </button>
                                    <a
                                        v-if="t.siteUrl"
                                        class="siteBtn"
                                        :href="t.siteUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Site →
                                    </a>
                                    <a
                                        class="swapBtn"
                                        :href="swapUrlFor(t.token)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Swap →
                                    </a>
                                </div>
                            </div>
                            <p v-if="t.description" class="tokenDesc">
                                {{ t.description }}
                            </p>
                            <div class="tokenStats">
                                <div>
                                    <span class="statLabel">Price</span>
                                    <span class="statValue">
                                        {{
                                            t.priceCyber != null
                                                ? formatPrice(t.priceCyber) +
                                                  ` ${t.quoteSymbol}`
                                                : '—'
                                        }}
                                    </span>
                                </div>
                                <div>
                                    <span class="statLabel">Market cap</span>
                                    <span class="statValue">
                                        {{
                                            t.marketCapCyber != null
                                                ? formatNum(t.marketCapCyber) +
                                                  ` ${t.quoteSymbol}`
                                                : '—'
                                        }}
                                    </span>
                                </div>
                                <div>
                                    <span class="statLabel">Supply</span>
                                    <span class="statValue">{{
                                        fmt(t.tokenSupply)
                                    }}</span>
                                </div>
                                <div>
                                    <span class="statLabel"
                                        >Liquidity locked</span
                                    >
                                    <span class="statValue"
                                        >{{ fmt(t.cyberSolLiquidity) }}
                                        {{ t.quoteSymbol }}</span
                                    >
                                </div>
                                <div>
                                    <span class="statLabel">Creator</span>
                                    <span class="statValue">
                                        <a
                                            v-if="t.creator"
                                            class="addrLink"
                                            :href="
                                                explorerAddressUrl(t.creator)
                                            "
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <code>{{ short(t.creator) }}</code>
                                        </a>
                                        <code v-else>—</code>
                                    </span>
                                </div>
                            </div>

                            <div
                                v-if="chartPointsFor(t).length > 0"
                                class="tokenChart"
                            >
                                <canvas
                                    :id="chartId(t.token)"
                                    :aria-label="`${t.symbol || 'Token'} price chart`"
                                ></canvas>
                            </div>
                            <div v-else class="chartEmpty">
                                Candlestick chart will appear after the first
                                trade. One candle represents one trade.
                            </div>

                            <div v-if="editingToken === t.token" class="editor">
                                <label>
                                    <span>Description</span>
                                    <textarea
                                        v-model="editDescription"
                                        rows="3"
                                        maxlength="2000"
                                        class="textarea"
                                    ></textarea>
                                </label>
                                <label>
                                    <span>Image (≤ 2 MB)</span>
                                    <input
                                        type="file"
                                        accept="image/*"
                                        class="file"
                                        @change="onEditImageChange"
                                    />
                                    <img
                                        v-if="editImagePreview"
                                        :src="editImagePreview"
                                        class="preview"
                                    />
                                </label>
                                <label>
                                    <span
                                        >HTML page (≤ 2 MB) — sandboxed static
                                        site</span
                                    >
                                    <input
                                        type="file"
                                        accept=".html,.htm,text/html"
                                        class="file"
                                        @change="onEditHtmlChange"
                                    />
                                    <span
                                        v-if="editHtmlFileName"
                                        class="small muted"
                                        >{{ editHtmlFileName }}</span
                                    >
                                </label>
                                <div v-if="editError" class="hint hint--err">
                                    {{ editError }}
                                </div>
                                <div class="editorActions">
                                    <Button
                                        :disabled="editBusy"
                                        @click="saveEditor(t)"
                                    >
                                        <Loader2 v-if="editBusy" class="spin" />
                                        Save
                                    </Button>
                                    <button
                                        type="button"
                                        class="editBtn"
                                        :disabled="editBusy"
                                        @click="closeEditor"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>

<style scoped>
.launchpad-page {
    min-height: 100vh;
    background: var(--background, #0d0d0d);
    color: var(--foreground, #e5e7eb);
}
.launchpad {
    max-width: 900px;
    margin: 0 auto;
    padding: 32px 16px 64px;
    color: var(--foreground, #e5e7eb);
}
.intro h1 {
    margin: 0 0 6px;
    font-size: 28px;
    font-weight: 700;
}
.intro p {
    color: var(--muted-foreground, #94a3b8);
    margin: 0 0 24px;
    max-width: 620px;
    line-height: 1.5;
}
.banner {
    padding: 12px 14px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 13px;
}
.banner--warn {
    background: rgba(234, 179, 8, 0.1);
    border: 1px solid rgba(234, 179, 8, 0.4);
    color: #facc15;
}
.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 20px;
}
.card h2 {
    margin: 0 0 16px;
    font-size: 18px;
    font-weight: 600;
}
.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.grid label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 13px;
    color: var(--muted-foreground, #94a3b8);
}
.grid label.full {
    grid-column: 1 / -1;
}
.textarea {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 10px;
    color: var(--foreground, #e5e7eb);
    font: inherit;
    resize: vertical;
}
.file {
    color: var(--muted-foreground, #94a3b8);
    font-size: 13px;
}
.preview {
    margin-top: 8px;
    max-width: 120px;
    max-height: 120px;
    border-radius: 12px;
    object-fit: cover;
    border: 1px solid var(--border);
}
.meta {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    margin-top: 14px;
    font-size: 13px;
    color: var(--muted-foreground, #94a3b8);
}
.meta strong {
    color: var(--foreground, #e5e7eb);
}
.actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}
.hint {
    margin-top: 10px;
    font-size: 13px;
    color: var(--muted-foreground, #94a3b8);
    word-break: break-all;
}
.hint--err {
    color: #f87171;
}
.spin {
    width: 16px;
    height: 16px;
    margin-right: 6px;
    animation: spin 0.8s linear infinite;
}
.muted {
    color: var(--muted-foreground, #94a3b8);
}
.small {
    font-size: 11px;
}
.tokenList {
    display: flex;
    flex-direction: column;
    gap: 14px;
    list-style: none;
    margin: 0;
    padding: 0;
}
.tokenItem {
    display: flex;
    gap: 14px;
    padding: 14px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
}
.tokenImage {
    flex-shrink: 0;
    width: 72px;
    height: 72px;
    border-radius: 12px;
    overflow: hidden;
    background: var(--muted);
    display: flex;
    align-items: center;
    justify-content: center;
}
.tokenImage img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.tokenImageFallback {
    color: var(--muted-foreground, #94a3b8);
    font-weight: 700;
    font-size: 18px;
}
.tokenBody {
    flex: 1;
    min-width: 0;
}
.tokenHead {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
}
.tokenTitle {
    font-size: 15px;
}
.rowActions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}
.swapBtn,
.editBtn,
.siteBtn {
    border: 1px solid var(--input);
    background: var(--card);
    color: var(--foreground, #e5e7eb);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 13px;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
    font: inherit;
}
.siteBtn {
    background: rgba(34, 197, 94, 0.15);
    border-color: rgba(34, 197, 94, 0.4);
    color: #86efac;
}
.siteBtn:hover {
    background: rgba(34, 197, 94, 0.25);
}
.swapBtn {
    background: rgba(59, 130, 246, 0.15);
    border-color: rgba(59, 130, 246, 0.4);
    color: #93c5fd;
}
.swapBtn:hover,
.editBtn:hover {
    background: var(--muted);
}
.swapBtn:hover {
    background: rgba(59, 130, 246, 0.25);
}
.editor {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.editor label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 13px;
    color: var(--muted-foreground, #94a3b8);
}
.editorActions {
    display: flex;
    gap: 8px;
}
.tokenDesc {
    margin: 8px 0 0;
    font-size: 13px;
    color: var(--foreground, #e5e7eb);
    line-height: 1.45;
    white-space: pre-wrap;
}
.tokenStats {
    margin-top: 10px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
}
.statLabel {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    color: var(--muted-foreground, #94a3b8);
    letter-spacing: 0.4px;
}
.statValue {
    font-size: 13px;
    color: var(--foreground, #e5e7eb);
}
.addrLink {
    color: #93c5fd;
    text-decoration: none;
}
.addrLink:hover {
    text-decoration: underline;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 640px) {
    .grid {
        grid-template-columns: 1fr;
    }
}

.tokenChart {
    margin-top: 14px;
    width: 100%;
    height: 220px;
    padding: 10px 8px 4px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: rgba(15, 23, 42, 0.28);
}
.tokenChart canvas {
    display: block;
    width: 100%;
    height: 100%;
}
.chartEmpty {
    margin-top: 12px;
    padding: 12px;
    border: 1px dashed rgba(148, 163, 184, 0.28);
    border-radius: 10px;
    color: var(--muted-foreground, #94a3b8);
    font-size: 12px;
}
</style>
