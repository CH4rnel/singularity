<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import TokenIcon from '@/components/TokenIcon.vue';
import PageHero from '@/components/web3/PageHero.vue';
import { formatUsdPrice } from '@/lib/tokenFormat';

type TokenCard = {
    address: string;
    symbol: string | null;
    name: string | null;
    logo: string | null;
    tagline: string | null;
    price: number | null;
};

type Group = {
    key: string;
    label: string;
    tokens: TokenCard[];
};

defineProps<{
    groups: Group[];
    count: number;
}>();
</script>

<template>
    <Head title="Tokens" />

    <div class="mx-auto max-w-6xl space-y-10 p-6">
        <PageHero
            title="Tokens"
            :description="`The ${count} assets that live on the Cyberia chain — bridged crypto, stablecoins, commodities, Russian-market funds and community tokens. Tap any token to learn what it is and why it's here.`"
        />

        <section v-for="group in groups" :key="group.key" class="space-y-3">
            <h2 class="text-lg font-semibold">{{ group.label }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="token in group.tokens"
                    :key="token.address"
                    :href="`/token/${token.address}`"
                    class="flex gap-3 rounded border border-border p-4 transition hover:border-input dark:hover:border-input"
                >
                    <TokenIcon
                        :symbol="token.symbol ?? '?'"
                        :logo="token.logo"
                        :size="40"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="truncate font-semibold">{{
                                token.symbol
                            }}</span>
                            <span
                                class="shrink-0 font-mono text-sm text-muted-foreground"
                            >
                                {{
                                    token.price !== null
                                        ? formatUsdPrice(token.price)
                                        : ''
                                }}
                            </span>
                        </div>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ token.name }}
                        </p>
                        <p class="mt-1 line-clamp-2 text-xs text-muted-foreground">
                            {{ token.tagline }}
                        </p>
                    </div>
                </Link>
            </div>
        </section>
    </div>
</template>
