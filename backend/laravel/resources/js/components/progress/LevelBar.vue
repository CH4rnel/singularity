<script setup lang="ts">
import { Flame } from 'lucide-vue-next';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { progressMessages } from '@/lib/progressMessages';
import type { Progress } from '@/types/progress';

const props = defineProps<{
    progress: Progress;
    /** Hide the streak chip on surfaces that show it separately. */
    compact?: boolean;
}>();

const { t } = useLocale(progressMessages);

const remaining = computed(() =>
    props.progress.next_level_xp === null
        ? null
        : Math.max(0, props.progress.next_level_xp - props.progress.xp),
);
</script>

<template>
    <div>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div class="flex items-baseline gap-2">
                <span class="font-mono text-2xl font-bold">
                    {{ t('level') }} {{ progress.level }}
                </span>
                <span class="text-sm text-brand-cyan">{{
                    progress.title
                }}</span>
            </div>

            <div
                v-if="!compact && progress.current_streak > 0"
                class="flex items-center gap-1 text-sm"
                :class="
                    progress.active_today
                        ? 'text-brand-cyan'
                        : 'text-muted-foreground'
                "
                :title="
                    t('streakMeaning') +
                    ' ' +
                    (progress.active_today
                        ? t('activeToday')
                        : t('comeBackToday'))
                "
            >
                <Flame class="size-4" />
                <span class="font-mono font-bold">{{
                    progress.current_streak
                }}</span>
                <span>{{ t('streakDays') }}</span>
            </div>
        </div>

        <div
            class="mt-3 h-2 w-full overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-valuenow="progress.progress_pct"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="h-full rounded-full bg-brand-cyan transition-[width] duration-500"
                :style="{ width: `${progress.progress_pct}%` }"
            />
        </div>

        <p class="mt-2 font-mono text-xs text-muted-foreground">
            {{ progress.xp.toLocaleString() }} {{ t('xp') }}
            <template v-if="remaining !== null">
                · {{ remaining.toLocaleString() }} {{ t('toNextLevel') }}
            </template>
            <template v-else> · {{ t('maxLevel') }} </template>
        </p>
    </div>
</template>
