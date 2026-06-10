<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    BrowserProvider,
    Contract,
    formatUnits,
    parseUnits,
    MaxUint256,
} from 'ethers';
import { Loader2 } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import Header from '@/components/Header.vue';
import { Badge } from '@/components/ui/badge';
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
import { getMetaMaskProvider } from '@/lib/evmProvider';

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

// Close the action modal on Escape.
function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape' && action.value) {
        action.value = null;
    }
}

onMounted(async () => {
    window.addEventListener('keydown', onKeydown);

    // Trigger silent reconnect via MetaMask in case the user landed on /lending
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
                    <a
                        href="/lending/liquidate"
                        class="ml-3 underline-offset-2 hover:underline"
                    >
                        liquidate borrowers →
                    </a>
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

                <div v-if="loading" class="flex items-center gap-2 text-muted-foreground">
                    <Loader2 class="h-4 w-4 animate-spin" />
                    Loading markets…
                </div>

                <div v-else-if="markets.length === 0" class="rounded-lg border p-8 text-center text-muted-foreground">
                    No markets listed at <code class="font-mono">{{ comptrollerAddress }}</code>.
                </div>

                <div v-else class="overflow-x-auto rounded-lg border">
                    <table class="w-full min-w-[760px] text-sm">
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
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="market in visibleMarkets" :key="market.address">
                            <tr class="border-t">
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
                            </tr>
                            <tr>
                                <td colspan="8" class="px-4 pb-3">
                                    <div class="flex flex-wrap gap-2">
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
                            </template>
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
