<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    BrowserProvider,
    Contract,
    Interface,
    formatUnits,
    parseUnits,
    MaxUint256,
    getAddress,
} from 'ethers';
import { Loader2 } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import Header from '@/components/Header.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWallet } from '@/composables/useWallet';
import { getMetaMaskProvider } from '@/lib/evmProvider';

type MarketAction = 'supply' | 'withdraw' | 'borrow' | 'repay';

type MarketView = {
    address: string;
    underlying: string;
    symbol: string;
    decimals: number;
    cash: bigint;
    totalBorrows: bigint;
    totalSupplyUnderlying: bigint;
    supplyApy: number;
    borrowApy: number;
    collateralFactor: number;
    priceMantissa: bigint;
    userSupplyShares: bigint;
    userSupplyUnderlying: bigint;
    userBorrow: bigint;
    userUnderlyingBalance: bigint;
    userAllowance: bigint;
    entered: boolean;
    exchangeRate: bigint;
};

const COMPTROLLER_ABI = [
    'function getAllMarkets() view returns (address[])',
    'function getAssetsIn(address) view returns (address[])',
    'function collateralFactor(address) view returns (uint256)',
    'function getAccountLiquidity(address) view returns (uint256,uint256,uint256)',
    'function oracle() view returns (address)',
    'function enterMarkets(address[]) returns (uint256[])',
    'function exitMarket(address)',
    'function closeFactorMantissa() view returns (uint256)',
    'function liquidationIncentiveMantissa() view returns (uint256)',
];

const MARKET_ABI = [
    'function underlying() view returns (address)',
    'function getCash() view returns (uint256)',
    'function totalBorrows() view returns (uint256)',
    'function totalSupply() view returns (uint256)',
    'function balanceOf(address) view returns (uint256)',
    'function borrowBalanceStored(address) view returns (uint256)',
    // Non-view: forces accrueInterest first, so the returned amount reflects
    // every block of interest up to the current head. We call it via Multicall3
    // inside an eth_call simulation — the state mutation is discarded.
    'function borrowBalanceCurrent(address) returns (uint256)',
    'function exchangeRateCurrent() returns (uint256)',
    'function exchangeRateStored() view returns (uint256)',
    'function accrueInterest()',
    'function interestRateModel() view returns (address)',
    'function reserveFactorMantissa() view returns (uint256)',
    'function mint(uint256) returns (uint256)',
    'function redeemUnderlying(uint256) returns (uint256)',
    'function borrow(uint256)',
    'function repayBorrow(uint256) returns (uint256)',
    'function liquidateBorrow(address borrower, uint256 repayAmount, address collateralMarket) returns (uint256)',
];

const COMPTROLLER_LIQ_ABI = [
    'function liquidateCalculateSeizeShares(address marketBorrowed, address marketCollateral, uint256 actualRepayAmount) view returns (uint256)',
];

const MARKET_EVENTS_ABI = [
    'event Borrow(address indexed borrower, uint256 borrowAmount, uint256 accountBorrows, uint256 totalBorrows)',
];

const ORACLE_ABI = ['function getUnderlyingPrice(address) view returns (uint256)'];

const RATE_MODEL_ABI = [
    'function getBorrowRate(uint256,uint256,uint256) view returns (uint256)',
    'function getSupplyRate(uint256,uint256,uint256,uint256) view returns (uint256)',
    'function blocksPerYear() view returns (uint256)',
];

const ERC20_ABI = [
    'function symbol() view returns (string)',
    'function decimals() view returns (uint8)',
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
];

// Configure once deployed. Empty string => UI prompts for address.
const DEFAULT_COMPTROLLER = (
    (import.meta as any).env?.VITE_LENDING_COMPTROLLER ?? ''
) as string;
// Use the same-origin proxy at /api/rpc/cyberia — the public Cyberia RPC blocks
// browser CORS and the app may be served over HTTPS (mixed-content guard).
const CYBERIA_RPC = '/api/rpc/cyberia';
const CYBERIA_CHAIN_ID = 49406;
const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';
// Pre-deployed Multicall3 on Cyberia (from crypto/hardhat/deployments/cyberia-quickswap.json).
const MULTICALL3 = '0x176C70dD7CF17056596D8c4C7E2b1f2537df978F';

const MULTICALL_ABI = [
    'function aggregate3((address target, bool allowFailure, bytes callData)[] calls) view returns ((bool success, bytes returnData)[])',
];

const comptrollerIface = new Interface(COMPTROLLER_ABI);
const marketIface = new Interface(MARKET_ABI);
const oracleIface = new Interface(ORACLE_ABI);
const rateModelIface = new Interface(RATE_MODEL_ABI);
const erc20Iface = new Interface(ERC20_ABI);

async function rpcCall<T>(method: string, params: unknown[]): Promise<T> {
    const res = await fetch(CYBERIA_RPC, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }),
    });
    const json = (await res.json()) as { result?: T; error?: { message: string } };

    if (json.error) {
throw new Error(json.error.message);
}

    return json.result as T;
}

type Call = { target: string; allowFailure: boolean; callData: string };

async function multicall(calls: Call[]): Promise<{ success: boolean; returnData: string }[]> {
    const mcIface = new Interface(MULTICALL_ABI);
    const data = mcIface.encodeFunctionData('aggregate3', [calls]);
    const result = await rpcCall<string>('eth_call', [
        { to: MULTICALL3, data },
        'latest',
    ]);
    const [decoded] = mcIface.decodeFunctionResult('aggregate3', result);

    return (decoded as Array<[boolean, string]>).map(([success, returnData]) => ({
        success,
        returnData,
    }));
}

const MANTISSA = 10n ** 18n;

const wallet = useWallet();
const page = usePage();
const authUser = computed(
    () =>
        page.props.auth?.user as
            | { wallet_address?: string | null }
            | undefined,
);

// Address we actually use for reads: prefer the connected EVM account, fall
// back to the saved wallet_address from Sanctum auth. Without this fallback,
// hitting /lending directly (without going through Welcome/AppSidebar where
// `wallet.restore()` runs) leaves `wallet.address` empty and balanceOf
// queries silently return 0.
const queryAddress = computed<string | null>(
    () => wallet.address.value || authUser.value?.wallet_address || null,
);
const comptrollerAddress = ref<string>(DEFAULT_COMPTROLLER);
const inputComptroller = ref<string>(DEFAULT_COMPTROLLER);
const markets = ref<MarketView[]>([]);
const liquidity = ref<{ liquidity: bigint; shortfall: bigint } | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

const action = ref<{ market: MarketView; type: MarketAction } | null>(null);
const amountInput = ref('');
const submitting = ref(false);

// --- Liquidation panel state ---------------------------------------------
type BorrowerPosition = {
    market: MarketView;
    supplyShares: bigint;
    supplyUnderlying: bigint;
    borrow: bigint;
};

type BorrowerView = {
    address: string;
    liquidity: bigint;
    shortfall: bigint;
    closeFactor: bigint;
    liquidationIncentive: bigint;
    positions: BorrowerPosition[];
};

const liquidationOpen = ref(false);
const borrowerInput = ref('');
const borrowerData = ref<BorrowerView | null>(null);
const inspectingBorrower = ref(false);
const selectedRepayMarket = ref('');
const selectedCollateralMarket = ref('');
const repayInput = ref('');
const liquidating = ref(false);
const liquidationError = ref<string | null>(null);
const expectedSeize = ref<bigint>(0n);

type UnderwaterAccount = {
    address: string;
    liquidity: bigint;
    shortfall: bigint;
};
const scanning = ref(false);
const scanError = ref<string | null>(null);
const underwater = ref<UnderwaterAccount[]>([]);
const allBorrowers = ref<string[]>([]);
const scanProgress = ref({ done: 0, total: 0 });

