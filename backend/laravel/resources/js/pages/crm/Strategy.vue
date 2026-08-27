<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Rule from '@/components/console/Rule.vue';
import { useLocale } from '@/composables/useLocale';
import { consoleMessages } from '@/lib/consoleMessages';

defineProps<{
    edited: boolean;
    updatedAt: string | null;
}>();

const { t } = useLocale(consoleMessages);
</script>

<template>
    <Head :title="`Пульт · ${t('strategy.title')}`" />

    <div class="strategy-heading">
        <div>
            <h1 class="mk-h1">{{ t('strategy.title') }}</h1>
            <p class="mk-t3 strategy-lead">{{ t('strategy.lead') }}</p>
        </div>
    </div>

    <div id="strategy-dock" />

    <Rule :label="t('strategy.hintTitle')" :note="t('strategy.hint')" />
</template>

<style scoped>
.strategy-heading {
    flex: 0 0 auto;
}
.strategy-lead {
    max-width: 72ch;
    margin: 7px 0 0;
    font-size: 12px;
    line-height: 1.5;
}
#strategy-dock {
    width: 100%;
    height: calc(100vh - 190px);
    min-height: 540px;
    min-width: 0;
    flex: 1;
}
</style>
