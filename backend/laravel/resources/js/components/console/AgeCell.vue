<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { age, plural, toneColor } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';

/**
 * The left-hand column of every queue row: how long this has been true.
 *
 * It is the priority. Twelve minutes and three hours ask for different things
 * and look identical on a board that only shows the current state, so the
 * duration gets the largest type in the row and the row's colour.
 */
const props = defineProps<{
    seconds: number | null;
    tone: string;
    compact?: boolean;
}>();

const { locale, t } = useLocale(consoleMessages);

const value = computed(() => age(props.seconds));
const color = computed(() => toneColor(props.tone));
const unit = computed(() =>
    value.value ? plural(locale.value, value.value.count, t(value.value.unit)) : '',
);
</script>

<template>
    <div class="mk-age">
        <template v-if="value">
            <div
                class="mk-num"
                :style="{
                    fontSize: compact ? '17px' : '21px',
                    color,
                    lineHeight: 1,
                }"
            >
                {{ value.value }}
            </div>
            <div
                class="mk-k"
                :style="{ marginTop: '4px', color, opacity: 0.7 }"
            >
                {{ unit }}
            </div>
        </template>
        <div v-else class="mk-k" style="opacity: 0.6">—</div>
    </div>
</template>
