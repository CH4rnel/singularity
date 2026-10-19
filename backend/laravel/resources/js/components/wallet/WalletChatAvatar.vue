<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/**
 * A face for a correspondent.
 *
 * Their profile picture when they have one. Otherwise a gradient drawn from
 * the address itself, so the same person is the same colours on every device
 * and in every list — which is most of what makes a thread findable at a
 * glance — with the first letter of their name, or of the address, on top.
 */
const props = withDefaults(
    defineProps<{
        address: string;
        name?: string;
        src?: string | null;
        size?: number;
    }>(),
    { name: '', src: null, size: 44 },
);

const broken = ref(false);

watch(
    () => props.src,
    () => {
        broken.value = false;
    },
);

const hex = computed(() => props.address.toLowerCase().replace(/^0x/, ''));

const gradient = computed(() => {
    const seed = (start: number): number =>
        parseInt(hex.value.slice(start, start + 4) || '0', 16) || 0;

    const first = seed(0) % 360;
    const second = (first + 50 + (seed(4) % 90)) % 360;
    const angle = 120 + (seed(8) % 120);

    return `linear-gradient(${angle}deg, hsl(${first} 72% 62%), hsl(${second} 70% 46%))`;
});

const initial = computed(() => {
    // A name that is only a shortened address starts with "0x" like every
    // other one; its first hex digit is the part that tells them apart.
    const letter = props.name.trim().replace(/^0x/i, '').charAt(0);

    return (letter || hex.value.charAt(0) || '?').toUpperCase();
});

const style = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`,
    fontSize: `${Math.round(props.size * 0.42)}px`,
    background: props.src && !broken.value ? undefined : gradient.value,
}));
</script>

<template>
    <span class="cw-dm-avatar" :style="style" aria-hidden="true">
        <img
            v-if="src && !broken"
            :src="src"
            alt=""
            loading="lazy"
            @error="broken = true"
        />
        <template v-else>{{ initial }}</template>
    </span>
</template>
