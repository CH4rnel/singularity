<script setup lang="ts">
import PwaInstallPrompt from '@/components/PwaInstallPrompt.vue';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description">
        <slot />
        <PwaInstallPrompt />
    </AuthLayout>
</template>
