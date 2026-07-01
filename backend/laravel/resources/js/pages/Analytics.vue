<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Header from '@/components/Header.vue';

type SwapTotals = {
    count: number;
    volume_usd: number;
    traders: number;
    unpriced: number;
};

type KindTotals = {
    kind: string;
    count: number;
    volume_usd: number;
};

type TopToken = {
    symbol: string;
    volume_usd: number;
    legs: number;
};

type DailyRow = {
    day: string;
    swap_usd: number;
    swaps: number;
    liquidity_events: number;
    bridges: number;
};

type RecentEvent = {
    kind: string;
    usd: number | null;
    sym_in: string | null;
    amt_in: number | null;
    sym_out: string | null;
    amt_out: number | null;
    user_addr: string | null;
    tx_hash: string | null;
    meta: string | null;
    created_at: string;
};

type BridgeRow = {
    direction: string;
    token: string;
    count: number;
    amount: number;
};

type PoolRow = {
    pair_address: string;
    symbol0: string;
    symbol1: string;
    reserve0: number;
    reserve1: number;
    tvl_usd: number | null;
};

type PriceRow = {
    address: string;
    symbol: string;
    price_usd: number;
};

const props = defineProps<{
    days: number;
    swaps: SwapTotals | null;
    liquidity: Record<string, KindTotals>;
    lending: KindTotals[];
    topTokens: TopToken[];
    daily: DailyRow[];
    recent: RecentEvent[];
    bridges: BridgeRow[];
    pools: PoolRow[];
    prices: PriceRow[];
    cyberPrice: number | null;
    tvlUsd: number | null;
    snapshotAt: string | null;
    chain: { latest_block: number | null; gas_price_gwei: number | null } | null;
    explorerUrl: string;
    indexerReady: boolean;
    tokenLinks: Record<string, string>;
}>();

const windows = [
    { days: 1, label: '24h' },
    { days: 7, label: '7d' },
    { days: 30, label: '30d' },
];

const setWindow = (days: number) => {
    router.get('/analytics', { days }, { preserveScroll: true });
};

const windowLabel = computed(
    () => windows.find((w) => w.days === props.days)?.label ?? `${props.days}d`,
);

const usd = (value: number | null | undefined, digits?: number): string => {
    if (value === null || value === undefined) {
return '—';
}

    const abs = Math.abs(value);
    const maximumFractionDigits =
        digits ?? (abs >= 1000 ? 0 : abs >= 1 ? 2 : 6);

    return (
        '$' +
        value.toLocaleString('en-US', {
            maximumFractionDigits,
        })
    );
};

const amount = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
return '—';
}

    return value.toLocaleString('en-US', { maximumFractionDigits: 6 });
};

const shortAddr = (addr: string | null): string => {
    if (!addr) {
return '?';
}

    return addr.length > 12 ? `${addr.slice(0, 6)}…${addr.slice(-4)}` : addr;
};

// The /token/{address} page for a documented symbol, or null when we have no
// page to link to (so the symbol renders as plain text instead of a dead link).
const tokenHrefBySymbol = (symbol: string | null): string | null => {
    if (!symbol) {
        return null;
    }

    const addr = props.tokenLinks[symbol.toUpperCase()];

    return addr ? `/token/${addr}` : null;
};

const maxDailyUsd = computed(() =>
    Math.max(1, ...props.daily.map((d) => d.swap_usd)),
);

const maxTopToken = computed(() =>
    Math.max(1, ...props.topTokens.map((t) => t.volume_usd)),
);

const liquidityAdded = computed(() => props.liquidity?.liq_add ?? null);
const liquidityRemoved = computed(() => props.liquidity?.liq_remove ?? null);

const kindBadge: Record<string, { label: string; classes: string }> = {
    swap: { label: 'swap', classes: 'bg-blue-500/10 text-blue-500' },
    liq_add: { label: '+liq', classes: 'bg-green-500/10 text-green-600' },
    liq_remove: { label: '-liq', classes: 'bg-orange-500/10 text-orange-500' },
    bridge: { label: 'bridge', classes: 'bg-purple-500/10 text-purple-500' },
};