const isReady = computed(
    () =>
        !!queryAddress.value &&
        comptrollerAddress.value &&
        comptrollerAddress.value.length === 42,
);

/// Forward-looking interest projection from the current owed amount and APR.
/// Converts a fractional rate (e.g. 6.5% APR = 0.065) into a per-period
/// underlying-token delta using simple compounding (`(1+APR)^(t/year) - 1`).
function projectInterest(market: MarketView, periodSeconds: number): bigint {
    if (market.userBorrow === 0n) {
return 0n;
}

    const apr = market.borrowApy / 100;

    if (!isFinite(apr) || apr <= 0) {
return 0n;
}

    const growth = Math.pow(1 + apr, periodSeconds / (365 * 24 * 3600)) - 1;

    if (!isFinite(growth) || growth <= 0) {
return 0n;
}

    // Convert growth to a bigint share of userBorrow with 12 decimals of
    // precision; that's more than enough given Math.pow's float resolution.
    const scaled = BigInt(Math.round(growth * 1e12));

    return (market.userBorrow * scaled) / 10n ** 12n;
}

function perDayInterest(market: MarketView): bigint {
    return projectInterest(market, 86_400);
}

function perWeekInterest(market: MarketView): bigint {
    return projectInterest(market, 7 * 86_400);
}

function perMonthInterest(market: MarketView): bigint {
    return projectInterest(market, 30 * 86_400);
}

function rateToApy(ratePerBlock: bigint, blocksPerYear: bigint): number {
    if (blocksPerYear === 0n) {
return 0;
}

    // APY ≈ (1 + r)^n - 1, but n is very large; use continuous compounding for stability.
    const r = Number(ratePerBlock) / 1e18;

    return (Math.exp(r * Number(blocksPerYear)) - 1) * 100;
}

function decodeBigint(iface: Interface, fn: string, data: string): bigint {
    return iface.decodeFunctionResult(fn, data)[0] as bigint;
}

function decodeAddress(iface: Interface, fn: string, data: string): string {
    return iface.decodeFunctionResult(fn, data)[0] as string;
}

function decodeAddressArray(iface: Interface, fn: string, data: string): string[] {
    return iface.decodeFunctionResult(fn, data)[0] as string[];
}

async function loadMarkets() {
    error.value = null;

    if (!isReady.value) {
return;
}

    loading.value = true;

    try {
        const account = getAddress(queryAddress.value!);
        const comptrollerAddr = getAddress(comptrollerAddress.value);

        // --- Round 1: comptroller metadata + per-account state ----------------
        const round1: Call[] = [
            { target: comptrollerAddr, allowFailure: false, callData: comptrollerIface.encodeFunctionData('oracle') },
            { target: comptrollerAddr, allowFailure: false, callData: '0xb0772d0b' /* getAllMarkets() */ },
            { target: comptrollerAddr, allowFailure: false, callData: comptrollerIface.encodeFunctionData('getAssetsIn', [account]) },
            { target: comptrollerAddr, allowFailure: true,  callData: comptrollerIface.encodeFunctionData('getAccountLiquidity', [account]) },
        ];
        const r1 = await multicall(round1);
        const oracleAddress = decodeAddress(comptrollerIface, 'oracle', r1[0].returnData);
        const getAllMarketsIface = new Interface(['function getAllMarkets() view returns (address[])']);
        const allMarkets = decodeAddressArray(getAllMarketsIface, 'getAllMarkets', r1[1].returnData);
        const enteredList = decodeAddressArray(comptrollerIface, 'getAssetsIn', r1[2].returnData);
        const enteredSet = new Set(enteredList.map((a) => a.toLowerCase()));

        if (r1[3].success) {
            const decoded = comptrollerIface.decodeFunctionResult('getAccountLiquidity', r1[3].returnData);
            liquidity.value = { liquidity: decoded[1] as bigint, shortfall: decoded[2] as bigint };
        } else {
            liquidity.value = { liquidity: 0n, shortfall: 0n };
        }

        // --- Round 2: each market's underlying address + rate model ----------
        const round2: Call[] = [];

        for (const m of allMarkets) {
            round2.push({ target: m, allowFailure: false, callData: marketIface.encodeFunctionData('underlying') });
            round2.push({ target: m, allowFailure: false, callData: marketIface.encodeFunctionData('interestRateModel') });
        }

        const r2 = await multicall(round2);
        const underlyings: string[] = [];
        const rateModels: string[] = [];

        for (let i = 0; i < allMarkets.length; i++) {
            underlyings.push(decodeAddress(marketIface, 'underlying', r2[2 * i].returnData));
            rateModels.push(decodeAddress(marketIface, 'interestRateModel', r2[2 * i + 1].returnData));
        }

        // --- Round 3: per-market data --------------------------------------
        const calls: Call[] = [];

        for (let i = 0; i < allMarkets.length; i++) {
            const m = allMarkets[i];
            const u = underlyings[i];
            const rm = rateModels[i];
            calls.push(
                { target: u,             allowFailure: true, callData: erc20Iface.encodeFunctionData('symbol') },
                { target: u,             allowFailure: true, callData: erc20Iface.encodeFunctionData('decimals') },
                { target: m,             allowFailure: false, callData: marketIface.encodeFunctionData('getCash') },
                { target: m,             allowFailure: false, callData: marketIface.encodeFunctionData('totalBorrows') },
                { target: m,             allowFailure: false, callData: marketIface.encodeFunctionData('totalSupply') },
                { target: m,             allowFailure: true,  callData: marketIface.encodeFunctionData('balanceOf', [account]) },
                // borrowBalanceCurrent is non-view but Multicall3.aggregate3 uses
                // .call(); inside an eth_call the simulated state mutation gives
                // us the up-to-the-block owed amount, then is discarded.
                { target: m,             allowFailure: true,  callData: marketIface.encodeFunctionData('borrowBalanceCurrent', [account]) },
                { target: u,             allowFailure: true,  callData: erc20Iface.encodeFunctionData('balanceOf', [account]) },
                { target: u,             allowFailure: true,  callData: erc20Iface.encodeFunctionData('allowance', [account, m]) },
                { target: m,             allowFailure: false, callData: marketIface.encodeFunctionData('exchangeRateStored') },
                { target: m,             allowFailure: false, callData: marketIface.encodeFunctionData('reserveFactorMantissa') },
                { target: comptrollerAddr, allowFailure: false, callData: comptrollerIface.encodeFunctionData('collateralFactor', [m]) },
                { target: oracleAddress, allowFailure: true,  callData: oracleIface.encodeFunctionData('getUnderlyingPrice', [m]) },
                { target: rm,            allowFailure: false, callData: rateModelIface.encodeFunctionData('blocksPerYear') },
            );
        }

        const r3 = await multicall(calls);

        // After we have cash/borrows/reserveFactor we compute the rate-model calls in round 4.
        const stride = 14;
        const partial = allMarkets.map((m, i) => {
            const base = i * stride;

            return {
                marketAddress: m,
                underlyingAddress: underlyings[i],
                symbol: r3[base].success ? (erc20Iface.decodeFunctionResult('symbol', r3[base].returnData)[0] as string) : '?',
                decimals: r3[base + 1].success ? Number(erc20Iface.decodeFunctionResult('decimals', r3[base + 1].returnData)[0]) : 18,
                cash: decodeBigint(marketIface, 'getCash', r3[base + 2].returnData),
                borrows: decodeBigint(marketIface, 'totalBorrows', r3[base + 3].returnData),
                supplyShares: decodeBigint(marketIface, 'totalSupply', r3[base + 4].returnData),
                userShares: r3[base + 5].success ? decodeBigint(marketIface, 'balanceOf', r3[base + 5].returnData) : 0n,
                userBorrow: r3[base + 6].success ? decodeBigint(marketIface, 'borrowBalanceCurrent', r3[base + 6].returnData) : 0n,
                userBalance: r3[base + 7].success ? decodeBigint(erc20Iface, 'balanceOf', r3[base + 7].returnData) : 0n,
                allowance: r3[base + 8].success ? decodeBigint(erc20Iface, 'allowance', r3[base + 8].returnData) : 0n,
                exchangeRate: decodeBigint(marketIface, 'exchangeRateStored', r3[base + 9].returnData),
                reserveFactor: decodeBigint(marketIface, 'reserveFactorMantissa', r3[base + 10].returnData),
                cf: decodeBigint(comptrollerIface, 'collateralFactor', r3[base + 11].returnData),
                priceMantissa: r3[base + 12].success ? decodeBigint(oracleIface, 'getUnderlyingPrice', r3[base + 12].returnData) : 0n,
                blocksPerYear: decodeBigint(rateModelIface, 'blocksPerYear', r3[base + 13].returnData),
                rateModel: rateModels[i],
            };
        });

        // --- Round 4: borrow + supply rates (depend on cash/borrows/reserveFactor)
        const round4: Call[] = [];

        for (const p of partial) {
            round4.push(
                { target: p.rateModel, allowFailure: false, callData: rateModelIface.encodeFunctionData('getBorrowRate', [p.cash, p.borrows, 0n]) },
                { target: p.rateModel, allowFailure: false, callData: rateModelIface.encodeFunctionData('getSupplyRate', [p.cash, p.borrows, 0n, p.reserveFactor]) },
            );
        }

        const r4 = await multicall(round4);

        markets.value = partial.map((p, i) => {
            const borrowRate = decodeBigint(rateModelIface, 'getBorrowRate', r4[2 * i].returnData);
            const supplyRate = decodeBigint(rateModelIface, 'getSupplyRate', r4[2 * i + 1].returnData);

            return <MarketView>{
                address: p.marketAddress,
                underlying: p.underlyingAddress,
                symbol: p.symbol,
                decimals: p.decimals,
                cash: p.cash,
                totalBorrows: p.borrows,
                totalSupplyUnderlying: (p.supplyShares * p.exchangeRate) / MANTISSA,
                supplyApy: rateToApy(supplyRate, p.blocksPerYear),
                borrowApy: rateToApy(borrowRate, p.blocksPerYear),
                collateralFactor: Number(p.cf) / 1e18,
                priceMantissa: p.priceMantissa,
                userSupplyShares: p.userShares,
                userSupplyUnderlying: (p.userShares * p.exchangeRate) / MANTISSA,
                userBorrow: p.userBorrow,
                userUnderlyingBalance: p.userBalance,
                userAllowance: p.allowance,
                entered: enteredSet.has(p.marketAddress.toLowerCase()),
                exchangeRate: p.exchangeRate,
            };
        });
    } catch (e) {
        console.error('[lending] loadMarkets failed', e);
        error.value =
            e instanceof Error ? e.message : 'Failed to load lending markets';
        markets.value = [];
    } finally {
        loading.value = false;
    }
}

