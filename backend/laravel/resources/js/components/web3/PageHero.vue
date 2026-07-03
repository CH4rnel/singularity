<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
    /** Small uppercase label above the title. */
    eyebrow?: string;
}>();
</script>

<template>
    <header class="mb-8">
        <p
            v-if="eyebrow"
            class="mb-1 text-xs font-semibold tracking-[0.25em] text-brand-cyan uppercase"
        >
            {{ eyebrow }}
        </p>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight">
                    {{ title }}<span class="text-brand-cyan">_</span>
                </h1>
                <p
                    v-if="description"
                    class="mt-2 max-w-2xl text-sm text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
