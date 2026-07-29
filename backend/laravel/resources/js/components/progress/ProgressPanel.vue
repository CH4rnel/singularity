<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Flame, Trophy } from 'lucide-vue-next';
import { computed } from 'vue';
import LevelBar from '@/components/progress/LevelBar.vue';
import QuestList from '@/components/progress/QuestList.vue';
import { useLocale } from '@/composables/useLocale';
import { progressMessages } from '@/lib/progressMessages';
import { leaderboard } from '@/routes';
import type { Progress } from '@/types/progress';

const props = defineProps<{ progress: Progress }>();

const { t } = useLocale(progressMessages);

const streakHint = computed(() =>
    props.progress.active_today ? t('activeToday') : t('comeBackToday'),
);
</script>

<template>
    <section class="rounded-lg border border-border/70 bg-card p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="text-lg font-bold">{{ t('progress') }}</h2>
            <Link
                :href="leaderboard().url"
                class="inline-flex items-center gap-1.5 text-sm text-brand-cyan hover:underline"
            >
                <Trophy class="size-4" />
                {{ t('viewLeaderboard') }}
            </Link>
        </div>

        <LevelBar :progress="progress" compact />

        <dl class="mt-5 grid grid-cols-3 gap-3">
            <div class="rounded-lg border border-border/70 p-3">
                <dt
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    {{ t('streak') }}
                </dt>
                <dd
                    class="mt-1 flex items-center gap-1.5 font-mono text-xl font-bold"
                >
                    <Flame
                        class="size-4"
                        :class="
                            progress.active_today
                                ? 'text-brand-cyan'
                                : 'text-muted-foreground'
                        "
                    />
                    {{ progress.current_streak }}
                </dd>
                <dd class="mt-1 text-xs text-muted-foreground">
                    {{ streakHint }}
                </dd>
            </div>

            <div class="rounded-lg border border-border/70 p-3">
                <dt
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    {{ t('longestStreak') }}
                </dt>
                <dd class="mt-1 font-mono text-xl font-bold">
                    {{ progress.longest_streak }}
                </dd>
            </div>

            <div class="rounded-lg border border-border/70 p-3">
                <dt
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    {{ t('rank') }}
                </dt>
                <dd class="mt-1 font-mono text-xl font-bold">
                    {{ progress.rank === null ? '—' : `#${progress.rank}` }}
                </dd>
            </div>
        </dl>

        <div class="mt-6">
            <h3 class="mb-3 text-sm font-bold">{{ t('quests') }}</h3>
            <QuestList :quests="progress.quests" />
        </div>

        <div class="mt-6">
            <h3 class="mb-2 text-sm font-bold">{{ t('recentXp') }}</h3>
            <p
                v-if="progress.recent.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('noRecentXp') }}
            </p>
            <ul v-else class="divide-y divide-border/60 text-sm">
                <li
                    v-for="(entry, index) in progress.recent"
                    :key="`${entry.source}-${entry.created_at}-${index}`"
                    class="flex items-center justify-between py-1.5"
                >
                    <span>{{ t(`source.${entry.source}`) }}</span>
                    <span class="font-mono text-brand-cyan"
                        >+{{ entry.amount }}</span
                    >
                </li>
            </ul>
        </div>
    </section>
</template>
