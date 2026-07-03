<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Container width: narrow (max-w-3xl), default (max-w-6xl), wide (max-w-7xl). */
        size?: 'narrow' | 'default' | 'wide';
    }>(),
    { size: 'default' },
);

const sizeClass = computed(
    () =>
        ({
            narrow: 'max-w-3xl',
            default: 'max-w-6xl',
            wide: 'max-w-7xl',
        })[props.size],
);
</script>

<template>
    <div class="mx-auto w-full px-4 py-8" :class="sizeClass">
        <slot name="hero" />
        <slot />
    </div>
</template>
