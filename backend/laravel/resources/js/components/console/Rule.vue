<script setup lang="ts">
/**
 * The only way a block is named here: a small caps label and a hairline out
 * to the edge. A heading in body size would out-shout the data under it, and
 * in a grid this dense every border costs more attention than it separates.
 */
defineProps<{ label: string; note?: string | null; tone?: string | null }>();
</script>

<template>
    <div class="mk-rule">
        <span
            v-if="tone"
            class="mk-dot"
            :style="{ background: `var(--mk-${tone})` }"
        />
        <span class="mk-k" style="color: var(--mk-body)">{{ label }}</span>
        <span v-if="note" class="mk-k">{{ note }}</span>
        <slot />
    </div>
</template>
