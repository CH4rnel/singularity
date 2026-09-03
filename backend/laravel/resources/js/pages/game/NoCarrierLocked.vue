<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import { useLocale } from '@/composables/useLocale';
import { progressMessages } from '@/lib/progressMessages';

/**
 * What somebody sees when the game is not theirs yet.
 *
 * It names the price and what they have, because "locked" without a number is
 * a wall and "locked, 800 short" is a reason to come back.
 */
const props = defineProps<{ cost: number; spendable: number }>();

const { t } = useLocale(progressMessages);
</script>

<template>
    <Head title="NO CARRIER" />

    <div class="mx-auto flex min-h-screen max-w-lg flex-col justify-center gap-5 p-6">
        <p class="font-mono text-xs tracking-widest text-muted-foreground uppercase">
            NO CARRIER
        </p>

        <h1 class="flex items-center gap-2 text-2xl font-bold">
            <Lock class="size-5 shrink-0 text-muted-foreground" />
            {{ t('gameLocked') }}
        </h1>

        <p class="text-sm text-muted-foreground">{{ t('gameLockedBody') }}</p>

        <p class="font-mono text-sm">
            {{ props.spendable }} / {{ props.cost }} XP
        </p>

        <Link
            href="/profile"
            class="self-start rounded-md bg-brand-cyan px-4 py-2 text-sm font-bold text-background"
        >
            {{ t('gameToProfile') }}
        </Link>
    </div>
</template>
