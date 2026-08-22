<script setup lang="ts">
import { computed } from 'vue';
import { toneColor } from '@/lib/console';

/**
 * A line, at the size of a word.
 *
 * Deliberately axis-less and label-less: it answers "which way and how
 * sharply", and a reader who needs the actual numbers has the block the spark
 * sits next to. A single point draws nothing rather than a flat line, which
 * would read as "steady" when it means "one reading".
 */
const props = withDefaults(
    defineProps<{
        values: number[];
        tone?: string;
        width?: number;
        height?: number;
        fill?: boolean;
    }>(),
    { tone: 'plain', width: 132, height: 26, fill: false },
);

const points = computed(() => {
    const values = props.values ?? [];

    // One reading draws nothing, and neither does a series of zeroes: a flat
    // line reads as "steady", which is a different claim from "no data".
    if (values.length < 2 || values.every((value) => value === 0)) {
        return null;
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const step = (props.width - 2) / (values.length - 1);

    return values
        .map((value, index) => {
            const x = 1 + index * step;
            const y =
                props.height - 3 - ((value - min) / span) * (props.height - 6);

            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});

const color = computed(() => toneColor(props.tone));
</script>

<template>
    <svg
        v-if="points"
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        fill="none"
    >
        <polygon
            v-if="fill"
            :points="`1,${height} ${points} ${width - 1},${height}`"
            :fill="color"
            opacity=".12"
        />
        <polyline
            :points="points"
            :stroke="color"
            stroke-width="1.4"
            stroke-linejoin="round"
            stroke-linecap="round"
        />
    </svg>
</template>
