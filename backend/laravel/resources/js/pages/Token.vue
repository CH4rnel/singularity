<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink } from 'lucide-vue-next';
import TokenIcon from '@/components/TokenIcon.vue';
import { formatUsd, formatUsdPrice, shortAddress } from '@/lib/tokenFormat';

type TokenLink = {
    label: string;
    url: string;
    external: boolean;
};

type Token = {
    address: string;
    symbol: string | null;
    name: string | null;
    decimals: number | null;
    logo: string | null;
    categoryKey: string | null;
    category: string | null;
    tagline: string | null;
    what: string | null;
    why: string | null;
    isKnown: boolean;
    price: number | null;
    links: TokenLink[];
};

type Pool = {
    pair_address: string;
    symbol0: string;
    symbol1: string;
    other_symbol: string;
    other_address: string;
    other_known: boolean;
    tvl_usd: number | null;
};

const props = defineProps<{
    token: Token;
    pools: Pool[];
    explorerUrl: string;
}>();

const title = props.token.symbol ?? shortAddress(props.token.address);
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto max-w-4xl space-y-8 p-6">
        <Link
            href="/analytics"
            class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground dark:hover:text-foreground"
        >
            <ArrowLeft class="h-4 w-4" /> Back to analytics
        </Link>

        <!-- Hero -->
        <header class="flex flex-wrap items-start gap-4">
            <TokenIcon
                :symbol="token.symbol ?? '?'"
                :logo="token.logo"
                :size="64"
            />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">
                        {{ token.name ?? title }}
                    </h1>
                    <span
                        v-if="token.symbol"
                        class="rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground"
                    >
                        {{ token.symbol }}
                    </span>
                    <span
                        v-if="token.category"
                        class="rounded bg-blue-500/10 px-2 py-0.5 text-xs text-blue-500"
                    >
                        {{ token.category }}
                    </span>
                </div>
                <p v-if="token.tagline" class="mt-1 text-sm text-muted-foreground">
                    {{ token.tagline }}
                </p>
                <a
                    :href="`${explorerUrl}/token/${token.address}`"
                    target="_blank"
                    rel="noopener"
                    class="mt-2 inline-flex items-center gap-1 font-mono text-xs text-muted-foreground hover:underline"
                >
                    {{ token.address }} <ExternalLink class="h-3 w-3" />
                </a>
            </div>
            <div class="text-right">
                <p class="text-xs text-muted-foreground">Price</p>
                <p class="font-mono text-2xl">
                    {{
                        token.price !== null ? formatUsdPrice(token.price) : '—'
                    }}
                </p>
            </div>
        </header>

        <!-- Undocumented fallback -->
        <section
            v-if="!token.isKnown"
            class="rounded border border-dashed border-input p-4 text-sm text-muted-foreground"
        >
            This token trades on the Cyberia DEX but hasn't been documented yet.
            You can still view its live price, pools and contract below.
        </section>

        <!-- What / why -->
        <section
            v-if="token.what || token.why"
            class="grid gap-6 md:grid-cols-2"
        >
            <div v-if="token.what" class="space-y-2">
                <h2 class="text-lg font-semibold">What is it?</h2>
                <p
                    class="text-sm leading-relaxed text-muted-foreground"
                >
                    {{ token.what }}
                </p>
            </div>
            <div v-if="token.why" class="space-y-2">
                <h2 class="text-lg font-semibold">Why is it on Cyberia?</h2>
                <p
                    class="text-sm leading-relaxed text-muted-foreground"
                >
                    {{ token.why }}
                </p>
            </div>
        </section>

        <!-- Actions -->
        <section class="flex flex-wrap gap-2">
            <template v-for="link in token.links" :key="link.url">
                <a
                    v-if="link.external"
                    :href="link.url"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1 rounded border border-border px-4 py-2 text-sm transition hover:border-input dark:hover:border-input"
                >
                    {{ link.label }} <ExternalLink class="h-3 w-3" />
                </a>
                <Link
                    v-else
                    :href="link.url"
                    class="inline-flex items-center gap-1 rounded border border-border px-4 py-2 text-sm transition hover:border-input dark:hover:border-input"
                >
                    {{ link.label }}
                </Link>
            </template>
        </section>

        <!-- Quick facts -->
        <section class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div
                class="rounded border border-border p-4"
            >
                <p class="text-xs text-muted-foreground">Symbol</p>
                <p class="font-mono">{{ token.symbol ?? '—' }}</p>
            </div>
            <div
                class="rounded border border-border p-4"
            >
                <p class="text-xs text-muted-foreground">Decimals</p>
                <p class="font-mono">{{ token.decimals ?? '—' }}</p>
            </div>
            <div
                class="rounded border border-border p-4"
            >
                <p class="text-xs text-muted-foreground">Standard</p>
                <p class="font-mono">ERC-20</p>
            </div>
            <div
                class="rounded border border-border p-4"
            >
                <p class="text-xs text-muted-foreground">Chain</p>
                <p class="font-mono">Cyberia</p>
            </div>
        </section>

        <!-- Pools -->
        <section v-if="pools.length > 0" class="space-y-3">
            <h2 class="text-lg font-semibold">Pools</h2>
            <div
                class="overflow-x-auto rounded border border-border"
            >
                <table class="min-w-full text-sm">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left">Pair</th>
                            <th class="px-3 py-2 text-left">Paired with</th>
                            <th class="px-3 py-2 text-right">TVL</th>
                            <th class="px-3 py-2 text-right">Contract</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="pool in pools"
                            :key="pool.pair_address"
                            class="border-t border-border/60"
                        >
                            <td class="px-3 py-2 font-mono">
                                {{ pool.symbol0 }}/{{ pool.symbol1 }}
                            </td>
                            <td class="px-3 py-2 font-mono">
                                <Link
                                    v-if="pool.other_known"
                                    :href="`/token/${pool.other_address}`"
                                    class="text-blue-500 hover:underline"
                                >
                                    {{ pool.other_symbol }}
                                </Link>
                                <span v-else>{{ pool.other_symbol }}</span>
                            </td>
                            <td class="px-3 py-2 text-right font-mono">
                                {{ formatUsd(pool.tvl_usd) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono text-xs">
                                <a
                                    :href="`${explorerUrl}/address/${pool.pair_address}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-blue-500 hover:underline"
                                >
                                    {{ shortAddress(pool.pair_address) }}
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="text-center text-xs text-muted-foreground">
            Prices and pools are derived live from the Cyberia DEX pool graph ·
            descriptions are maintained by the Cyberia community
        </p>
    </div>
</template>
