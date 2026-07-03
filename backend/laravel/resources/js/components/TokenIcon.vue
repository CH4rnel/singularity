<script setup lang="ts">
import { computed } from 'vue';
import { hueFor, logoFailed, logoFor, markLogoFailed } from '@/lib/tokenLogos';

// Circular token icon: a token logo when one exists, otherwise a deterministic
// lettered gradient avatar. An explicit `logo` URL (e.g. from the token
// registry) wins over the built-in symbol map; either falls back to the
// gradient on a 404. Shared by the Lending, Farm, Liquidity and token pages.
const props = withDefaults(
    defineProps<{
        symbol: string;
        size?: number;
        ring?: boolean;
        logo?: string | null;
    }>(),
    { size: 40, ring: false, logo: null },
);

const src = computed<string | undefined>(
    () => props.logo ?? logoFor(props.symbol),
);
const useImage = computed(() => !!src.value && !logoFailed(src.value));

const dimension = computed(() => `${props.size}px`);
const fontSize = computed(
    () => `${Math.max(9, Math.round(props.size * 0.32))}px`,
);
const letters = computed(() => props.symbol.slice(0, props.size >= 32 ? 3 : 2));
const gradient = computed(
    () =>
        `linear-gradient(135deg, hsl(${hueFor(props.symbol)} 70% 55%), hsl(${(hueFor(props.symbol) + 40) % 360} 70% 45%))`,
);
</script>

<template>
    <img
        v-if="useImage"
        :src="src"
        :alt="symbol"
        class="shrink-0 rounded-full object-contain"
        :class="ring ? 'bg-card ring-2 ring-card' : ''"
        :style="{ width: dimension, height: dimension }"
        @error="markLogoFailed(src!)"
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
