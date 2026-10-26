<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import WalletCandles from '@/components/wallet/WalletCandles.vue';
import { useLocale } from '@/composables/useLocale';
import type { MarketCandle } from '@/lib/marketCandles';
import {
    EXCHANGE_RANGES,
    exchangeListing,
    loadExchangeCandles,
} from '@/lib/wallet/exchangeCandles';
import type { ExchangeRangeKey } from '@/lib/wallet/exchangeCandles';
import { formatUsdPrice } from '@/lib/wallet/format';
import type { Market } from '@/lib/wallet/markets';
import { walletMessages } from '@/lib/walletMessages';

const props = defineProps<{
    market: Market;
    scheme: 'light' | 'dark';
}>();

const { locale, t } = useLocale(walletMessages);
const rangeKey = ref<ExchangeRangeKey>('7D');
const candles = ref<MarketCandle[]>([]);
const loading = ref(false);
const failed = ref(false);
const updatedAt = ref<number | null>(null);
const listing = computed(() => exchangeListing(props.market.tvSymbol));
const last = computed(() => candles.value.at(-1));
const changePct = computed(() => {
    const first = candles.value[0];

    return first && last.value
        ? ((last.value.close - first.open) / first.open) * 100
        : null;
});
const updatedTime = computed(() =>
    updatedAt.value === null
        ? '—'
        : new Date(updatedAt.value).toLocaleTimeString(locale.value),
);

let inFlight: AbortController | null = null;
let poll: ReturnType<typeof setInterval> | undefined;

const load = async (): Promise<void> => {
    inFlight?.abort();

    const controller = new AbortController();

    inFlight = controller;
    loading.value = true;

    try {
        const loaded = await loadExchangeCandles(
            props.market.tvSymbol ?? '',
            rangeKey.value,
            controller.signal,
        );

        if (controller.signal.aborted) {
            return;
        }

        candles.value = loaded;
        updatedAt.value = Date.now();
        failed.value = false;
    } catch {
        if (!controller.signal.aborted) {
            failed.value = true;
        }
    } finally {
        if (!controller.signal.aborted) {
            loading.value = false;
        }
    }
};

watch(
    [() => props.market.tvSymbol, rangeKey],
    () => {
        candles.value = [];
        updatedAt.value = null;
        failed.value = false;
        void load();
    },
    { immediate: true },
);

const refreshVisible = (): void => {
    if (document.visibilityState === 'visible' && !loading.value) {
        void load();
    }
};

onMounted(() => {
    poll = setInterval(refreshVisible, 30000);
    document.addEventListener('visibilitychange', refreshVisible);
});

onBeforeUnmount(() => {
    inFlight?.abort();
    clearInterval(poll);
    document.removeEventListener('visibilitychange', refreshVisible);
});
</script>

<template>
    <div>
        <div
            class="cw-row"
            style="align-items: baseline; gap: 12px; margin: 16px 0 12px"
        >
            <span class="cw-total" style="font-size: 28px">
                {{ last ? formatUsdPrice(last.close, locale) : '—' }}
            </span>
            <span v-if="listing" class="cw-label">{{ listing.quote }}</span>
            <span
                v-if="changePct !== null"
                class="cw-num"
                :style="{
                    color:
                        changePct >= 0 ? 'var(--cw-ok)' : 'var(--cw-bad-soft)',
                }"
                >{{ changePct >= 0 ? '+' : ''
                }}{{ changePct.toFixed(2) }}%</span
            >
        </div>

        <div
            style="display: flex; gap: 3px; margin-bottom: 8px"
            role="group"
            :aria-label="t('marketRange')"
        >
            <button
                v-for="range in EXCHANGE_RANGES"
                :key="range.key"
                type="button"
                class="cw-tf"
                :aria-pressed="rangeKey === range.key"
                @click="rangeKey = range.key"
            >
                {{ range.label }}
            </button>
        </div>

        <p v-if="loading && candles.length === 0" class="cw-note" role="status">
            {{ t('marketExchangeLoading') }}
        </p>
        <p v-if="failed" class="cw-note cw-note-warn" role="status">
            <span style="flex: 1">{{ t('marketExchangeFailed') }}</span>
            <button
                type="button"
                class="cw-back"
                :disabled="loading"
                @click="load()"
            >
                {{ t('retry') }}
            </button>
        </p>

        <WalletCandles
            v-if="candles.length > 0 && listing"
            :key="`${market.tvSymbol}:${rangeKey}`"
            :candles="candles"
            :base-symbol="market.symbol"
            :quote-symbol="listing.quote"
            :scheme="scheme"
        />
        <p v-if="listing" class="cw-rowdata" style="margin-top: 8px">
            {{
                t('marketCandleSource', {
                    venue: listing.venue,
                    pair: listing.pair,
                })
            }}
            <template v-if="updatedAt !== null">
                · {{ t('marketChartUpdated', { time: updatedTime }) }}</template
            >
        </p>
    </div>
</template>