function formatToken(raw: bigint, decimals: number, digits = 4): string {
    if (raw === 0n) {
return '0';
}

    const n = Number(formatUnits(raw, decimals));

    if (n < 0.0001) {
return '<0.0001';
}

    return n.toLocaleString('en', {
        maximumFractionDigits: digits,
    });
}

function formatUsd(raw: bigint, priceMantissa: bigint): string {
    if (raw === 0n) {
return '$0';
}

    // raw is in underlying units; priceMantissa is normalized to 1e(36 - decimals)
    // so price * raw / 1e36 = USD. Divide by 1e32 then by 1e4 to keep 4 USD decimals.
    const usd = Number((raw * priceMantissa) / 10n ** 32n) / 1e4;

    if (usd < 0.01) {
return '<$0.01';
}

    return '$' + usd.toLocaleString('en', { maximumFractionDigits: 2 });
}

function openAction(market: MarketView, type: MarketAction) {
    action.value = { market, type };
    amountInput.value = '';
}

/// Underlying amount the user can safely borrow without triggering shortfall.
/// liquidity.value.liquidity is USD-mantissa (1e18); priceMantissa is
/// normalized so that price * underlying / 1e18 = USD (also mantissa 1e18).
function maxBorrowUnderlying(view: MarketView): bigint {
    const usdLiquidity = liquidity.value?.liquidity ?? 0n;

    if (usdLiquidity === 0n || view.priceMantissa === 0n) {
return 0n;
}

    return (usdLiquidity * MANTISSA) / view.priceMantissa;
}

function maxFor(view: MarketView, type: MarketAction): bigint {
    if (type === 'supply') {
return view.userUnderlyingBalance;
}

    if (type === 'withdraw') {
return view.userSupplyUnderlying;
}

    if (type === 'repay') {
        return view.userBorrow < view.userUnderlyingBalance
            ? view.userBorrow
            : view.userUnderlyingBalance;
    }

    // Borrow capped by the smaller of (a) market cash and (b) the user's
    // remaining collateral-backed borrow power.
    const power = maxBorrowUnderlying(view);

    return power < view.cash ? power : view.cash;
}

function setMax() {
    if (!action.value) {
return;
}

    const max = maxFor(action.value.market, action.value.type);
    amountInput.value = formatUnits(max, action.value.market.decimals);
}

async function ensureCyberia(): Promise<void> {
    const injected = getMetaMaskProvider();

    if (!injected) {
throw new Error('MetaMask not detected');
}

    const current = (await injected.request({
        method: 'eth_chainId',
    })) as string;

    if (parseInt(current, 16) === CYBERIA_CHAIN_ID) {
return;
}

    const hex = '0x' + CYBERIA_CHAIN_ID.toString(16);

    try {
        await injected.request({
            method: 'wallet_switchEthereumChain',
            params: [{ chainId: hex }],
        });
    } catch (err) {
        const code = (err as { code?: number })?.code;

        if (code === 4902) {
            await injected.request({
                method: 'wallet_addEthereumChain',
                params: [
                    {
                        chainId: hex,
                        chainName: 'Cyberia',
                        rpcUrls: [CYBERIA_PUBLIC_RPC],
                        nativeCurrency: { name: 'Cyber', symbol: 'CYBER', decimals: 18 },
                    },
                ],
            });
        } else {
            throw err;
        }
    }
}

/// Pull every Borrow event from every market and shake out the unique
/// borrower addresses, then check `getAccountLiquidity` for each in one
/// multicall. The comptroller has no on-chain enumerable list of borrowers;
/// logs are the canonical history.
/// The public Cyberia RPC rejects `eth_getLogs` over wide block ranges
/// ("block range too high"). Paginate in `LOG_CHUNK`-block windows; halve and
/// retry if the chunk itself is rejected.
const LOG_CHUNK_DEFAULT = 9_999;

/// Binary search for the block where this contract first existed. Compound's
/// markets all expose `accrualBlockNumber`, but it gets updated on every
/// `accrueInterest`, so it's not a reliable deployment marker. `eth_getCode`
/// is — empty before deploy, non-empty after.
async function findDeploymentBlock(addr: string, latest: number): Promise<number> {
    const cacheKey = `lending:deployBlock:${addr.toLowerCase()}`;
    const cached = localStorage.getItem(cacheKey);

    if (cached) {
        const n = Number(cached);

        if (Number.isFinite(n) && n >= 0 && n <= latest) {
return n;
}
    }

    let lo = 0;
    let hi = latest;

    while (lo < hi) {
        const mid = Math.floor((lo + hi) / 2);
        const code = await rpcCall<string>('eth_getCode', [addr, '0x' + mid.toString(16)]);

        if (code === '0x' || code === '0x0') {
            lo = mid + 1;
        } else {
            hi = mid;
        }
    }

    localStorage.setItem(cacheKey, String(lo));

    return lo;
}

