<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    hint?: string;
}>();
</script>

<template>
    <div class="rounded-lg border border-border/70 bg-card p-4">
        <p class="text-xs tracking-widest text-muted-foreground uppercase">
            {{ label }}
        </p>
        <p class="mt-1 font-mono text-2xl font-bold">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">
            {{ hint }}
        </p>
    </div>
</template>
