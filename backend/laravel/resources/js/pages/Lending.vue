<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    BrowserProvider,
    Contract,
    formatUnits,
    parseUnits,
    MaxUint256,
} from 'ethers';
import {
    ArrowRight,
    Loader2,
    RefreshCw,
    Search,
    TriangleAlert,
    Wallet,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import LendingMarketCard from '@/components/LendingMarketCard.vue';
import TokenIcon from '@/components/TokenIcon.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    COMPTROLLER_ABI,
    ERC20_ABI,
    MANTISSA,
    MARKET_ABI,
    ensureCyberia,
    formatToken,
    formatUsd,
    useLending,
} from '@/composables/useLending';
import type { MarketAction, MarketView } from '@/composables/useLending';
import { getSelectedEvmProvider } from '@/lib/evmProvider';

const {
    wallet,
    authUser,
    queryAddress,
    comptrollerAddress,
    inputComptroller,
    markets,
    liquidity,
    loading,
    error,
    loadMarkets,
    setComptroller,
} = useLending();

const action = ref<{ market: MarketView; type: MarketAction } | null>(null);
const amountInput = ref('');
const submitting = ref(false);
const marketFilter = ref('');

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

async function submitAction() {
    const injected = getSelectedEvmProvider();

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
                const enterTx = await comptroller.enterMarkets([
                    market.address,
                ]);
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
    const injected = getSelectedEvmProvider();

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

const hasPositions = computed(
    () => supplyPositions.value.length > 0 || borrowPositions.value.length > 0,
);

const totalSupplyUsd = computed(() =>
    supplyPositions.value.reduce((sum, m) => {
        return (
            sum +
                Number(
                    formatUsd(m.userSupplyUnderlying, m.priceMantissa).replace(
                        /[^0-9.]/g,
                        '',
                    ),
                ) || 0
        );
    }, 0),
);

const totalBorrowUsd = computed(() =>
    borrowPositions.value.reduce((sum, m) => {
        return (
            sum +
                Number(
                    formatUsd(m.userBorrow, m.priceMantissa).replace(
                        /[^0-9.]/g,
                        '',
                    ),
                ) || 0
        );
    }, 0),
);

// Account liquidity is the remaining USD borrow power; shortfall is how far
// underwater the account is (>0 means liquidatable).
const remainingBorrowUsd = computed(() =>
    liquidity.value ? Number(liquidity.value.liquidity) / 1e18 : 0,
);
const shortfallUsd = computed(() =>
    liquidity.value ? Number(liquidity.value.shortfall) / 1e18 : 0,
);
const borrowLimitUsd = computed(
    () => totalBorrowUsd.value + remainingBorrowUsd.value,
);
const borrowUsedPct = computed(() => {
    if (shortfallUsd.value > 0) {
        return 100;
    }

    return borrowLimitUsd.value > 0
        ? Math.min(100, (totalBorrowUsd.value / borrowLimitUsd.value) * 100)
        : 0;
});
const healthColor = computed(() => {
    if (shortfallUsd.value > 0 || borrowUsedPct.value >= 85) {
        return 'bg-red-500';
    }

    if (borrowUsedPct.value >= 60) {
        return 'bg-amber-500';
    }

    return 'bg-emerald-500';
});

const usd2 = (n: number): string =>
    '$' + n.toLocaleString('en', { maximumFractionDigits: 2 });

// Markets the user already has a position in float to the top; the rest sort by
// liquidity so the deepest pools lead.
const filteredMarkets = computed(() => {
    const q = marketFilter.value.trim().toLowerCase();
    // Match the on-chain symbol, the displayed name (WCYBER shows as CYBER)
    // and the market/underlying addresses, so pasted 0x… strings work too.
    const list = q
        ? visibleMarkets.value.filter(
              (m) =>
                  m.symbol.toLowerCase().includes(q) ||
                  featuredName(m).toLowerCase().includes(q) ||
                  m.address.toLowerCase().includes(q) ||
                  m.underlying.toLowerCase().includes(q),
          )
        : visibleMarkets.value.slice();

    return list.sort((a, b) => {
        // Featured tokens are pinned to the top, in their curated order.
        const ra = featuredRank(a.symbol);
        const rb = featuredRank(b.symbol);

        if (ra !== rb) {
            return ra - rb;
        }

        const aMine = a.userSupplyShares > 0n || a.userBorrow > 0n ? 1 : 0;
        const bMine = b.userSupplyShares > 0n || b.userBorrow > 0n ? 1 : 0;

        if (aMine !== bMine) {
            return bMine - aMine;
        }

        return b.cash > a.cash ? 1 : b.cash < a.cash ? -1 : 0;
    });
});

// The single markets grid leads with a curated set, pinned to the top: the
// headline assets first, then the community "partner" projects — so they land
// on the first two rows (at lg's 3 columns). WCYBER is surfaced under its
// friendlier "CYBER" name.
const FEATURED_ORDER = ['WCYBER', 'BTC', 'ETH', 'HATCHER', 'ORBV', 'KRSQ', 'YTN'];
const PARTNER_SYMBOLS = new Set(['HATCHER', 'ORBV', 'KRSQ', 'YTN']);

const featuredRank = (symbol: string): number => {
    const i = FEATURED_ORDER.indexOf(symbol.toUpperCase());

    return i === -1 ? Number.POSITIVE_INFINITY : i;
};
const isPartner = (symbol: string): boolean =>
    PARTNER_SYMBOLS.has(symbol.toUpperCase());
const featuredName = (m: MarketView): string =>
    m.symbol.toUpperCase() === 'WCYBER' ? 'CYBER' : m.symbol;

// The filtered list, split into the framed "Featured" group (headline assets +
// partners) and everything else. Each token appears in exactly one place.
const mainFeaturedMarkets = computed(() =>
    filteredMarkets.value.filter(
        (m) => Number.isFinite(featuredRank(m.symbol)) && !isPartner(m.symbol),
    ),
);
const partnerMarkets = computed(() =>
    filteredMarkets.value.filter((m) => isPartner(m.symbol)),
);
const otherMarkets = computed(() =>
    filteredMarkets.value.filter(
        (m) => !Number.isFinite(featuredRank(m.symbol)),
    ),
);
const hasFeatured = computed(
    () =>
        mainFeaturedMarkets.value.length > 0 || partnerMarkets.value.length > 0,
);

watch(queryAddress, async (addr) => {
    if (addr) {
        await loadMarkets();
    }
});

// Close the action modal on Escape.
function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape' && action.value) {
        action.value = null;
    }
}