async function getLogsChunked(
    market: string,
    topic: string,
    from: number,
    to: number,
    initialChunk: number,
    onProgress: (cursor: number) => void,
): Promise<Array<{ topics: string[] }>> {
    const out: Array<{ topics: string[] }> = [];
    let chunk = initialChunk;
    let cursor = from;

    while (cursor <= to) {
        const end = Math.min(cursor + chunk - 1, to);

        try {
            const logs = await rpcCall<Array<{ topics: string[] }>>('eth_getLogs', [
                {
                    address: market,
                    topics: [topic],
                    fromBlock: '0x' + cursor.toString(16),
                    toBlock: '0x' + end.toString(16),
                },
            ]);
            out.push(...logs);
            cursor = end + 1;
            onProgress(cursor);
        } catch (e) {
            const msg = e instanceof Error ? e.message.toLowerCase() : '';

            if (chunk > 100 && (msg.includes('range') || msg.includes('limit') || msg.includes('too'))) {
                chunk = Math.floor(chunk / 2);
                continue;
            }

            throw e;
        }
    }

    return out;
}

async function scanLiquidatable() {
    scanError.value = null;
    underwater.value = [];
    allBorrowers.value = [];
    scanProgress.value = { done: 0, total: 0 };

    if (markets.value.length === 0) {
        scanError.value = 'Markets not loaded yet';

        return;
    }

    scanning.value = true;

    try {
        const eventsIface = new Interface(MARKET_EVENTS_ABI);
        const borrowTopic = eventsIface.getEvent('Borrow')!.topicHash;

        const latestHex = await rpcCall<string>('eth_blockNumber', []);
        const latest = parseInt(latestHex, 16);

        // Resolve each market's deployment block first; this lets us scan
        // only ~thousands of blocks instead of all 8M+ on Cyberia.
        const deployBlocks: number[] = [];

        for (const m of markets.value) {
            deployBlocks.push(await findDeploymentBlock(m.address, latest));
        }

        const totalBlocks = deployBlocks.reduce(
            (sum, from) => sum + Math.max(0, latest - from + 1),
            0,
        );
        scanProgress.value = { done: 0, total: totalBlocks };

        const seen = new Set<string>();
        let scannedFromPreviousMarkets = 0;

        for (let i = 0; i < markets.value.length; i++) {
            const m = markets.value[i];
            const from = deployBlocks[i];
            const range = latest - from + 1;
            const baseOffset = scannedFromPreviousMarkets;
            const logs = await getLogsChunked(
                m.address,
                borrowTopic,
                from,
                latest,
                LOG_CHUNK_DEFAULT,
                (cursor) => {
                    scanProgress.value = {
                        ...scanProgress.value,
                        done: baseOffset + (cursor - from),
                    };
                },
            );
            scannedFromPreviousMarkets += range;

            for (const log of logs) {
                const padded = log.topics[1];

                if (!padded) {
continue;
}

                seen.add(getAddress('0x' + padded.slice(26)));
            }
        }

        allBorrowers.value = [...seen];

        if (seen.size === 0) {
            scanning.value = false;

            return;
        }

        const comptrollerAddr = getAddress(comptrollerAddress.value);
        const calls: Call[] = [...seen].map((addr) => ({
            target: comptrollerAddr,
            allowFailure: true,
            callData: comptrollerIface.encodeFunctionData('getAccountLiquidity', [addr]),
        }));
        const results = await multicall(calls);

        const list: UnderwaterAccount[] = [];
        [...seen].forEach((addr, i) => {
            if (!results[i].success) {
return;
}

            const decoded = comptrollerIface.decodeFunctionResult(
                'getAccountLiquidity',
                results[i].returnData,
            );
            const liq = decoded[1] as bigint;
            const sf = decoded[2] as bigint;

            if (sf > 0n) {
list.push({ address: addr, liquidity: liq, shortfall: sf });
}
        });

        // Sort biggest shortfall first — those are the juiciest.
        list.sort((a, b) => (b.shortfall > a.shortfall ? 1 : -1));
        underwater.value = list;
    } catch (e) {
        console.error('[lending] scan failed', e);
        scanError.value = e instanceof Error ? e.message : 'Scan failed';
    } finally {
        scanning.value = false;
    }
}

function pickBorrower(addr: string) {
    borrowerInput.value = addr;
    inspectBorrower();
}

async function inspectBorrower() {
    liquidationError.value = null;
    expectedSeize.value = 0n;
    let target: string;

    try {
        target = getAddress(borrowerInput.value.trim());
    } catch {
        liquidationError.value = 'Invalid address';

        return;
    }

    if (markets.value.length === 0) {
        liquidationError.value = 'Markets not loaded yet';

        return;
    }

    inspectingBorrower.value = true;

    try {
        const comptrollerAddr = getAddress(comptrollerAddress.value);
        const calls: Call[] = [
            { target: comptrollerAddr, allowFailure: true,  callData: comptrollerIface.encodeFunctionData('getAccountLiquidity', [target]) },
            { target: comptrollerAddr, allowFailure: false, callData: comptrollerIface.encodeFunctionData('closeFactorMantissa') },
            { target: comptrollerAddr, allowFailure: false, callData: comptrollerIface.encodeFunctionData('liquidationIncentiveMantissa') },
        ];

        for (const m of markets.value) {
            calls.push(
                { target: m.address, allowFailure: true, callData: marketIface.encodeFunctionData('balanceOf', [target]) },
                { target: m.address, allowFailure: true, callData: marketIface.encodeFunctionData('borrowBalanceCurrent', [target]) },
                { target: m.address, allowFailure: false, callData: marketIface.encodeFunctionData('exchangeRateStored') },
            );
        }

        const r = await multicall(calls);

        let liq = 0n;
        let sf = 0n;

        if (r[0].success) {
            const decoded = comptrollerIface.decodeFunctionResult('getAccountLiquidity', r[0].returnData);
            liq = decoded[1] as bigint;
            sf = decoded[2] as bigint;
        }

        const closeFactor = decodeBigint(comptrollerIface, 'closeFactorMantissa', r[1].returnData);
        const liquidationIncentive = decodeBigint(comptrollerIface, 'liquidationIncentiveMantissa', r[2].returnData);

        const positions: BorrowerPosition[] = markets.value.map((market, i) => {
            const base = 3 + i * 3;
            const shares = r[base].success ? decodeBigint(marketIface, 'balanceOf', r[base].returnData) : 0n;
            const borrow = r[base + 1].success ? decodeBigint(marketIface, 'borrowBalanceCurrent', r[base + 1].returnData) : 0n;
            const exchangeRate = decodeBigint(marketIface, 'exchangeRateStored', r[base + 2].returnData);

            return {
                market,
                supplyShares: shares,
                supplyUnderlying: (shares * exchangeRate) / MANTISSA,
                borrow,
            };
        });

        borrowerData.value = {
            address: target,
            liquidity: liq,
            shortfall: sf,
            closeFactor,
            liquidationIncentive,
            positions,
        };

        // Auto-pick the first borrowed asset + first available collateral.
        const firstBorrow = positions.find((p) => p.borrow > 0n);
        const firstCollateral = positions.find((p) => p.supplyShares > 0n);
        selectedRepayMarket.value = firstBorrow?.market.address ?? '';
        selectedCollateralMarket.value = firstCollateral?.market.address ?? '';
        repayInput.value = '';
    } catch (e) {
        liquidationError.value = e instanceof Error ? e.message : 'Failed to load borrower';
    } finally {
        inspectingBorrower.value = false;
    }
}

const borrowerBorrows = computed(() =>
    borrowerData.value?.positions.filter((p) => p.borrow > 0n) ?? [],
);
const borrowerCollaterals = computed(() =>
    borrowerData.value?.positions.filter((p) => p.supplyShares > 0n) ?? [],
);

