<script setup lang="ts">
import { ExternalLink } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { useWalletLayers } from '@/composables/useWalletLayers';
import { DOCTRINE_LEVEL } from '@/lib/wallet/layers';
import { fetchUnderground, tokensBy } from '@/lib/wallet/underground';
import type {
    UndergroundDirectory,
    UndergroundEntry,
} from '@/lib/wallet/underground';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Layer 08: the underground.
 *
 * The launchpad's homepages, the way the old web kept them: a directory with
 * a hit counter on every page, a ring that links one to the next, and every
 * token that has not built one yet standing as a vacant lot. The public face
 * of it is underground.cyberia.church; this is the same directory from
 * inside, one floor under the gallery, with the workshop where a creator
 * moves in and the door to the bottom.
 */

const props = defineProps<{ wallet: MultiWallet }>();

const emit = defineEmits<{
    back: [];
    workshop: [address: string | null];
    doctrine: [];
}>();

const { locale, t } = useLocale(walletMessages);

const layers = useWalletLayers(props.wallet, '08');
const { fresh, level } = layers;

const directory = ref<UndergroundDirectory | null>(null);
const failure = ref<string | null>(null);

onMounted(async () => {
    try {
        directory.value = await fetchUnderground();
    } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error);
    }
});

const myAddresses = computed(() =>
    props.wallet.accounts.value
        .filter((account) => account.family === 'evm')
        .map((account) => account.address.toLowerCase()),
);

const mine = computed(
    () =>
        new Set(
            directory.value === null
                ? []
                : tokensBy(directory.value, myAddresses.value).map(
                      (entry) => entry.address,
                  ),
        ),
);

const ringUrl = (direction: 'prev' | 'random' | 'next'): string =>
    `${directory.value?.underground_url ?? ''}/ring/${direction}`;

const host = (entry: UndergroundEntry): string =>
    (entry.site_url ?? '').replace(/^https?:\/\//, '').replace(/\/$/, '');

const ticker = (entry: UndergroundEntry): string =>
    `$${(entry.symbol ?? '').toUpperCase()}`;

const doctrineOpen = computed(() => (level.value ?? 1) >= DOCTRINE_LEVEL);

const creed = computed(() => [
    t('ugCreed1'),
    t('ugCreed2'),
    t('ugCreed3'),
    t('ugCreed4'),
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
            ← {{ t('layersGalleryDoor') }}
        </button>

        <div style="margin: 24px 0 18px">
            <div class="cw-layer-tag">{{ t('layersLayer', { n: '08' }) }}</div>
            <h2 class="cw-title" style="font-size: 22px; margin-top: 6px">
                {{ t('ugTitle') }}
            </h2>
            <p class="cw-prose" style="margin-top: 8px">
                {{ t('ugBody') }}
            </p>
        </div>

        <p v-if="freshNote" class="cw-note cw-layer-fresh">
            {{ freshNote }}
        </p>

        <ol class="cw-ug-creed">
            <li v-for="line in creed" :key="line">{{ line }}</li>
        </ol>

        <div class="cw-label" style="margin: 22px 0 9px">
            {{ t('ugRing') }}
        </div>
        <div class="cw-ug-ring">
            <a :href="ringUrl('prev')" target="_blank" rel="noopener">{{
                t('ugPrev')
            }}</a>
            <a
                class="cw-ug-random"
                :href="ringUrl('random')"
                target="_blank"
                rel="noopener"
                >✶ {{ t('ugRandom') }} ✶</a
            >
            <a :href="ringUrl('next')" target="_blank" rel="noopener">{{
                t('ugNext')
            }}</a>
        </div>
        <a
            v-if="directory"
            class="cw-btn cw-btn-secondary"
            style="height: 44px; margin-top: 10px; text-decoration: none"
            :href="directory.underground_url"
            target="_blank"
            rel="noopener"
        >
            {{ t('ugOpen') }}
            <ExternalLink :size="14" aria-hidden="true" />
        </a>

        <p
            v-if="failure"
            class="cw-hint"
            style="margin-top: 14px; color: var(--cw-bad-soft)"
        >
            {{ failure }}
        </p>

        <template v-if="directory">
            <div class="cw-label" style="margin: 26px 0 9px">
                {{ t('ugHomes') }} · {{ directory.homes.length }}
            </div>
            <p v-if="directory.homes.length === 0" class="cw-hint">
                {{ t('ugHomesEmpty') }}
            </p>
            <div class="cw-ug-grid">
                <div
                    v-for="home in directory.homes"
                    :key="`${home.chain_id}:${home.address}`"
                    class="cw-ug-card"
                >
                    <a
                        class="cw-ug-open"
                        :href="home.site_url ?? '#'"
                        target="_blank"
                        rel="noopener"
                    >
                        <img
                            :src="home.cover_url"
                            alt=""
                            loading="lazy"
                            width="400"
                            height="300"
                        />
                        <span class="cw-ug-sym">{{ ticker(home) }}</span>
                        <span class="cw-ug-name">{{ home.name }}</span>
                        <span class="cw-ug-host">{{ host(home) }}</span>
                        <span class="cw-ug-hits">{{
                            t('ugVisitors', { count: home.site_hits })
                        }}</span>
                    </a>
                    <button
                        v-if="mine.has(home.address)"
                        type="button"
                        class="cw-ug-mine"
                        @click="emit('workshop', home.address)"
                    >
                        {{ t('lpEditHomepage') }} →
                    </button>
                </div>
            </div>

            <div class="cw-label" style="margin: 26px 0 9px">
                {{ t('ugLots') }} · {{ directory.lots.length }}
            </div>
            <div class="cw-ug-grid cw-ug-grid-lots">
                <component
                    :is="mine.has(lot.address) ? 'button' : 'div'"
                    v-for="lot in directory.lots"
                    :key="`${lot.chain_id}:${lot.address}`"
                    class="cw-ug-card cw-ug-lot"
                    :type="mine.has(lot.address) ? 'button' : undefined"
                    @click="
                        mine.has(lot.address) && emit('workshop', lot.address)
                    "
                >
                    <img
                        :src="lot.cover_url"
                        alt=""
                        loading="lazy"
                        width="400"
                        height="300"
                    />
                    <span class="cw-ug-sym">{{ ticker(lot) }}</span>
                    <span class="cw-ug-host">{{
                        mine.has(lot.address) ? t('ugYourLot') : t('ugVacant')
                    }}</span>
                </component>
            </div>
        </template>

        <div class="cw-stack" style="gap: 0; margin-top: 28px">
            <button
                type="button"
                class="cw-line-row"
                @click="emit('workshop', null)"
            >
                <span style="flex: 1">{{ t('ugWorkshop') }}</span>
                <span class="cw-layer-tag">{{
                    t('layersLayer', { n: '09' })
                }}</span>
                →
            </button>
            <button
                type="button"
                class="cw-line-row"
                :disabled="!doctrineOpen"
                :style="{ opacity: doctrineOpen ? 1 : 0.62 }"
                @click="emit('doctrine')"
            >
                <span style="flex: 1">
                    {{ t('docTitle') }}
                    <span
                        v-if="!doctrineOpen"
                        class="cw-label"
                        style="
                            display: block;
                            margin-top: 4px;
                            text-transform: none;
                            letter-spacing: 0;
                        "
                        >{{
                            t('layersGalleryLockedNote', {
                                level: DOCTRINE_LEVEL,
                            })
                        }}</span
                    >
                </span>
                <span class="cw-layer-tag">{{
                    t('layersLayer', { n: '13' })
                }}</span>
                →
            </button>
        </div>
    </div>
</template>