const badgeFor = (kind: string) =>
    kindBadge[kind] ?? {
        label: kind.replace('lend_', ''),
        classes: 'bg-gray-500/10 text-gray-500',
    };

const directionLabel = (direction: string): string =>
    ({
        sol_to_evm: 'Solana → Cyberia',
        evm_to_sol: 'Cyberia → Solana',
    })[direction] ?? direction;

const eventSummary = (e: RecentEvent): string => {
    if (e.kind === 'swap') {
        return `${amount(e.amt_in)} ${e.sym_in} → ${amount(e.amt_out)} ${e.sym_out}`;
    }

    if (e.kind === 'liq_add' || e.kind === 'liq_remove') {
        const sign = e.kind === 'liq_add' ? '+' : '-';

        return `${sign}${amount(e.amt_in)} ${e.sym_in} / ${sign}${amount(e.amt_out)} ${e.sym_out}`;
    }

    if (e.kind === 'bridge') {
        return `${amount(e.amt_in)} ${e.sym_in} ${directionLabel(e.meta ?? '')}`;
    }

    return `${amount(e.amt_in)} ${e.sym_in}`;
};

const price = (value: number): string => {
    if (value >= 1000) {
return usd(value, 0);
}

    if (value >= 0.01) {
return usd(value, 4);
}

    if (value <= 0) {
return usd(value, 2);
}

    // Sub-cent: plain decimal with ~4 significant figures, never scientific
    // notation (e.g. 0.00009235, not 9.235e-5).
    const digits = Math.min(20, Math.ceil(-Math.log10(value)) + 3);

    return usd(value, digits);
};
</script>