const repayMarket = computed<MarketView | null>(() => {
    if (!borrowerData.value) {
return null;
}

    return (
        borrowerBorrows.value.find((p) => p.market.address === selectedRepayMarket.value)?.market ?? null
    );
});
const collateralMarket = computed<MarketView | null>(() => {
    if (!borrowerData.value) {
return null;
}

    return (
        borrowerCollaterals.value.find((p) => p.market.address === selectedCollateralMarket.value)?.market ?? null
    );
});

/// Hard cap on liquidation amount considering BOTH closeFactor and the
/// borrower's remaining collateral shares. Without the second cap, a
/// liquidator can cleanly compute a closeFactor-bound amount only to revert
/// inside `_seize` with «burn amount exceeds balance» — what happens after
/// successive liquidations that have already drained most of the collateral.
const maxRepay = computed<bigint>(() => {
    if (!repayMarket.value || !borrowerData.value) {
return 0n;
}

    const position = borrowerBorrows.value.find((p) => p.market.address === repayMarket.value!.address);

    if (!position) {
return 0n;
}

    const closeFactorMax = (position.borrow * borrowerData.value.closeFactor) / MANTISSA;

    if (!collateralMarket.value) {
return closeFactorMax;
}

    const collateral = borrowerCollaterals.value.find(
        (p) => p.market.address === collateralMarket.value!.address,
    );

    if (!collateral || collateral.supplyShares === 0n) {
return closeFactorMax;
}

    const incentive = borrowerData.value.liquidationIncentive;
    const priceBorrowed = repayMarket.value.priceMantissa;
    const priceCollateral = collateralMarket.value.priceMantissa;
    const exchangeRate = collateralMarket.value.exchangeRate;

    if (priceBorrowed === 0n || incentive === 0n) {
return closeFactorMax;
}

    // Invert the seize formula:
    //   seizeShares = repay * incentive * priceBorrowed / (priceCollateral * exchangeRate)
    // ⇒ repay_max = shares * priceCollateral * exchangeRate / (incentive * priceBorrowed)
    // 99 % safety margin to absorb rounding + tiny accrual drift between
    // multicall snapshot and on-chain submission time.
    const collateralCap =
        (collateral.supplyShares * priceCollateral * exchangeRate * 99n) /
        (incentive * priceBorrowed * 100n);

    return closeFactorMax < collateralCap ? closeFactorMax : collateralCap;
});

const maxRepayReason = computed<'closeFactor' | 'collateral' | null>(() => {
    if (!repayMarket.value || !borrowerData.value) {
return null;
}

    const position = borrowerBorrows.value.find((p) => p.market.address === repayMarket.value!.address);

    if (!position) {
return null;
}

    const closeFactorMax = (position.borrow * borrowerData.value.closeFactor) / MANTISSA;

    return maxRepay.value < closeFactorMax ? 'collateral' : 'closeFactor';
});

async function previewSeize() {
    expectedSeize.value = 0n;

    if (!repayMarket.value || !collateralMarket.value || !repayInput.value) {
return;
}

    let amount: bigint;

    try {
        amount = parseUnits(String(repayInput.value).trim() || '0', repayMarket.value.decimals);
    } catch {
        return;
    }

    if (amount === 0n) {
return;
}

    const data = new Interface(COMPTROLLER_LIQ_ABI).encodeFunctionData(
        'liquidateCalculateSeizeShares',
        [repayMarket.value.address, collateralMarket.value.address, amount],
    );

    try {
        const raw = await rpcCall<string>('eth_call', [
            { to: comptrollerAddress.value, data },
            'latest',
        ]);
        const [seizeShares] = new Interface(COMPTROLLER_LIQ_ABI).decodeFunctionResult(
            'liquidateCalculateSeizeShares',
            raw,
        );
        expectedSeize.value = seizeShares as bigint;
    } catch {
        expectedSeize.value = 0n;
    }
}

watch([repayInput, selectedRepayMarket, selectedCollateralMarket], () => {
    previewSeize();
});

async function liquidate() {
    const injected = getMetaMaskProvider();

    if (!borrowerData.value || !repayMarket.value || !collateralMarket.value || !injected) {
return;
}

    liquidationError.value = null;

    let amount: bigint;

    try {
        amount = parseUnits(String(repayInput.value).trim() || '0', repayMarket.value.decimals);
    } catch {
        liquidationError.value = 'Invalid repay amount';

        return;
    }

    if (amount === 0n) {
        liquidationError.value = 'Repay amount must be positive';

        return;
    }

    if (amount > maxRepay.value) {
        const reason = maxRepayReason.value === 'collateral' ? 'borrower\'s remaining collateral' : 'closeFactor';
        liquidationError.value = `Exceeds ${reason} cap — max repay is ${formatToken(maxRepay.value, repayMarket.value.decimals)} ${repayMarket.value.symbol}`;

        return;
    }

    liquidating.value = true;

    try {
        await ensureCyberia();
        const provider = new BrowserProvider(injected);
        const signer = await provider.getSigner();
        const liquidator = await signer.getAddress();

        if (liquidator.toLowerCase() === borrowerData.value.address.toLowerCase()) {
            throw new Error('Cannot liquidate yourself');
        }

        const underlying = new Contract(repayMarket.value.underlying, ERC20_ABI, signer);
        const allowance: bigint = await underlying.allowance(liquidator, repayMarket.value.address);

        if (allowance < amount) {
            const txA = await underlying.approve(repayMarket.value.address, MaxUint256);
            await txA.wait();
        }

        const marketContract = new Contract(repayMarket.value.address, MARKET_ABI, signer);
        const tx = await marketContract.liquidateBorrow(
            borrowerData.value.address,
            amount,
            collateralMarket.value.address,
        );
        await tx.wait();

        // Refresh both the global view and the borrower panel.
        await Promise.all([loadMarkets(), inspectBorrower()]);
        repayInput.value = '';
    } catch (e) {
        console.error('[lending] liquidate failed', e);
        liquidationError.value = e instanceof Error ? e.message : 'Liquidation failed';
    } finally {
        liquidating.value = false;
    }
}

function setMaxRepay() {
    if (!repayMarket.value) {
return;
}

    repayInput.value = formatUnits(maxRepay.value, repayMarket.value.decimals);
}

