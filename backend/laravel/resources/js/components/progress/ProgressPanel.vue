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

/**
 * What the level is worth right now, and what the next one adds.
 *
 * Shown together on purpose: a perk with no next rung reads as a ceiling, and
 * a next rung with nothing already earned reads as a promise. The pair reads
 * as a ladder.
 */
const discount = computed(
    () => props.progress.perks.crosschain_fee_discount ?? 0,
);

const nextDiscount = computed(
    () => props.progress.next_perk?.perks.crosschain_fee_discount ?? null,
);

/** How much proven XP is still owed for the next rung. */
const provenToGo = computed(() =>
    props.progress.next_perk === null
        ? 0
        : Math.max(0, props.progress.next_perk.xp - props.progress.proven_xp),
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

        <!--
          The reason a level exists. Without this the panel is a scoreboard,
          which is what it was: a number that went up and bought nothing.
        -->
        <div
            class="mt-5 rounded-lg border p-4"
            :class="
                discount > 0
                    ? 'border-brand-cyan/40 bg-brand-cyan/5'
                    : 'border-border/70'
            "
        >
            <div class="flex items-baseline justify-between gap-3">
                <span
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    {{ t('perksTitle') }}
                </span>
                <span class="text-xs text-muted-foreground">
                    {{ t('provenXp') }}: {{ progress.proven_xp }}
                </span>
            </div>

            <p v-if="discount > 0" class="mt-2 text-sm">
                <span class="font-bold text-brand-cyan"
                    >−{{ discount }}%</span
                >
                {{ t('perkCrosschainFee') }}
            </p>
            <p v-else class="mt-2 text-sm text-muted-foreground">
                {{ t('perksNone') }}
            </p>

            <p
                v-if="nextDiscount !== null"
                class="mt-2 text-xs text-muted-foreground"
            >
                {{
                    t('perkNext')
                        .replace('{level}', String(progress.next_perk!.level))
                        .replace('{discount}', String(nextDiscount))
                        .replace('{xp}', String(provenToGo))
                }}
            </p>
        </div>

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