<template>
    <Head title="Analytics" />

    <Header />

    <div class="mx-auto max-w-6xl space-y-8 p-6">
        <header
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <div>
                <h1 class="text-2xl font-semibold">Cyberia analytics</h1>
                <p class="text-xs text-gray-500">
                    <span v-if="chain?.latest_block">
                        block #{{ chain.latest_block.toLocaleString() }}
                        <span v-if="chain.gas_price_gwei !== null">
                            · gas {{ chain.gas_price_gwei.toFixed(2) }} gwei
                        </span>
                    </span>
                    <span v-if="snapshotAt">
                        · prices updated {{ snapshotAt }} UTC
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-1">
                <button
                    v-for="w in windows"
                    :key="w.days"
                    class="rounded px-3 py-1 text-sm"
                    :class="
                        w.days === days
                            ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                            : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'
                    "
                    @click="setWindow(w.days)"
                >
                    {{ w.label }}
                </button>
            </div>
        </header>

        <section
            v-if="!indexerReady"
            class="rounded border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700"
        >
            The on-chain indexer has not written any data yet. Start the
            Telegram bot (it shares this app's database) and this page will
            fill in.
        </section>

        <section class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
            <div class="rounded border border-gray-200 p-4 dark:border-gray-800">
                <p class="text-xs text-gray-500">CYBER price</p>
                <p class="font-mono text-xl">
                    {{ cyberPrice !== null ? price(cyberPrice) : '—' }}
                </p>
            </div>
            <div class="rounded border border-gray-200 p-4 dark:border-gray-800">
                <p class="text-xs text-gray-500">DEX TVL</p>
                <p class="font-mono text-xl">{{ usd(tvlUsd) }}</p>
            </div>
            <div class="rounded border border-gray-200 p-4 dark:border-gray-800">
                <p class="text-xs text-gray-500">Volume ({{ windowLabel }})</p>
                <p class="font-mono text-xl">{{ usd(swaps?.volume_usd) }}</p>
            </div>
            <div class="rounded border border-gray-200 p-4 dark:border-gray-800">
                <p class="text-xs text-gray-500">Swaps ({{ windowLabel }})</p>
                <p class="font-mono text-xl">{{ swaps?.count ?? '—' }}</p>
            </div>
            <div class="rounded border border-gray-200 p-4 dark:border-gray-800">
                <p class="text-xs text-gray-500">Traders ({{ windowLabel }})</p>
                <p class="font-mono text-xl">{{ swaps?.traders ?? '—' }}</p>
            </div>
            <div class="rounded border border-gray-200 p-4 dark:border-gray-800">
                <p class="text-xs text-gray-500">Pools</p>
                <p class="font-mono text-xl">{{ pools.length || '—' }}</p>
            </div>
        </section>

        <section v-if="daily.length > 0">
            <h2 class="mb-3 text-lg font-semibold">Daily swap volume</h2>
            <div class="space-y-1.5">
                <div
                    v-for="d in daily"
                    :key="d.day"
                    class="grid grid-cols-[6.5rem_1fr_8rem_10rem] items-center gap-3 text-sm"
                >
                    <span class="font-mono text-xs">{{ d.day }}</span>
                    <div class="relative h-5 rounded bg-gray-100 dark:bg-gray-800">
                        <div
                            class="absolute inset-y-0 left-0 rounded bg-blue-500/70"
                            :style="{
                                width: (d.swap_usd / maxDailyUsd) * 100 + '%',
                            }"
                        />
                    </div>
                    <span class="text-right font-mono">{{ usd(d.swap_usd) }}</span>
                    <span class="font-mono text-xs text-gray-500">
                        {{ d.swaps }} swaps · {{ d.liquidity_events }} liq ·
                        {{ d.bridges }} bridges
                    </span>
                </div>
            </div>
        </section>

        <div class="grid gap-8 lg:grid-cols-2">
            <section v-if="topTokens.length > 0">
                <h2 class="mb-3 text-lg font-semibold">
                    Top tokens by volume ({{ windowLabel }})
                </h2>
                <div class="space-y-1.5">
                    <div
                        v-for="t in topTokens"
                        :key="t.symbol"
                        class="grid grid-cols-[7rem_1fr_6rem] items-center gap-3 text-sm"
                    >
                        <Link
                            v-if="tokenHrefBySymbol(t.symbol)"
                            :href="tokenHrefBySymbol(t.symbol)!"
                            class="truncate font-mono text-xs text-blue-500 hover:underline"
                        >
                            {{ t.symbol }}
                        </Link>
                        <span v-else class="truncate font-mono text-xs">{{ t.symbol }}</span>
                        <div class="relative h-5 rounded bg-gray-100 dark:bg-gray-800">
                            <div
                                class="absolute inset-y-0 left-0 rounded bg-indigo-500/70"
                                :style="{
                                    width:
                                        (t.volume_usd / maxTopToken) * 100 + '%',
                                }"
                            />
                        </div>
                        <span class="text-right font-mono">{{
                            usd(t.volume_usd)
                        }}</span>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-lg font-semibold">
                    Flows ({{ windowLabel }})
                </h2>
                <div class="space-y-2 text-sm">
                    <div
                        class="flex items-center justify-between rounded border border-gray-200 px-4 py-2 dark:border-gray-800"
                    >
                        <span class="text-gray-500">Liquidity added</span>
                        <span class="font-mono text-green-600">
                            +{{ usd(liquidityAdded?.volume_usd ?? 0) }}
                            <span class="text-xs text-gray-500">
                                ({{ liquidityAdded?.count ?? 0 }})
                            </span>
                        </span>
                    </div>
                    <div
                        class="flex items-center justify-between rounded border border-gray-200 px-4 py-2 dark:border-gray-800"
                    >
                        <span class="text-gray-500">Liquidity removed</span>
                        <span class="font-mono text-orange-500">
                            -{{ usd(liquidityRemoved?.volume_usd ?? 0) }}
                            <span class="text-xs text-gray-500">
                                ({{ liquidityRemoved?.count ?? 0 }})
                            </span>
                        </span>
                    </div>
                    <div
                        v-for="b in bridges"
                        :key="b.direction + b.token"
                        class="flex items-center justify-between rounded border border-gray-200 px-4 py-2 dark:border-gray-800"
                    >
                        <span class="text-gray-500">
                            Bridge {{ directionLabel(b.direction) }}
                        </span>
                        <span class="font-mono">
                            {{ amount(b.amount) }} {{ b.token }}
                            <span class="text-xs text-gray-500">({{ b.count }})</span>
                        </span>
                    </div>
                    <div
                        v-for="l in lending"
                        :key="l.kind"
                        class="flex items-center justify-between rounded border border-gray-200 px-4 py-2 dark:border-gray-800"
                    >
                        <span class="text-gray-500">
                            Lending {{ l.kind.replace('lend_', '') }}
                        </span>
                        <span class="font-mono">
                            {{ usd(l.volume_usd) }}
                            <span class="text-xs text-gray-500">({{ l.count }})</span>
                        </span>
                    </div>
                    <p
                        v-if="swaps && swaps.unpriced > 0"
                        class="text-xs text-gray-400"
                    >
                        {{ swaps.unpriced }} swap(s) involved tokens with no USD
                        route and are excluded from volume numbers.
                    </p>
                </div>
            </section>
        </div>

        <section v-if="pools.length > 0">
            <h2 class="mb-3 text-lg font-semibold">Liquidity pools</h2>
            <div
                class="overflow-x-auto rounded border border-gray-200 dark:border-gray-800"
            >
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-3 py-2 text-left">Pair</th>
                            <th class="px-3 py-2 text-right">Reserves</th>
                            <th class="px-3 py-2 text-right">TVL</th>
                            <th class="px-3 py-2 text-right">Contract</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in pools"
                            :key="p.pair_address"
                            class="border-t border-gray-100 dark:border-gray-800"
                        >
                            <td class="px-3 py-2 font-mono">
                                {{ p.symbol0 }}/{{ p.symbol1 }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono text-xs">
                                {{ amount(p.reserve0) }} {{ p.symbol0 }} ·
                                {{ amount(p.reserve1) }} {{ p.symbol1 }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ usd(p.tvl_usd) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono text-xs">
                                <a
                                    :href="`${explorerUrl}/address/${p.pair_address}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-blue-500 hover:underline"
                                >
                                    {{ shortAddr(p.pair_address) }}
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="recent.length > 0">
            <h2 class="mb-3 text-lg font-semibold">Recent activity</h2>
            <div
                class="overflow-x-auto rounded border border-gray-200 dark:border-gray-800"
            >
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-3 py-2 text-left">Type</th>
                            <th class="px-3 py-2 text-left">Event</th>
                            <th class="px-3 py-2 text-right">Value</th>
                            <th class="px-3 py-2 text-right">User</th>
                            <th class="px-3 py-2 text-right">Time (UTC)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(e, i) in recent"
                            :key="i"
                            class="border-t border-gray-100 dark:border-gray-800"
                        >
                            <td class="px-3 py-2">
                                <span
                                    class="rounded px-2 py-0.5 font-mono text-xs"
                                    :class="badgeFor(e.kind).classes"
                                >
                                    {{ badgeFor(e.kind).label }}
                                </span>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">
                                <a
                                    v-if="e.tx_hash && e.kind !== 'bridge'"
                                    :href="`${explorerUrl}/tx/${e.tx_hash}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="hover:underline"
                                >
                                    {{ eventSummary(e) }}
                                </a>
                                <span v-else>{{ eventSummary(e) }}</span>
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ usd(e.usd) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono text-xs">
                                {{ shortAddr(e.user_addr) }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-mono text-xs text-gray-500"
                            >
                                {{ e.created_at }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="prices.length > 0">
            <div class="mb-3 flex items-baseline justify-between">
                <h2 class="text-lg font-semibold">Token prices</h2>
                <Link href="/tokens" class="text-xs text-blue-500 hover:underline">
                    Browse all tokens →
                </Link>
            </div>
            <div class="grid grid-cols-2 gap-2 md:grid-cols-4 lg:grid-cols-6">
                <Link
                    v-for="p in prices"
                    :key="p.address"
                    :href="`/token/${p.address}`"
                    class="block rounded border border-gray-200 px-3 py-2 transition hover:border-gray-400 dark:border-gray-800 dark:hover:border-gray-600"
                >
                    <span class="block truncate font-mono text-xs text-gray-500">
                        {{ p.symbol }}
                    </span>
                    <span class="font-mono text-sm">{{ price(p.price_usd) }}</span>
                </Link>
            </div>
        </section>

        <p class="text-center text-xs text-gray-400">
            Indexed from the Cyberia chain by the community bot · prices walk
            the DEX pool graph from USDC/USDT · refreshed every few minutes
        </p>
    </div>
</template>