async function submitAction() {
    const injected = getMetaMaskProvider();

    if (!action.value || !injected) {
return;
}

    const { market, type } = action.value;
    const decimals = market.decimals;

    // <input type="number"> with v-model can deliver a JS number; parseUnits in
    // ethers v6 strictly requires a string. Normalize before parsing.
    const raw = String(amountInput.value ?? '').trim();
    let amountRaw: bigint;

    try {
        amountRaw = parseUnits(raw || '0', decimals);
    } catch (e) {
        console.error('[lending] parseUnits failed', { raw, decimals, e });
        error.value = `Invalid amount: "${raw}"`;

        return;
    }

    if (amountRaw <= 0n) {
        error.value = 'Amount must be positive';

        return;
    }

    if (type === 'borrow') {
        if (market.cash === 0n) {
            error.value = `No ${market.symbol} liquidity in the pool — someone has to supply ${market.symbol} before it can be borrowed.`;

            return;
        }

        if (amountRaw > market.cash) {
            error.value = `Only ${formatToken(market.cash, market.decimals)} ${market.symbol} available to borrow.`;

            return;
        }

        const power = maxBorrowUnderlying(market);

        if (amountRaw > power) {
            error.value = `Exceeds your borrow power (${formatToken(power, market.decimals)} ${market.symbol}). Supply more collateral or borrow less.`;

            return;
        }
    }

    if (type === 'withdraw' && amountRaw > market.cash) {
        error.value = `Only ${formatToken(market.cash, market.decimals)} ${market.symbol} of cash left in the pool — withdraw less or wait for borrowers to repay.`;

        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        await ensureCyberia();
        const provider = new BrowserProvider(injected);
        const signer = await provider.getSigner();
        const marketContract = new Contract(market.address, MARKET_ABI, signer);

        if (type === 'supply') {
            if (market.userAllowance < amountRaw) {
                const underlying = new Contract(
                    market.underlying,
                    ERC20_ABI,
                    signer,
                );
                const approveTx = await underlying.approve(
                    market.address,
                    MaxUint256,
                );
                await approveTx.wait();
            }

            const tx = await marketContract.mint(amountRaw);
            await tx.wait();

            // First supply also enrolls the user in this market so the deposit
            // counts toward their borrow power. Compound makes this opt-in via
            // enterMarkets — without this call `getAccountLiquidity` still
            // reports zero collateral even after the deposit settles.
            if (!market.entered) {
                const comptroller = new Contract(
                    comptrollerAddress.value,
                    COMPTROLLER_ABI,
                    signer,
                );
                const enterTx = await comptroller.enterMarkets([market.address]);
                await enterTx.wait();
            }
        } else if (type === 'withdraw') {
            const tx = await marketContract.redeemUnderlying(amountRaw);
            await tx.wait();
        } else if (type === 'borrow') {
            // Auto-enter collateral markets before borrowing.
            const comptroller = new Contract(
                comptrollerAddress.value,
                COMPTROLLER_ABI,
                signer,
            );
            const toEnter = markets.value
                .filter((m) => !m.entered && m.userSupplyShares > 0n)
                .map((m) => m.address);

            if (toEnter.length > 0) {
                const enterTx = await comptroller.enterMarkets(toEnter);
                await enterTx.wait();
            }

            const tx = await marketContract.borrow(amountRaw);
            await tx.wait();
        } else if (type === 'repay') {
            if (market.userAllowance < amountRaw) {
                const underlying = new Contract(
                    market.underlying,
                    ERC20_ABI,
                    signer,
                );
                const approveTx = await underlying.approve(
                    market.address,
                    MaxUint256,
                );
                await approveTx.wait();
            }

            const tx = await marketContract.repayBorrow(amountRaw);
            await tx.wait();
        }

        action.value = null;
        amountInput.value = '';
        await loadMarkets();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Transaction failed';
    } finally {
        submitting.value = false;
    }
}

async function toggleMembership(market: MarketView) {
    const injected = getMetaMaskProvider();

    if (!injected) {
return;
}

    submitting.value = true;
    error.value = null;

    try {
        await ensureCyberia();
        const provider = new BrowserProvider(injected);
        const signer = await provider.getSigner();
        const comptroller = new Contract(
            comptrollerAddress.value,
            COMPTROLLER_ABI,
            signer,
        );
        const tx = market.entered
            ? await comptroller.exitMarket(market.address)
            : await comptroller.enterMarkets([market.address]);
        await tx.wait();
        await loadMarkets();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Transaction failed';
    } finally {
        submitting.value = false;
    }
}

function setComptroller() {
    if (!inputComptroller.value) {
return;
}

    comptrollerAddress.value = inputComptroller.value.trim();
    loadMarkets();
}

// Hide markets that are deprecated (CF=0) AND empty for this user. These linger
// on-chain because the comptroller does not support delisting; they still need
// to be visible if the user has any position so they can withdraw/repay.
const visibleMarkets = computed(() =>
    markets.value.filter((m) => {
        const deprecated = m.collateralFactor === 0 && m.totalBorrows === 0n;

        if (!deprecated) {
return true;
}

        return m.userSupplyShares > 0n || m.userBorrow > 0n;
    }),
);

const supplyPositions = computed(() =>
    markets.value.filter((m) => m.userSupplyShares > 0n),
);
const borrowPositions = computed(() =>
    markets.value.filter((m) => m.userBorrow > 0n),
);

const totalSupplyUsd = computed(() =>
    supplyPositions.value.reduce((sum, m) => {
        return (
            sum + Number(formatUsd(m.userSupplyUnderlying, m.priceMantissa).replace(/[^0-9.]/g, '')) || 0
        );
    }, 0),
);

const totalBorrowUsd = computed(() =>
    borrowPositions.value.reduce((sum, m) => {
        return (
            sum + Number(formatUsd(m.userBorrow, m.priceMantissa).replace(/[^0-9.]/g, '')) || 0
        );
    }, 0),
);

watch(queryAddress, async (addr) => {
    if (addr) {
await loadMarkets();
}
});

onMounted(async () => {
    // Trigger silent reconnect via MetaMask in case the user landed on /lending
    // directly (AppSidebar/Welcome would normally do this).
    await wallet.restore(authUser.value?.wallet_address ?? null);

    if (queryAddress.value) {
        await loadMarkets();
    }
});
</script>

