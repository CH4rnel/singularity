<script setup lang="ts">
import { computed } from 'vue';
import TokenIcon from '@/components/TokenIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatToken } from '@/composables/useLending';
import type { MarketAction, MarketView } from '@/composables/useLending';

// One lending market card. Extracted from Lending.vue so it can render both in
// the framed "Featured" group and in the main list without duplicating markup.
const props = defineProps<{
    market: MarketView;
    submitting: boolean;
    // Friendly display name (e.g. WCYBER surfaced as "CYBER").
    displayName: string;
}>();

defineEmits<{
    action: [market: MarketView, type: MarketAction];
    toggle: [market: MarketView];
}>();

const collateral = computed(() => {
    const m = props.market;

    if (m.entered) {
        return {
            label: 'collateral on',
            classes:
                'border-emerald-500/40 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
            warn: false,
        };
    }

    if (m.userSupplyShares > 0n || m.userBorrow > 0n) {
        return {
            label: 'enable collateral',
            classes:
                'border-amber-500/40 bg-amber-500/10 text-amber-600 dark:text-amber-400',
            warn: true,
        };
    }

    return {
        label: 'collateral off',
        classes: 'border-border text-muted-foreground',
        warn: false,
    };
});
</script>

<template>
    <article
        class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5"
    >
        <!-- card header -->
        <div class="flex items-start justify-between gap-2">
            <div class="flex items-center gap-3">
                <TokenIcon :symbol="market.symbol" :size="40" />
                <div>
                    <p class="font-semibold leading-tight">{{ displayName }}</p>
                    <Badge
                        variant="outline"
                        class="mt-0.5 font-mono text-[10px]"
                    >
                        CF {{ (market.collateralFactor * 100).toFixed(0) }}%
                    </Badge>
                </div>
            </div>
            <button
                class="rounded-full border px-2 py-0.5 text-[10px] font-medium transition disabled:opacity-50"
                :class="collateral.classes"
                :disabled="submitting"
                :title="
                    collateral.warn
                        ? 'You have a position here but the comptroller does not count it — click to enable collateral.'
                        : 'Toggle whether this market counts as collateral.'
                "
                @click="$emit('toggle', market)"
            >
                {{ collateral.label }}
            </button>
        </div>

        <!-- APYs -->
        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-border bg-background/50 p-3">
                <p class="text-[11px] text-muted-foreground">Supply APY</p>
                <p
                    class="font-mono text-lg text-emerald-600 dark:text-emerald-400"
                >
                    {{ market.supplyApy.toFixed(2) }}%
                </p>
            </div>
            <div class="rounded-xl border border-border bg-background/50 p-3">
                <p class="text-[11px] text-muted-foreground">Borrow APY</p>
                <p class="font-mono text-lg text-amber-600 dark:text-amber-400">
                    {{ market.borrowApy.toFixed(2) }}%
                </p>
            </div>
        </div>

        <!-- stats -->
        <dl class="grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-muted-foreground">Liquidity</dt>
            <dd
                class="text-right font-mono"
                :class="
                    market.cash === 0n ? 'text-muted-foreground/50 italic' : ''
                "
            >
                {{ formatToken(market.cash, market.decimals) }}
            </dd>
            <dt class="text-muted-foreground">Wallet</dt>
            <dd class="text-right font-mono">
                {{ formatToken(market.userUnderlyingBalance, market.decimals) }}
            </dd>
            <dt class="text-muted-foreground">My supply</dt>
            <dd class="text-right font-mono">
                {{ formatToken(market.userSupplyUnderlying, market.decimals) }}
            </dd>
            <dt class="text-muted-foreground">My borrow</dt>
            <dd class="text-right font-mono">
                {{ formatToken(market.userBorrow, market.decimals) }}
            </dd>
        </dl>

        <!-- actions -->
        <div class="mt-auto grid grid-cols-2 gap-2">
            <Button
                variant="outline"
                size="sm"
                @click="$emit('action', market, 'supply')"
            >
                Supply
            </Button>
            <Button
                variant="ghost"
                size="sm"
                :disabled="market.userSupplyShares === 0n"
                @click="$emit('action', market, 'withdraw')"
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
                @click="$emit('action', market, 'borrow')"
            >
                Borrow
            </Button>
            <Button
                variant="ghost"
                size="sm"
                :disabled="market.userBorrow === 0n"
                @click="$emit('action', market, 'repay')"
            >
                Repay
            </Button>
        </div>
    </article>
</template>
