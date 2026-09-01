<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Lock } from 'lucide-vue-next';
import { useLocale } from '@/composables/useLocale';
import { progressMessages } from '@/lib/progressMessages';
import type { Enchantment } from '@/types/progress';

/**
 * The table experience is spent at.
 *
 * XP was a number that went up and bought nothing, which is why nobody chased
 * it. Here it is a currency: you choose something, you pay for it, the balance
 * drops and what you bought is permanent.
 *
 * Every locked row says which of the two things is missing — standing or
 * balance — because they are different things to go and do, and a single
 * "unavailable" would hide which one.
 */
const props = defineProps<{
    enchantments: Enchantment[];
    spendable: number;
    provenLevel: number;
}>();

const { t, locale } = useLocale(progressMessages);

const form = useForm({ key: '' });

const buy = (key: string): void => {
    form.key = key;
    form.post('/profile/enchant', { preserveScroll: true });
};

const text = (value: Record<string, string | undefined>): string =>
    value[locale.value] ?? value.en ?? '';

const hint = (row: Enchantment): string =>
    ({
        owned: t('enchantOwned'),
        ready: '',
        level: t('enchantNeedsLevel').replace('{level}', String(row.level)),
        xp: t('enchantNeedsXp').replace(
            '{xp}',
            String(row.cost - props.spendable),
        ),
        requires: t('enchantNeedsPrevious'),
    })[row.state];
</script>

<template>
    <div>
        <div class="mb-3 flex items-baseline justify-between gap-3">
            <h3 class="text-sm font-bold">{{ t('enchantTitle') }}</h3>
            <span class="text-xs text-muted-foreground">
                {{ t('enchantBalance') }}:
                <span class="font-bold text-brand-cyan">{{ spendable }}</span>
            </span>
        </div>

        <p class="mb-3 text-xs text-muted-foreground">
            {{ t('enchantIntro') }}
        </p>

        <ul class="flex flex-col gap-2">
            <li
                v-for="row in enchantments"
                :key="row.key"
                class="rounded-lg border p-3"
                :class="
                    row.state === 'owned'
                        ? 'border-brand-cyan/40 bg-brand-cyan/5'
                        : 'border-border/70'
                "
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex items-center gap-1.5 text-sm font-bold">
                            <Check
                                v-if="row.state === 'owned'"
                                class="size-4 shrink-0 text-brand-cyan"
                            />
                            <Lock
                                v-else-if="row.state !== 'ready'"
                                class="size-3.5 shrink-0 text-muted-foreground"
                            />
                            {{ text(row.title) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ text(row.description) }}
                        </p>
                        <p
                            v-if="hint(row)"
                            class="mt-1 text-xs text-muted-foreground/80"
                        >
                            {{ hint(row) }}
                        </p>
                    </div>

                    <button
                        v-if="row.state === 'ready'"
                        type="button"
                        :disabled="form.processing"
                        class="shrink-0 rounded-md bg-brand-cyan px-3 py-1.5 text-xs font-bold text-background disabled:opacity-50"
                        @click="buy(row.key)"
                    >
                        {{ row.cost }} XP
                    </button>
                    <span
                        v-else-if="row.state !== 'owned'"
                        class="shrink-0 text-xs text-muted-foreground"
                    >
                        {{ row.cost }} XP
                    </span>
                </div>
            </li>
        </ul>
    </div>
</template>
