<script setup lang="ts">
import { TriangleAlert } from 'lucide-vue-next';
</script>

<template>
    <div
        class="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-600 dark:text-amber-400"
    >
        <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
        <div><slot /></div>
    </div>
</template>
