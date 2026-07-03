<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    title: string;
    description?: string;
    icon?: Component;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border py-12 text-center"
    >
        <component
            :is="icon"
            v-if="icon"
            class="h-8 w-8 text-muted-foreground/60"
        />
        <p class="font-medium">{{ title }}</p>
        <p v-if="description" class="max-w-sm text-sm text-muted-foreground">
            {{ description }}
        </p>
        <div class="mt-2">
            <slot />
        </div>
    </div>
</template>
