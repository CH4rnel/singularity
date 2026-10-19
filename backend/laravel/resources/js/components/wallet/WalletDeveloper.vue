<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { useWalletLayers } from '@/composables/useWalletLayers';
import { levelPct } from '@/lib/wallet/daily';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Layer 02: developer settings.
 *
 * The first floor below the wallet. Nobody needs anything here to send, swap
 * or hold — which is exactly why progress starts here: whoever scrolled to the
 * bottom of Settings and opened this already wanted more than a wallet.
 *
 * Progress is shown here and nowhere above. The quest board, the streak and
 * the leaderboard are one tap further (`daily`), and the strange things are a
 * layer down (`experiments`).
 */

const props = defineProps<{ wallet: MultiWallet }>();

const emit = defineEmits<{
    back: [];
    experiments: [];
    daily: [];
}>();

const { locale, t } = useLocale(walletMessages);

const layers = useWalletLayers(props.wallet, '02');
const { board, fresh, signedIn, signingIn, failure, found } = layers;

const standing = computed(() => board.value?.standing ?? null);

const xpLabel = computed(() => {
    const value = standing.value;

    if (value === null) {
        return '';
    }

    return value.nextLevelXp === null
        ? t('dailyXpMax', { xp: value.xp.toLocaleString(locale.value) })
        : t('dailyXpOf', {
              xp: value.xp.toLocaleString(locale.value),
              next: value.nextLevelXp.toLocaleString(locale.value),
          });
});

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

const foundNote = computed(() =>
    t('layersFound', {
        found: found.value.length,
        total: board.value?.secrets?.length ?? 0,
    }),
);

/**
 * TEMPORARY — a readout of how this window is actually laid out.
 *
 * The frame's bottom edge is wrong on one person's iPhone and right on every
 * emulator here, and no Safari on this side of the cable can be attached to a
 * home-screen app. So the app states its own numbers and a screenshot carries
 * them back. It lived at the bottom of Settings, in front of everybody; this
 * is where a readout like this belongs. Delete it once that is answered.
 */
const frameProbe = ref<string[]>([]);

const readFrameProbe = (): void => {
    const px = (value: number | undefined): string =>
        value === undefined ? '—' : String(Math.round(value));

    const probe = document.createElement('div');
    probe.style.cssText =
        'position:fixed;top:0;left:0;padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);visibility:hidden;pointer-events:none';
    document.body.append(probe);
    const insets = getComputedStyle(probe);
    const safe = `${insets.paddingTop} / ${insets.paddingBottom}`;
    probe.remove();

    const rect = (selector: string): string => {
        const element = document.querySelector(selector);

        if (!element) {
            return 'нет';
        }

        const box = element.getBoundingClientRect();
        const style = getComputedStyle(element);

        return `${px(box.top)}–${px(box.bottom)} pad ${style.paddingTop}/${style.paddingBottom}`;
    };

    frameProbe.value = [
        `win ${px(window.innerWidth)}×${px(window.innerHeight)} vv ${px(window.visualViewport?.height)} scr ${px(window.screen.height)}`,
        `client ${px(document.documentElement.clientHeight)} vh ${(() => {
            const unit = document.createElement('div');
            unit.style.cssText =
                'position:fixed;top:0;left:0;width:1px;height:100vh;visibility:hidden;pointer-events:none';
            document.body.append(unit);
            const height = unit.getBoundingClientRect().height;
            unit.remove();

            return px(height);
        })()}`,
        `safe ${safe}`,
        `insets=${document.documentElement.dataset.windowInsets ?? '—'} boot ${
            document.documentElement.dataset.bootHeight ?? '—'
        }`,
        `vp ${document.querySelector<HTMLMetaElement>('meta[name=viewport]')?.content ?? '—'}`,
        `app=${document.documentElement.dataset.appWindow ?? '0'} sa=${
            (window.navigator as { standalone?: boolean }).standalone === true
                ? 1
                : 0
        } dm=${window.matchMedia('(display-mode: standalone)').matches ? 1 : 0}`,
        `frame ${rect('.cw-frame')}`,
        `shell ${rect('.cw-shell')}`,
        `tabs ${rect('.cw-tabs')}`,
        `html ${getComputedStyle(document.documentElement).backgroundColor}`,
        `body ${getComputedStyle(document.body).backgroundColor}`,
    ];
};

onMounted(readFrameProbe);
</script>

<template>
    <div class="cw-stack cw-screen">
        <button type="button" class="cw-back" @click="emit('back')">
            ← {{ t('navPreferences') }}
        </button>

        <div style="margin: 24px 0 20px">
            <div class="cw-layer-tag">{{ t('layersLayer', { n: '02' }) }}</div>
            <h2 class="cw-title" style="font-size: 22px; margin-top: 6px">
                {{ t('layersDoor') }}
            </h2>
            <p class="cw-prose" style="margin-top: 8px">
                {{ t('layers02Body') }}
            </p>
        </div>

        <p v-if="freshNote" class="cw-note cw-layer-fresh">
            {{ freshNote }}
        </p>

        <div class="cw-label" style="margin-bottom: 9px">
            {{ t('layersProgress') }}
        </div>

        <div class="cw-card" style="padding: 16px; margin-bottom: 18px">
            <template v-if="signedIn && standing">
                <div class="cw-row" style="justify-content: space-between">
                    <span class="cw-data">
                        {{ t('dailyLevelTag', { level: standing.level }) }}
                        · {{ standing.title }}
                    </span>
                    <span class="cw-label">{{ xpLabel }}</span>
                </div>
                <div class="cw-bar" style="margin-top: 10px">
                    <div
                        class="cw-bar-fill"
                        :style="{ width: `${levelPct(standing)}%` }"
                    />
                </div>
                <span class="cw-hint" style="margin-top: 10px">{{
                    foundNote
                }}</span>
                <button
                    type="button"
                    class="cw-btn cw-btn-secondary"
                    style="height: 44px; margin-top: 14px"
                    @click="emit('daily')"
                >
                    {{ t('layersProgressOpen') }}
                </button>
            </template>
            <template v-else>
                <p class="cw-prose">{{ t('layersProgressAnon') }}</p>
                <button
                    type="button"
                    class="cw-btn cw-btn-primary"
                    style="height: 44px; margin-top: 14px"
                    :disabled="signingIn || layers.address.value === null"
                    @click="layers.signIn()"
                >
                    {{ signingIn ? t('dailySigningIn') : t('dailySignIn') }}
                </button>
            </template>
            <p
                v-if="failure"
                class="cw-hint"
                style="margin-top: 10px; color: var(--cw-bad-soft)"
            >
                {{ failure }}
            </p>
        </div>

        <div class="cw-stack" style="gap: 0; margin-bottom: 24px">
            <button
                type="button"
                class="cw-line-row"
                @click="emit('experiments')"
            >
                <span style="flex: 1">
                    {{ t('layersExperimentsDoor') }}
                </span>
                <span class="cw-layer-tag">{{
                    t('layersLayer', { n: '03' })
                }}</span>
                →
            </button>
        </div>

        <div class="cw-label" style="margin-bottom: 9px">
            {{ t('layersFrameProbe') }}
        </div>
        <div class="cw-layer-probe">
            <div v-for="line in frameProbe" :key="line">{{ line }}</div>
            <button
                type="button"
                class="cw-ghost"
                style="margin-top: 6px"
                @click="readFrameProbe()"
            >
                {{ t('layersRefresh') }}
            </button>
        </div>
    </div>
</template>
