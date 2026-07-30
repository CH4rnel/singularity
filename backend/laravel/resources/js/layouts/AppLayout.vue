<script setup lang="ts">
import PwaInstallPrompt from '@/components/PwaInstallPrompt.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
        <PwaInstallPrompt />
    </AppLayout>
</template>
