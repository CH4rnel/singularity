<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    COMPTROLLER_LIQ_ABI,
    ERC20_ABI,
    MANTISSA,
    MARKET_ABI,
    MARKET_EVENTS_ABI,
    comptrollerIface,
    decodeBigint,
    ensureCyberia,
    formatToken,
    marketIface,
    multicall,
    rpcCall,
    useLending,
} from '@/composables/useLending';
import type { Call, MarketView } from '@/composables/useLending';
import { getMetaMaskProvider } from '@/lib/evmProvider';

const {
    wallet,
    authUser,
    queryAddress,
    comptrollerAddress,
    inputComptroller,
    markets,
    loading,
    error,
    loadMarkets,
    setComptroller,
} = useLending();

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

watch(queryAddress, async (addr) => {
    if (addr) {
        await loadMarkets();
    }
});

onMounted(async () => {
    // Trigger silent reconnect via MetaMask in case the user landed on
    // /lending/liquidate directly.
    await wallet.restore(authUser.value?.wallet_address ?? null);

    if (queryAddress.value) {
        await loadMarkets();
    }
});
</script>

<template>
    <Head title="Cyberia Lending — Liquidate" />

    <div class="min-h-screen bg-background text-foreground">
        <Header />

        <main class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-12">
            <div>
                <p
                    class="mb-3 text-xs tracking-[0.24em] text-muted-foreground uppercase"
                >
                    Cyberia product
                </p>
                <h1 class="text-4xl font-semibold tracking-tight">Liquidate</h1>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                    Repay an underwater borrower's debt and seize their
                    collateral at a discount. Paste an address, or scan every
                    market's <code>Borrow</code> logs to find accounts with a
                    shortfall.
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
                    <a
                        href="/lending"
                        class="ml-3 underline-offset-2 hover:underline"
                    >
                        ← back to lending
                    </a>
                </p>
            </div>

            <div v-if="!queryAddress" class="rounded-lg border p-6">
                <p class="mb-4 text-sm text-muted-foreground">
                    Connect your wallet to scan for and liquidate borrowers.
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
                <div v-if="error" class="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
                    {{ error }}
                </div>

                <div v-if="loading" class="mb-4 flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 class="h-4 w-4 animate-spin" />
                    Loading markets…
                </div>

                <div class="rounded-lg border">
                    <div class="border-b px-4 py-2 text-xs uppercase tracking-wide text-muted-foreground">
                        Liquidate borrower
                    </div>
                    <div class="space-y-3 p-4">
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
            </div>
        </main>
    </div>
</template>
