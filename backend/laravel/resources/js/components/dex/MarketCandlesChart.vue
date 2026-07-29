<script setup lang="ts">
import {
    CandlestickSeries,
    ColorType,
    CrosshairMode,
    HistogramSeries,
    PriceScaleMode,
    createChart,
} from 'lightweight-charts';
import type {
    IChartApi,
    ISeriesApi,
    MouseEventParams,
} from 'lightweight-charts';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { formatNum, formatPrice } from '@/lib/dexFormat';
import type { MarketCandle } from '@/lib/marketCandles';

const props = defineProps<{
    candles: MarketCandle[];
    baseSymbol: string;
    quoteSymbol: string;
}>();

// TradingView's classic candle pair — passes the CVD-separation and contrast
// checks against the dark page surface.
const UP_COLOR = '#26a69a';
const DOWN_COLOR = '#ef5350';
const UP_FILL = 'rgba(38, 166, 154, 0.45)';
const DOWN_FILL = 'rgba(239, 83, 80, 0.45)';

type LegendLine = {
    open: string;
    high: string;
    low: string;
    close: string;
    changePct: string;
    volume: string;
    when: string;
    up: boolean;
};

const host = ref<HTMLDivElement | null>(null);
const legend = ref<LegendLine | null>(null);

let chart: IChartApi | null = null;
let candleSeries: ISeriesApi<'Candlestick'> | null = null;
let volumeSeries: ISeriesApi<'Histogram'> | null = null;

const legendFrom = (candle: MarketCandle): LegendLine => {
    const pct =
        candle.open > 0
            ? ((candle.close - candle.open) / candle.open) * 100
            : 0;

    return {
        open: formatPrice(candle.open),
        high: formatPrice(candle.high),
        low: formatPrice(candle.low),
        close: formatPrice(candle.close),
        changePct: `${pct >= 0 ? '+' : ''}${pct.toFixed(2)}%`,
        volume: `${formatNum(candle.volume)} ${props.baseSymbol}`,
        when: new Date(candle.time * 1000).toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }),
        up: candle.close >= candle.open,
    };
};

const applyData = (): void => {
    if (!chart || !candleSeries || !volumeSeries) {
        return;
    }

    candleSeries.setData(
        props.candles.map(({ time, open, high, low, close }) => ({
            time,
            open,
            high,
            low,
            close,
        })),
    );
    volumeSeries.setData(
        props.candles.map((candle) => ({
            time: candle.time,
            value: candle.volume,
            color: candle.close >= candle.open ? UP_FILL : DOWN_FILL,
        })),
    );
    // fitContent() puts the first and last bars flush against the frame, which
    // slices them in half; widen the range by a bar on each side.
    const timeScale = chart.timeScale();
    timeScale.fitContent();

    const visible = timeScale.getVisibleLogicalRange();

    if (visible) {
        timeScale.setVisibleLogicalRange({
            from: visible.from - 1,
            to: visible.to + 1,
        });
    }

    const last = props.candles[props.candles.length - 1];
    legend.value = last ? legendFrom(last) : null;
};

const onCrosshairMove = (param: MouseEventParams): void => {
    const hovered =
        param.time === undefined
            ? undefined
            : props.candles.find((candle) => candle.time === param.time);
    const target = hovered ?? props.candles[props.candles.length - 1];

    legend.value = target ? legendFrom(target) : null;
};

onMounted(() => {
    if (!host.value) {
        return;
    }

    chart = createChart(host.value, {
        autoSize: true,
        layout: {
            background: { type: ColorType.Solid, color: 'transparent' },
            textColor: '#94a3b8',
            fontSize: 11,
        },
        grid: {
            vertLines: { color: 'rgba(148, 163, 184, 0.08)' },
            horzLines: { color: 'rgba(148, 163, 184, 0.08)' },
        },
        crosshair: { mode: CrosshairMode.Normal },
        rightPriceScale: {
            borderColor: 'rgba(148, 163, 184, 0.2)',
            // Ritual markets are thin enough to move by multiples over a
            // month; a linear scale squashes everything after such a leg.
            mode: PriceScaleMode.Logarithmic,
            // Keep the extremes off the frame edge and clear of the volume
            // strip below, so no candle is ever half-drawn.
            scaleMargins: { top: 0.1, bottom: 0.24 },
        },
        timeScale: {
            borderColor: 'rgba(148, 163, 184, 0.2)',
            timeVisible: true,
            secondsVisible: false,
        },
        localization: {
            priceFormatter: (price: number): string => formatPrice(price),
        },
    });

    candleSeries = chart.addSeries(CandlestickSeries, {
        upColor: UP_COLOR,
        downColor: DOWN_COLOR,
        wickUpColor: UP_COLOR,
        wickDownColor: DOWN_COLOR,
        borderVisible: false,
        priceFormat: {
            type: 'custom',
            formatter: (price: number): string => formatPrice(price),
            minMove: 1e-12,
        },
    });

    volumeSeries = chart.addSeries(HistogramSeries, {
        priceScaleId: 'volume',
        priceFormat: { type: 'volume' },
        priceLineVisible: false,
        lastValueVisible: false,
    });
    chart.priceScale('volume').applyOptions({
        visible: false,
        scaleMargins: { top: 0.82, bottom: 0 },
    });

    chart.subscribeCrosshairMove(onCrosshairMove);
    applyData();
});

watch(() => props.candles, applyData);

onBeforeUnmount(() => {
    chart?.remove();
    chart = null;
    candleSeries = null;
    volumeSeries = null;
});
</script>

<template>
    <div class="marketChart">
        <div
            v-if="legend"
            class="legend"
            :class="legend.up ? 'legend--up' : 'legend--down'"
        >
            <span class="legendItem legendMuted">{{ legend.when }}</span>
            <span class="legendItem">
                O <b>{{ legend.open }}</b>
            </span>
            <span class="legendItem">
                H <b>{{ legend.high }}</b>
            </span>
            <span class="legendItem">
                L <b>{{ legend.low }}</b>
            </span>
            <span class="legendItem">
                C <b>{{ legend.close }}</b>
            </span>
            <span class="legendItem legendPct">{{ legend.changePct }}</span>
            <span class="legendItem legendMuted">Vol {{ legend.volume }}</span>
        </div>
        <div
            ref="host"
            class="chartHost"
            role="img"
            :aria-label="`${baseSymbol}/${quoteSymbol} candlestick chart`"
        ></div>
    </div>
</template>

<style scoped>
/* The OHLC read-out sits above the canvas rather than floating over it —
   as an overlay it hid the top-left candles on markets that start high. */
.marketChart {
    display: flex;
    flex-direction: column;
    width: 100%;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: rgba(10, 15, 26, 0.45);
}
.chartHost {
    width: 100%;
    height: 260px;
}
.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 10px;
    align-items: baseline;
    padding: 6px 10px;
    border-bottom: 1px solid var(--border);
    pointer-events: none;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
    color: var(--muted-foreground, #94a3b8);
}
.legendItem b {
    font-weight: 600;
}
.legend--up .legendItem b,
.legend--up .legendPct {
    color: #26a69a;
}
.legend--down .legendItem b,
.legend--down .legendPct {
    color: #ef5350;
}
.legendPct {
    font-weight: 700;
}
.legendMuted {
    color: var(--muted-foreground, #94a3b8);
}
</style>
