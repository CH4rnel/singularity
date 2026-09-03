<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useLocale } from '@/composables/useLocale';
import { progressMessages } from '@/lib/progressMessages';

/**
 * Unlocked, and the build is not on this host.
 *
 * A separate screen from the locked one because it is somebody else's problem
 * to fix: the person paid, and a broken canvas would look like they were
 * cheated.
 */
const { t } = useLocale(progressMessages);
</script>

<template>
    <Head title="NO CARRIER" />

    <div class="mx-auto flex min-h-screen max-w-lg flex-col justify-center gap-4 p-6">
        <p class="font-mono text-xs tracking-widest text-muted-foreground uppercase">
            NO CARRIER
        </p>
        <h1 class="text-2xl font-bold">{{ t('gameMissing') }}</h1>
        <p class="text-sm text-muted-foreground">{{ t('gameMissingBody') }}</p>
    </div>
</template>
