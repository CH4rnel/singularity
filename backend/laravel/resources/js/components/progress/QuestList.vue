<script setup lang="ts">
import { Check } from 'lucide-vue-next';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { progressMessages } from '@/lib/progressMessages';
import type { Quest } from '@/types/progress';

const props = defineProps<{ quests: Quest[] }>();

const { locale, t } = useLocale(progressMessages);

const groups = computed(() =>
    (['daily', 'weekly'] as const)
        .map((period) => ({
            period,
            label: t(period),
            quests: props.quests.filter((quest) => quest.period === period),
        }))
        .filter((group) => group.quests.length > 0),
);

const pct = (quest: Quest): number =>
    quest.target === 0
        ? 100
        : Math.min(100, Math.round((quest.progress / quest.target) * 100));
</script>

<template>
    <div class="space-y-5">
        <section v-for="group in groups" :key="group.period">
            <h3
                class="mb-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
            >
                {{ group.label }}
            </h3>

            <ul class="space-y-2">
                <li
                    v-for="quest in group.quests"
                    :key="quest.key"
                    class="rounded-lg border p-3 transition-colors"
                    :class="
                        quest.completed
                            ? 'border-brand-cyan/40 bg-brand-cyan/5'
                            : 'border-border/70 bg-card'
                    "
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex items-center gap-1.5 font-semibold">
                                <Check
                                    v-if="quest.completed"
                                    class="size-4 shrink-0 text-brand-cyan"
                                />
                                {{ quest.title[locale] }}
                            </p>
                            <p class="mt-0.5 text-sm text-muted-foreground">
                                {{ quest.description[locale] }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 font-mono text-sm font-bold"
                            :class="
                                quest.completed
                                    ? 'text-brand-cyan'
                                    : 'text-muted-foreground'
                            "
                        >
                            +{{ quest.xp }}
                        </span>
                    </div>

                    <div class="mt-2 flex items-center gap-2">
                        <div
                            class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full transition-[width] duration-500"
                                :class="
                                    quest.completed
                                        ? 'bg-brand-cyan'
                                        : 'bg-foreground/40'
                                "
                                :style="{ width: `${pct(quest)}%` }"
                            />
                        </div>
                        <span
                            class="shrink-0 font-mono text-xs text-muted-foreground"
                        >
                            <template v-if="quest.completed">{{
                                t('done')
                            }}</template>
                            <template v-else
                                >{{ quest.progress }}/{{
                                    quest.target
                                }}</template
                            >
                        </span>
                    </div>
                </li>
            </ul>
        </section>

        <p class="text-xs text-muted-foreground">{{ t('questsHint') }}</p>
    </div>
</template>