<template>
    <Head title="Cyberia Lending" />

    <div class="min-h-screen bg-background text-foreground">
        <Header />

        <main class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-12">
            <div>
                <p
                    class="mb-3 text-xs tracking-[0.24em] text-muted-foreground uppercase"
                >
                    Cyberia product
                </p>
                <h1 class="text-4xl font-semibold tracking-tight">Lending</h1>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                    Supply assets to earn interest, or borrow against your
                    collateral. Each market is isolated and shares interest
                    accrual blocks with on-chain liquidity checks.
                </p>
                <p
                    v-if="queryAddress"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    Connected as
                    <span class="font-mono">{{ queryAddress }}</span>
                    <button
                        class="ml-3 underline-offset-2 hover:underline"
                        :disabled="loading"
                        @click="loadMarkets"
                    >
                        refresh
                    </button>
                </p>
            </div>

            <div v-if="!queryAddress" class="rounded-lg border p-6">
                <p class="mb-4 text-sm text-muted-foreground">
                    Connect your wallet to view markets and manage positions.
                </p>
                <Button @click="wallet.connect()"
                    >Подключить кошелёк</Button
                >
            </div>

            <div v-else-if="!comptrollerAddress" class="rounded-lg border p-6">
                <p class="mb-4 text-sm text-muted-foreground">
                    Lending comptroller address not configured. Paste it below
                    or set <code>VITE_LENDING_COMPTROLLER</code> at build time.
                </p>
                <div class="flex gap-2">
                    <Input
                        v-model="inputComptroller"
                        placeholder="0x…"
                        class="font-mono"
                    />
                    <Button @click="setComptroller">Load</Button>
                </div>
            </div>

            <div v-else>
                <div class="mb-6 grid gap-4 md:grid-cols-3">
                    <div class="rounded-lg border p-4">
                        <p class="text-xs uppercase text-muted-foreground">
                            Total supplied
                        </p>
                        <p class="mt-2 text-2xl font-semibold">
                            ${{ totalSupplyUsd.toFixed(2) }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-4">
                        <p class="text-xs uppercase text-muted-foreground">
                            Total borrowed
                        </p>
                        <p class="mt-2 text-2xl font-semibold">
                            ${{ totalBorrowUsd.toFixed(2) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg border p-4"
                        :class="{
                            'border-destructive':
                                liquidity && liquidity.shortfall > 0n,
                        }"
                    >
                        <p class="text-xs uppercase text-muted-foreground">
                            Account liquidity
                        </p>
                        <p
                            v-if="liquidity && liquidity.shortfall > 0n"
                            class="mt-2 text-2xl font-semibold text-destructive"
                        >
                            -${{
                                (
                                    Number(liquidity.shortfall) / 1e18
                                ).toFixed(2)
                            }}
                        </p>
                        <p v-else-if="liquidity" class="mt-2 text-2xl font-semibold">
                            ${{ (Number(liquidity.liquidity) / 1e18).toFixed(2) }}
                        </p>
                        <p v-else class="mt-2 text-2xl font-semibold">—</p>
                    </div>
                </div>

                <div v-if="error" class="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
                    {{ error }}
                </div>

                <div v-if="borrowPositions.length > 0" class="mb-6 rounded-lg border">
                    <div class="border-b px-4 py-2 text-xs uppercase tracking-wide text-muted-foreground">
                        My borrows
                    </div>
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 text-left">Asset</th>
                                <th class="px-4 py-2 text-right">Owed (live)</th>
                                <th class="px-4 py-2 text-right">APR</th>
                                <th class="px-4 py-2 text-right">Per month</th>
                                <th class="px-4 py-2 text-right">Per week</th>
                                <th class="px-4 py-2 text-right">Per day</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="m in borrowPositions"
                                :key="`borrow-${m.address}`"
                                class="border-t"
                            >
                                <td class="px-4 py-2 font-medium">{{ m.symbol }}</td>
                                <td class="px-4 py-2 text-right font-mono">
                                    {{ formatToken(m.userBorrow, m.decimals, 8) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    {{ m.borrowApy.toFixed(2) }}%
                                </td>
                                <td class="px-4 py-2 text-right font-mono">
                                    +{{ formatToken(perMonthInterest(m), m.decimals, 8) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono">
                                    +{{ formatToken(perWeekInterest(m), m.decimals, 8) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono">
                                    +{{ formatToken(perDayInterest(m), m.decimals, 8) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="px-4 py-2 text-[11px] text-muted-foreground">
                        Owed values are live, recomputed on every refresh
                        (Multicall3 forces <code>accrueInterest</code> in a
                        simulated call). The «per month / per week / per day»
                        columns are projections based on the current borrow APR.
                    </p>
                </div>

                <div class="mb-6 rounded-lg border">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between border-b px-4 py-2 text-xs uppercase tracking-wide text-muted-foreground hover:bg-muted/40"
                        @click="liquidationOpen = !liquidationOpen"
                    >
                        <span>Liquidate borrower</span>
                        <span class="font-mono">{{ liquidationOpen ? '−' : '+' }}</span>
                    </button>
                    <div v-if="liquidationOpen" class="space-y-3 p-4">
                        <p class="text-xs text-muted-foreground">
                            Paste a borrower's address. If they have a
                            <code>shortfall &gt; 0</code> you can repay up to
                            <code>closeFactor × debt</code> of any borrowed
                            asset and seize their collateral at a
                            <code>liquidationIncentive</code> discount.
                        </p>

                        <div class="flex gap-2">
                            <Input
                                v-model="borrowerInput"
                                placeholder="0x… borrower address"
                                class="font-mono"
                            />
                            <Button
                                variant="outline"
                                :disabled="inspectingBorrower"
                                @click="inspectBorrower"
                            >
                                <Loader2 v-if="inspectingBorrower" class="mr-2 h-4 w-4 animate-spin" />
                                Inspect
                            </Button>
                            <Button
                                variant="outline"
                                :disabled="scanning"
                                :title="'Scan Borrow event logs across every market, check getAccountLiquidity for each unique borrower'"
                                @click="scanLiquidatable"
                            >
                                <Loader2 v-if="scanning" class="mr-2 h-4 w-4 animate-spin" />
                                Scan all
                            </Button>
                        </div>

                        <div
                            v-if="scanning && scanProgress.total > 0"
                            class="text-xs text-muted-foreground"
                        >
                            Scanned
                            {{
                                Math.min(
                                    100,
                                    Math.floor(
                                        (scanProgress.done / scanProgress.total) * 100,
                                    ),
                                )
                            }}% of {{ scanProgress.total.toLocaleString() }} blocks (across {{ markets.length }} markets)
                        </div>

                        <div
                            v-if="scanError"
                            class="rounded border border-destructive/40 bg-destructive/10 p-2 text-xs text-destructive"
                        >
                            {{ scanError }}
                        </div>

                        <div
                            v-if="allBorrowers.length > 0"
                            class="rounded border"
                        >
                            <div class="flex items-center justify-between border-b bg-muted/40 px-3 py-2 text-xs uppercase tracking-wide text-muted-foreground">
                                <span>
                                    Scanned {{ allBorrowers.length }} unique borrower{{ allBorrowers.length === 1 ? '' : 's' }},
                                    {{ underwater.length }} underwater
                                </span>
                            </div>
                            <table v-if="underwater.length > 0" class="w-full text-xs">
                                <thead class="text-muted-foreground">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Borrower</th>
                                        <th class="px-3 py-2 text-right">Shortfall</th>
                                        <th class="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="u in underwater"
                                        :key="u.address"
                                        class="border-t"
                                    >
                                        <td class="px-3 py-2 font-mono">{{ u.address }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-destructive">
                                            -${{ (Number(u.shortfall) / 1e18).toFixed(4) }}
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <Button size="sm" variant="outline" @click="pickBorrower(u.address)">
                                                Inspect
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="px-3 py-3 text-xs text-muted-foreground">
                                Every borrower is healthy. Nothing to liquidate.
                            </p>
                        </div>

                        <div
                            v-if="liquidationError"
                            class="rounded border border-destructive/40 bg-destructive/10 p-2 text-xs text-destructive"
                        >
                            {{ liquidationError }}
                        </div>

                        <div v-if="borrowerData" class="space-y-3 border-t pt-3">
                            <div class="grid gap-2 text-sm md:grid-cols-3">
                                <div>
                                    <p class="text-xs uppercase text-muted-foreground">Account</p>
                                    <p class="font-mono text-xs">{{ borrowerData.address }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase text-muted-foreground">Liquidity</p>
                                    <p>${{ (Number(borrowerData.liquidity) / 1e18).toFixed(4) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase text-muted-foreground">Shortfall</p>
                                    <p
                                        :class="
                                            borrowerData.shortfall > 0n
                                                ? 'text-destructive font-semibold'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        ${{ (Number(borrowerData.shortfall) / 1e18).toFixed(4) }}
                                    </p>
                                </div>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <p class="mb-1 text-xs uppercase text-muted-foreground">Borrows</p>
                                    <ul v-if="borrowerBorrows.length > 0" class="space-y-1 text-xs font-mono">
                                        <li
                                            v-for="p in borrowerBorrows"
                                            :key="`b-${p.market.address}`"
                                            class="flex justify-between"
                                        >
                                            <span>{{ p.market.symbol }}</span>
                                            <span>{{ formatToken(p.borrow, p.market.decimals, 8) }}</span>
                                        </li>
                                    </ul>
                                    <p v-else class="text-xs text-muted-foreground">none</p>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs uppercase text-muted-foreground">Collateral</p>
                                    <ul v-if="borrowerCollaterals.length > 0" class="space-y-1 text-xs font-mono">
                                        <li
                                            v-for="p in borrowerCollaterals"
                                            :key="`c-${p.market.address}`"
                                            class="flex justify-between"
                                        >
                                            <span>{{ p.market.symbol }}</span>
                                            <span>{{ formatToken(p.supplyUnderlying, p.market.decimals, 8) }}</span>
                                        </li>
                                    </ul>
                                    <p v-else class="text-xs text-muted-foreground">none</p>
                                </div>
                            </div>

                            <div
                                v-if="borrowerData.shortfall === 0n"
                                class="rounded border border-amber-500/40 bg-amber-500/10 p-2 text-xs text-amber-600 dark:text-amber-300"
                            >
                                No shortfall — this account is healthy.
                                Liquidation will revert with «no shortfall».
                            </div>

                            <div
                                v-else-if="borrowerBorrows.length === 0 || borrowerCollaterals.length === 0"
                                class="rounded border border-amber-500/40 bg-amber-500/10 p-2 text-xs text-amber-600 dark:text-amber-300"
                            >
                                Cannot liquidate — borrower has no active
                                borrow/collateral position in any listed market.
                            </div>

                            <div v-else class="grid gap-2 md:grid-cols-[1fr_1fr_1fr_auto] md:items-end">
                                <label class="text-xs">
                                    <span class="mb-1 block uppercase text-muted-foreground">Repay (asset they owe)</span>
                                    <select
                                        v-model="selectedRepayMarket"
                                        class="border-input bg-transparent w-full rounded border px-2 py-1 text-sm"
                                    >
                                        <option
                                            v-for="p in borrowerBorrows"
                                            :key="p.market.address"
                                            :value="p.market.address"
                                        >
                                            {{ p.market.symbol }} (max
                                            {{ formatToken((p.borrow * borrowerData.closeFactor) / MANTISSA, p.market.decimals, 8) }})
                                        </option>
                                    </select>
                                </label>
                                <label class="text-xs">
                                    <span class="mb-1 block uppercase text-muted-foreground">Seize (collateral)</span>
                                    <select
                                        v-model="selectedCollateralMarket"
                                        class="border-input bg-transparent w-full rounded border px-2 py-1 text-sm"
                                    >
                                        <option
                                            v-for="p in borrowerCollaterals"
                                            :key="p.market.address"
                                            :value="p.market.address"
                                        >
                                            {{ p.market.symbol }} ({{ formatToken(p.supplyUnderlying, p.market.decimals, 8) }})
                                        </option>
                                    </select>
                                </label>
                                <label class="text-xs">
                                    <span class="mb-1 block uppercase text-muted-foreground">
                                        Amount
                                        <span
                                            v-if="repayMarket && maxRepayReason"
                                            class="ml-1 normal-case font-normal"
                                            :class="
                                                maxRepayReason === 'collateral'
                                                    ? 'text-amber-500'
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            (max {{ formatToken(maxRepay, repayMarket.decimals, 8) }} —
                                            {{
                                                maxRepayReason === 'collateral'
                                                    ? 'collateral cap'
                                                    : 'closeFactor cap'
                                            }})
                                        </span>
                                    </span>
                                    <div class="flex gap-1">
                                        <Input v-model="repayInput" type="number" min="0" step="any" placeholder="0.0" />
                                        <Button type="button" variant="outline" size="sm" @click="setMaxRepay">Max</Button>
                                    </div>
                                </label>
                                <Button :disabled="liquidating" @click="liquidate">
                                    <Loader2 v-if="liquidating" class="mr-2 h-4 w-4 animate-spin" />
                                    Liquidate
                                </Button>
                            </div>

                            <p
                                v-if="repayMarket && collateralMarket && expectedSeize > 0n"
                                class="text-xs text-muted-foreground"
                            >
                                Expected seize: ≈ {{ formatToken(expectedSeize, collateralMarket.decimals, 8) }}
                                <code class="font-mono">cl{{ collateralMarket.symbol }}</code>
                                shares (incl. {{ ((Number(borrowerData.liquidationIncentive) - 1e18) / 1e16).toFixed(1) }}%
                                liquidation incentive). Redeem afterwards to convert to underlying.
                            </p>
                        </div>
                    </div>
                </div>

                <div v-if="loading" class="flex items-center gap-2 text-muted-foreground">
                    <Loader2 class="h-4 w-4 animate-spin" />
                    Loading markets…
                </div>

                <div v-else-if="markets.length === 0" class="rounded-lg border p-8 text-center text-muted-foreground">
                    No markets listed at <code class="font-mono">{{ comptrollerAddress }}</code>.
                </div>

                <div v-else class="overflow-hidden rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 text-left">Asset</th>
                                <th class="px-4 py-3 text-right">Wallet</th>
                                <th class="px-4 py-3 text-right">Supply APY</th>
                                <th class="px-4 py-3 text-right">Borrow APY</th>
                                <th class="px-4 py-3 text-right">Liquidity</th>
                                <th class="px-4 py-3 text-right">My supply</th>
                                <th class="px-4 py-3 text-right">My borrow</th>
                                <th class="px-4 py-3 text-right">Collateral</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="market in visibleMarkets"
                                :key="market.address"
                                class="border-t"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ market.symbol }}</span>
                                        <Badge variant="outline" class="font-mono text-[10px]">
                                            CF {{ (market.collateralFactor * 100).toFixed(0) }}%
                                        </Badge>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right font-mono">
                                    {{ formatToken(market.userUnderlyingBalance, market.decimals) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ market.supplyApy.toFixed(2) }}%
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ market.borrowApy.toFixed(2) }}%
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span
                                        :class="
                                            market.cash === 0n
                                                ? 'text-muted-foreground/60 italic'
                                                : ''
                                        "
                                    >
                                        {{ formatToken(market.cash, market.decimals) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ formatToken(market.userSupplyUnderlying, market.decimals) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ formatToken(market.userBorrow, market.decimals) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        class="text-xs underline-offset-2 hover:underline"
                                        :class="
                                            market.entered
                                                ? 'text-emerald-500'
                                                : (market.userSupplyShares > 0n || market.userBorrow > 0n)
                                                    ? 'text-amber-500 font-medium'
                                                    : 'text-muted-foreground'
                                        "
                                        :disabled="submitting"
                                        :title="
                                            !market.entered && (market.userSupplyShares > 0n || market.userBorrow > 0n)
                                                ? 'You have a position here but the comptroller does not see it — click to enterMarkets so collateral / debt is counted.'
                                                : undefined
                                        "
                                        @click="toggleMembership(market)"
                                    >
                                        {{
                                            market.entered
                                                ? 'enabled'
                                                : (market.userSupplyShares > 0n || market.userBorrow > 0n)
                                                    ? '⚠ enter'
                                                    : 'disabled'
                                        }}
                                    </button>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1">
                                        <Button variant="outline" size="sm" @click="openAction(market, 'supply')">
                                            Supply
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            :disabled="market.userSupplyShares === 0n"
                                            @click="openAction(market, 'withdraw')"
                                        >
                                            Withdraw
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            :disabled="market.cash === 0n"
                                            :title="
                                                market.cash === 0n
                                                    ? 'No liquidity to borrow — someone must supply first'
                                                    : undefined
                                            "
                                            @click="openAction(market, 'borrow')"
                                        >
                                            Borrow
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            :disabled="market.userBorrow === 0n"
                                            @click="openAction(market, 'repay')"
                                        >
                                            Repay
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <div
            v-if="action"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="action = null"
        >
            <div class="w-full max-w-md rounded-lg border bg-background p-6 shadow-xl">
                <h2 class="text-lg font-semibold capitalize">
                    {{ action.type }} {{ action.market.symbol }}
                </h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    <template v-if="action.type === 'supply'">
                        Wallet balance: {{ formatToken(action.market.userUnderlyingBalance, action.market.decimals) }} {{ action.market.symbol }}
                    </template>
                    <template v-else-if="action.type === 'withdraw'">
                        Supplied: {{ formatToken(action.market.userSupplyUnderlying, action.market.decimals) }} {{ action.market.symbol }}
                    </template>
                    <template v-else-if="action.type === 'borrow'">
                        Borrow power: {{ formatToken(maxBorrowUnderlying(action.market), action.market.decimals) }} {{ action.market.symbol }}
                        · cash {{ formatToken(action.market.cash, action.market.decimals) }}
                    </template>
                    <template v-else>
                        Owed: {{ formatToken(action.market.userBorrow, action.market.decimals) }} {{ action.market.symbol }}
                    </template>
                </p>

                <div class="mt-4 space-y-2">
                    <div class="flex gap-2">
                        <Input v-model="amountInput" type="number" min="0" step="any" placeholder="0.0" />
                        <Button variant="outline" type="button" @click="setMax">Max</Button>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <Button variant="ghost" :disabled="submitting" @click="action = null">
                        Cancel
                    </Button>
                    <Button :disabled="submitting" @click="submitAction">
                        <Loader2 v-if="submitting" class="mr-2 h-4 w-4 animate-spin" />
                        Confirm
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
