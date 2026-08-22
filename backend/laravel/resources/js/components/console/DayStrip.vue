<script setup lang="ts">
import { computed } from 'vue';

/**
 * A day of a service's life, one cell an hour.
 *
 * The last sweep says what is true now; this says whether it has been true
 * all night. Hours the monitor missed are drawn hatched rather than as any
 * state at all — not knowing must never look like knowing.
 */
const props = withDefaults(
    defineProps<{ cells: string[]; width?: number; height?: number }>(),
    { width: 132, height: 10 },
);

const COLORS: Record<string, string> = {
    up: '#2a3436',
    degraded: 'var(--mk-warning)',
    down: 'var(--mk-critical)',
    off: '#171d1e',
};

const boxes = computed(() => {
    const cells = props.cells ?? [];

    if (cells.length === 0) {
        return [];
    }

    const gap = 1;
    const width = (props.width - gap * (cells.length - 1)) / cells.length;

    return cells.map((status, index) => ({
        x: index * (width + gap),
        width,
        status,
        fill: COLORS[status] ?? 'none',
        hatched: status === 'unknown' || status === 'gap',
    }));
});
</script>

<template>
    <svg
        v-if="boxes.length"
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        fill="none"
    >
        <defs>
            <pattern
                id="mk-hatch"
                width="4"
                height="4"
                patternTransform="rotate(45)"
                patternUnits="userSpaceOnUse"
            >
                <line
                    x1="0"
                    y1="0"
                    x2="0"
                    y2="4"
                    stroke="rgba(141,154,157,.5)"
                    stroke-width="1"
                />
            </pattern>
        </defs>
        <rect
            v-for="(box, index) in boxes"
            :key="index"
            :x="box.x"
            y="0"
            :width="box.width"
            :height="height"
            :fill="box.hatched ? 'url(#mk-hatch)' : box.fill"
        />
    </svg>
</template>