onMounted(async () => {
    window.addEventListener('keydown', onKeydown);

    // Trigger silent reconnect via the selected EVM wallet in case the user landed on /lending
    // directly (AppSidebar/Welcome would normally do this).
    await wallet.restore(authUser.value?.wallet_address ?? null);

    if (queryAddress.value) {
        await loadMarkets();
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Head title="Cyberia Lending" />

    <div>
        <main class="relative overflow-hidden">
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-x-0 -top-40 -z-10 flex justify-center"
            >
                <div
                    class="h-[26rem] w-[60rem] max-w-full rounded-full bg-gradient-to-tr from-cyan-400/20 via-teal-400/10 to-teal-300/20 blur-3xl"
                ></div>
            </div>

            <div
                class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-12"
            >
                <!-- HERO -->
                <header class="space-y-3">
                    <p
                        class="text-xs tracking-[0.24em] text-muted-foreground uppercase"
                    >
                        Cyberia product
                    </p>
                    <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
                        Money
                        <span
                            class="bg-gradient-to-r from-cyan-400 via-teal-400 to-teal-300 bg-clip-text text-transparent"
                            >markets</span
                        >
                    </h1>
                    <p class="max-w-2xl text-sm text-muted-foreground">
                        Supply assets to earn interest, or borrow against your
                        collateral. Each market is isolated, accrues interest
                        every block and checks liquidity on-chain.
                    </p>
                    <p
                        v-if="queryAddress"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                    >
                        <span class="font-mono">{{ queryAddress }}</span>
                        <button
                            class="inline-flex items-center gap-1 transition hover:text-foreground disabled:opacity-50"
                            :disabled="loading"
                            @click="loadMarkets"
                        >
                            <RefreshCw
                                class="h-3 w-3"
                                :class="loading && 'animate-spin'"
                            />
                            refresh
                        </button>
                        <a
                            href="/lending/liquidate"
                            class="inline-flex items-center gap-1 transition hover:text-foreground"
                        >
                            liquidate borrowers
                            <ArrowRight class="h-3 w-3" />
                        </a>
                    </p>
                </header>

                <!-- NOT CONNECTED -->
                <div
                    v-if="!queryAddress"
                    class="rounded-2xl border border-border bg-card p-8 text-center"
                >
                    <Wallet
                        class="mx-auto mb-3 h-7 w-7 text-muted-foreground"
                    />
                    <p class="mb-4 text-sm text-muted-foreground">
                        Connect your wallet to view markets and manage
                        positions.
                    </p>
                    <Button class="rounded-xl" @click="wallet.connect()">
                        <Wallet class="mr-2 h-4 w-4" />
                        Connect wallet
                    </Button>
                </div>

                <!-- COMPTROLLER NOT CONFIGURED -->
                <div
                    v-else-if="!comptrollerAddress"
                    class="rounded-2xl border border-border bg-card p-6"
                >
                    <p class="mb-4 text-sm text-muted-foreground">
                        Lending comptroller address not configured. Paste it
                        below or set
                        <code>VITE_LENDING_COMPTROLLER</code> at build time.
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

                <template v-else>
                    <!-- SUMMARY -->
                    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div
                            class="rounded-2xl border border-border bg-card p-5"
                        >
                            <p class="text-xs text-muted-foreground">
                                Total supplied
                            </p>
                            <p class="mt-2 font-mono text-2xl font-semibold">
                                {{ usd2(totalSupplyUsd) }}
                            </p>
                        </div>
                        <div
                            class="rounded-2xl border border-border bg-card p-5"
                        >
                            <p class="text-xs text-muted-foreground">
                                Total borrowed
                            </p>
                            <p class="mt-2 font-mono text-2xl font-semibold">
                                {{ usd2(totalBorrowUsd) }}
                            </p>
                        </div>
                        <div
                            class="rounded-2xl border border-border bg-card p-5"
                        >
                            <p class="text-xs text-muted-foreground">
                                Borrow power left
                            </p>
                            <p
                                class="mt-2 font-mono text-2xl font-semibold"
                                :class="shortfallUsd > 0 ? 'text-red-500' : ''"
                            >
                                {{
                                    shortfallUsd > 0
                                        ? '-' + usd2(shortfallUsd)
                                        : usd2(remainingBorrowUsd)
                                }}
                            </p>
                        </div>
                        <div
                            class="rounded-2xl border border-border bg-card p-5"
                        >
                            <div
                                class="flex items-center justify-between text-xs text-muted-foreground"
                            >
                                <span>Borrow used</span>
                                <span
                                    class="font-mono"
                                    :class="
 shortfallUsd > 0 ? 'text-red-500' : ''
 "
                                    >{{ borrowUsedPct.toFixed(0) }}%</span
                                >
                            </div>
                            <div
                                class="mt-3 h-2 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="healthColor"
                                    :style="{ width: borrowUsedPct + '%' }"
                                />
                            </div>
                            <p
                                v-if="shortfallUsd > 0"
                                class="mt-2 flex items-center gap-1 text-[11px] text-red-500"
                            >
                                <TriangleAlert class="h-3 w-3" />
                                Account is liquidatable
                            </p>
                        </div>
                    </section>

                    <!-- ERROR -->
                    <div
                        v-if="error"
                        class="rounded-xl border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
                    >
                        {{ error }}
                    </div>

                    <!-- MY POSITIONS -->
                    <section
                        v-if="hasPositions"
                        class="grid gap-4 lg:grid-cols-2"
                    >
                        <div
                            v-if="supplyPositions.length > 0"
                            class="rounded-2xl border border-border bg-card p-5"
                        >
                            <h2 class="mb-3 text-sm font-semibold">
                                Your supplies
                            </h2>
                            <div class="space-y-2">
                                <div
                                    v-for="m in supplyPositions"
                                    :key="`s-${m.address}`"
                                    class="flex items-center justify-between gap-3 rounded-xl border border-border bg-background/50 px-3 py-2 text-sm"
                                >
                                    <span class="font-medium">{{
                                        m.symbol
                                    }}</span>
                                    <div class="text-right">
                                        <p class="font-mono">
                                            {{
                                                formatToken(
                                                    m.userSupplyUnderlying,
                                                    m.decimals,
                                                )
                                            }}
                                        </p>
                                        <p
                                            class="text-[11px] text-emerald-600 dark:text-emerald-400"
                                        >
                                            {{ m.supplyApy.toFixed(2) }}% APY
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="borrowPositions.length > 0"
                            class="rounded-2xl border border-border bg-card p-5"
                        >
                            <h2 class="mb-3 text-sm font-semibold">
                                Your borrows
                            </h2>
                            <div class="space-y-2">
                                <div
                                    v-for="m in borrowPositions"
                                    :key="`b-${m.address}`"
                                    class="rounded-xl border border-border bg-background/50 px-3 py-2 text-sm"
                                >
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <span class="font-medium">{{
                                            m.symbol
                                        }}</span>
                                        <div class="text-right">
                                            <p class="font-mono">
                                                {{
                                                    formatToken(
                                                        m.userBorrow,
                                                        m.decimals,
                                                        8,
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="text-[11px] text-amber-600 dark:text-amber-400"
                                            >
                                                {{ m.borrowApy.toFixed(2) }}%
                                                APR
                                            </p>
                                        </div>
                                    </div>
                                    <p
                                        class="mt-1 font-mono text-[11px] text-muted-foreground"
                                    >
                                        +{{
                                            formatToken(
                                                perDayInterest(m),
                                                m.decimals,
                                                8,
                                            )
                                        }}/d · +{{
                                            formatToken(
                                                perWeekInterest(m),
                                                m.decimals,
                                                8,
                                            )
                                        }}/wk · +{{
                                            formatToken(
                                                perMonthInterest(m),
                                                m.decimals,
                                                8,
                                            )
                                        }}/mo
                                    </p>
                                </div>
                            </div>
                            <p class="mt-2 text-[11px] text-muted-foreground">
                                Owed amounts are live (interest accrued to the
                                current block); the per-day/week/month figures
                                project the current APR forward.
                            </p>
                        </div>
                    </section>

                    <!-- MARKETS -->
                    <section class="space-y-4">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <h2 class="text-lg font-semibold">All markets</h2>
                            <div class="relative w-full max-w-xs">
                                <Search
                                    class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Input
                                    v-model="marketFilter"
                                    placeholder="Filter by symbol…"
                                    class="pl-9"
                                />
                            </div>
                        </div>

                        <div
                            v-if="loading"
                            class="flex items-center gap-2 py-10 text-muted-foreground"
                        >
                            <Loader2 class="h-4 w-4 animate-spin" />
                            Loading markets…
                        </div>

                        <div
                            v-else-if="markets.length === 0"
                            class="rounded-2xl border border-dashed border-border p-10 text-center text-muted-foreground"
                        >
                            No markets listed at
                            <code class="font-mono">{{
                                comptrollerAddress
                            }}</code
                            >.
                        </div>

                        <div
                            v-else-if="filteredMarkets.length === 0"
                            class="rounded-2xl border border-dashed border-border p-10 text-center text-muted-foreground"
                        >
                            No markets match “{{ marketFilter }}”.
                        </div>

                        <template v-else>
                            <!-- Featured: headline assets + partners, framed -->
                            <div
                                v-if="hasFeatured"
                                class="space-y-4 rounded-2xl border border-border/70 bg-muted/20 p-4 sm:p-5"
                            >
                                <h3 class="text-sm font-semibold">Featured</h3>
                                <div
                                    v-if="mainFeaturedMarkets.length"
                                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                                >
                                    <LendingMarketCard
                                        v-for="market in mainFeaturedMarkets"
                                        :key="market.address"
                                        :market="market"
                                        :submitting="submitting"
                                        :display-name="featuredName(market)"
                                        @action="openAction"
                                        @toggle="toggleMembership"
                                    />
                                </div>
                                <div
                                    v-if="partnerMarkets.length"
                                    class="space-y-2"
                                >
                                    <p
                                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                    >
                                        Partners
                                    </p>
                                    <div
                                        class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                                    >
                                        <LendingMarketCard
                                            v-for="market in partnerMarkets"
                                            :key="market.address"
                                            :market="market"
                                            :submitting="submitting"
                                            :display-name="featuredName(market)"
                                            @action="openAction"
                                            @toggle="toggleMembership"
                                        />
                                    </div>
                                </div>
                            </div>

                            <!-- Everything else -->
                            <div
                                v-if="otherMarkets.length"
                                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                            >
                                <LendingMarketCard
                                    v-for="market in otherMarkets"
                                    :key="market.address"
                                    :market="market"
                                    :submitting="submitting"
                                    :display-name="featuredName(market)"
                                    @action="openAction"
                                    @toggle="toggleMembership"
                                />
                            </div>
                        </template>
                    </section>
                </template>
            </div>
        </main>

        <!-- ACTION MODAL -->
        <div
            v-if="action"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
            @click.self="action = null"
        >
            <div
                class="w-full max-w-md rounded-2xl border border-border bg-background p-6 shadow-2xl"
            >
                <div class="flex items-center gap-3">
                    <TokenIcon :symbol="action.market.symbol" :size="36" />
                    <h2 class="text-lg font-semibold capitalize">
                        {{ action.type }} {{ action.market.symbol }}
                    </h2>
                </div>

                <p class="mt-3 text-xs text-muted-foreground">
                    <template v-if="action.type === 'supply'">
                        Wallet balance:
                        {{
                            formatToken(
                                action.market.userUnderlyingBalance,
                                action.market.decimals,
                            )
                        }}
                        {{ action.market.symbol }}
                    </template>
                    <template v-else-if="action.type === 'withdraw'">
                        Supplied:
                        {{
                            formatToken(
                                action.market.userSupplyUnderlying,
                                action.market.decimals,
                            )
                        }}
                        {{ action.market.symbol }}
                    </template>
                    <template v-else-if="action.type === 'borrow'">
                        Borrow power:
                        {{
                            formatToken(
                                maxBorrowUnderlying(action.market),
                                action.market.decimals,
                            )
                        }}
                        {{ action.market.symbol }} · cash
                        {{
                            formatToken(
                                action.market.cash,
                                action.market.decimals,
                            )
                        }}
                    </template>
                    <template v-else>
                        Owed:
                        {{
                            formatToken(
                                action.market.userBorrow,
                                action.market.decimals,
                            )
                        }}
                        {{ action.market.symbol }}
                    </template>
                </p>

                <div
                    class="mt-4 flex items-center gap-2 rounded-xl border border-border bg-card p-2"
                >
                    <Input
                        v-model="amountInput"
                        type="number"
                        min="0"
                        step="any"
                        placeholder="0.0"
                        class="border-0 bg-transparent text-lg shadow-none focus-visible:ring-0"
                    />
                    <button
                        type="button"
                        class="rounded-md border border-border px-2 py-1 text-xs font-medium text-muted-foreground transition hover:border-foreground/30 hover:text-foreground"
                        @click="setMax"
                    >
                        MAX
                    </button>
                    <span
                        class="pr-1 text-sm font-medium text-muted-foreground"
                        >{{ action.market.symbol }}</span
                    >
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <Button
                        variant="ghost"
                        :disabled="submitting"
                        @click="action = null"
                    >
                        Cancel
                    </Button>
                    <Button :disabled="submitting" @click="submitAction">
                        <Loader2
                            v-if="submitting"
                            class="mr-2 h-4 w-4 animate-spin"
                        />
                        <span class="capitalize">{{ action.type }}</span>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
