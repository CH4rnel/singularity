<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Wallet address or any stable identity string. */
        seed: string | null | undefined;
        name?: string | null;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { name: null, size: 'md' },
);

// Deterministic identicon: hash the seed into two hues for a gradient.
const hash = computed(() => {
    const input = props.seed || props.name || '?';
    let h = 0;

    for (let i = 0; i < input.length; i++) {
        h = (h * 31 + input.charCodeAt(i)) >>> 0;
    }

    return h;
});

const gradient = computed(() => {
    const h1 = hash.value % 360;
    const h2 = (h1 + 90 + (hash.value % 120)) % 360;

    return `linear-gradient(135deg, hsl(${h1} 80% 45%), hsl(${h2} 80% 35%))`;
});

const initials = computed(() => {
    if (props.name && props.name.trim().length > 0) {
        return props.name
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map((part) => part[0]!.toUpperCase())
            .join('');
    }

    if (props.seed && props.seed.startsWith('0x')) {
        return props.seed.slice(2, 4).toUpperCase();
    }

    return '?';
});

const sizeClass = computed(
    () =>
        ({
            sm: 'h-6 w-6 text-[9px]',
            md: 'h-8 w-8 text-[11px]',
            lg: 'h-16 w-16 text-xl',
        })[props.size],
);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center rounded-full font-bold text-white select-none"
        :class="sizeClass"
        :style="{ background: gradient }"
    >
        {{ initials }}
    </span>
</template>
