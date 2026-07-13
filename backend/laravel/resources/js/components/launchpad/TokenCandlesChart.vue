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
import { formatNum, formatPrice } from '@/lib/launchpadChart';
import type { TokenCandle } from '@/lib/launchpadChart';

const props = defineProps<{
    candles: TokenCandle[];
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
    block: string;
    up: boolean;
};

const host = ref<HTMLDivElement | null>(null);
const legend = ref<LegendLine | null>(null);

let chart: IChartApi | null = null;
let candleSeries: ISeriesApi<'Candlestick'> | null = null;
let volumeSeries: ISeriesApi<'Histogram'> | null = null;

const legendFrom = (candle: TokenCandle): LegendLine => {
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
        volume: `${formatNum(candle.volumeCyber)} ${props.quoteSymbol}`,
        block: `#${candle.block.toLocaleString('en-US')}`,
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
            value: candle.volumeCyber,
            color: candle.close >= candle.open ? UP_FILL : DOWN_FILL,
        })),
    );
    chart.timeScale().fitContent();

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
            // Launchpad tokens routinely move orders of magnitude right after
            // launch — a linear scale flattens everything after the first pump.
            mode: PriceScaleMode.Logarithmic,
        },
        timeScale: {
            borderColor: 'rgba(148, 163, 184, 0.2)',
            timeVisible: true,
            secondsVisible: true,
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
    <div class="tokenChart">
        <div
            v-if="legend"
            class="legend"
            :class="legend.up ? 'legend--up' : 'legend--down'"
        >
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
            <span class="legendItem legendMuted">
                Vol {{ legend.volume }} · block {{ legend.block }}
            </span>
        </div>
        <div ref="host" class="chartHost"></div>
    </div>
</template>

<style scoped>
.tokenChart {
    position: relative;
    width: 100%;
    height: 260px;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: rgba(10, 15, 26, 0.45);
}
.chartHost {
    position: absolute;
    inset: 0;
}
.legend {
    position: absolute;
    top: 8px;
    left: 10px;
    z-index: 3;
    display: flex;
    flex-wrap: wrap;
    gap: 4px 10px;
    align-items: baseline;
    padding: 4px 8px;
    border-radius: 8px;
    background: rgba(8, 12, 20, 0.55);
    backdrop-filter: blur(4px);
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
