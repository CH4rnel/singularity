<script setup lang="ts">
import PwaInstallPrompt from '@/components/PwaInstallPrompt.vue';
import SiteFooter from '@/components/web3/SiteFooter.vue';
import SiteHeader from '@/components/web3/SiteHeader.vue';
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <SiteHeader />
        <main class="flex-1">
            <slot />
        </main>
        <SiteFooter />
        <PwaInstallPrompt />
    </div>
</template>
