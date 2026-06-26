<script setup lang="ts">
import { computed } from 'vue';
import { hueFor, logoFor, markLogoFailed, showLogo } from '@/lib/tokenLogos';

// Circular token icon: the DEX logo when one exists, otherwise a deterministic
// lettered gradient avatar. Shared by the Lending and Farm pages.
const props = withDefaults(
    defineProps<{ symbol: string; size?: number; ring?: boolean }>(),
    { size: 40, ring: false },
);

const dimension = computed(() => `${props.size}px`);
const fontSize = computed(() => `${Math.max(9, Math.round(props.size * 0.32))}px`);
const letters = computed(() => props.symbol.slice(0, props.size >= 32 ? 3 : 2));
const gradient = computed(
    () =>
        `linear-gradient(135deg, hsl(${hueFor(props.symbol)} 70% 55%), hsl(${(hueFor(props.symbol) + 40) % 360} 70% 45%))`,
);
</script>

<template>
    <img
        v-if="showLogo(symbol)"
        :src="logoFor(symbol)"
        :alt="symbol"
        class="shrink-0 rounded-full object-contain"
        :class="ring ? 'bg-card ring-2 ring-card' : ''"
        :style="{ width: dimension, height: dimension }"
        @error="markLogoFailed(symbol)"
    />
    <span
        v-else
        class="flex shrink-0 items-center justify-center rounded-full font-bold text-white"
        :class="ring ? 'ring-2 ring-card' : ''"
        :style="{
            width: dimension,
            height: dimension,
            fontSize,
            background: gradient,
        }"
    >
        {{ letters }}
    </span>
</template>
