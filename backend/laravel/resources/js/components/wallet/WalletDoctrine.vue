<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { useWalletLayers } from '@/composables/useWalletLayers';
import { drawPiece } from '@/lib/wallet/netart';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Layer 13: the bottom.
 *
 * What the whole descent was for, said plainly: the web back in the hands of
 * the people who make it, open source that feeds whoever writes it, and a
 * neighbourhood of homepages to prove both. The line under the title cannot
 * decide whether it is joking. That is the point of it.
 */

const props = defineProps<{ wallet: MultiWallet }>();

const emit = defineEmits<{ back: []; workshop: [] }>();

const { locale, t } = useLocale(walletMessages);

const { fresh } = useWalletLayers(props.wallet, '13');

const art = ref<HTMLCanvasElement | null>(null);

const oscillation = computed(() => [t('docOsc1'), t('docOsc2'), t('docOsc3')]);
const phase = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    const ctx = art.value?.getContext('2d', { willReadFrequently: true });

    if (art.value && ctx) {
        art.value.width = 720;
        art.value.height = 720;
        drawPiece(ctx, 'deus', 13, 720);
    }

    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        timer = setInterval(
            () => (phase.value = (phase.value + 1) % oscillation.value.length),
            2600,
        );
    }
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

const tenets = computed(() => [
    t('docTenet1'),
    t('docTenet2'),
    t('docTenet3'),
    t('docTenet4'),
]);

const freshNote = computed(() =>
    fresh.value?.secret
        ? t('layersFresh', {
              xp: fresh.value.granted,
              title:
                  fresh.value.secret.title[locale.value] ??
                  fresh.value.secret.title.en,
          })
        : null,
);
</script>

<template>
    <div class="cw-stack cw-screen">
        <button type="button" class="cw-back" @click="emit('back')">
            ← {{ t('ugTitle') }}
        </button>

        <canvas ref="art" class="cw-doc-art" aria-hidden="true" />

        <div style="margin: 18px 0 8px">
            <div class="cw-layer-tag">{{ t('layersLayer', { n: '13' }) }}</div>
            <h2 class="cw-title cw-doc-title">{{ t('docTitle') }}</h2>
            <p class="cw-doc-osc" aria-live="off">
                {{ oscillation[phase] }}
            </p>
        </div>

        <p v-if="freshNote" class="cw-note cw-layer-fresh">
            {{ freshNote }}
        </p>

        <p class="cw-doc-lead">{{ t('docLead') }}</p>

        <ol class="cw-doc-tenets">
            <li v-for="tenet in tenets" :key="tenet">{{ tenet }}</li>
        </ol>

        <p class="cw-doc-close">{{ t('docClose') }}</p>

        <button
            type="button"
            class="cw-btn cw-btn-primary"
            style="height: 48px; margin-top: 18px"
            @click="emit('workshop')"
        >
            {{ t('ugWorkshop') }} →
        </button>
    </div>
</template>
