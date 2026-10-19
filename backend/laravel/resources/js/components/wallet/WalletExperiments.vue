<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { useWalletExperiments } from '@/composables/useWalletExperiments';
import { useWalletLayers } from '@/composables/useWalletLayers';
import type { DailySecret } from '@/lib/wallet/daily';
import {
    EXPERIMENTS,
    GALLERY_LEVEL,
    UNDERGROUND_LEVEL,
    claimSecret,
    isUnlocked,
    missLine,
    runCommand,
} from '@/lib/wallet/layers';
import type { ExperimentId, Requirement } from '@/lib/wallet/layers';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Layer 03: experiments.
 *
 * Switches that do nothing useful and a terminal that answers. Each switch
 * asks for a level or for a particular find, and says which — a locked row
 * that does not say what opens it is a wall, not a game.
 *
 * The terminal is where finds are made. Commands are a handful of fixed
 * replies (`runCommand`); everything else is a guess, sent to the server,
 * which knows the answers only as hashes.
 */

const props = defineProps<{ wallet: MultiWallet }>();

const emit = defineEmits<{ back: []; gallery: []; underground: [] }>();

const { locale, t } = useLocale(walletMessages);

const layers = useWalletLayers(props.wallet, '03');
const { board, fresh, signedIn, signingIn, level, found } = layers;

const { isOn, toggle } = useWalletExperiments();

const pickText = (text: Record<string, string> | null | undefined): string =>
    text ? (text[locale.value] ?? text.en ?? '') : '';

const secretByKey = (key: string): DailySecret | undefined =>
    board.value?.secrets?.find((secret) => secret.key === key);

/** Why a row is locked, in the words of whatever opens it. */
const lockReason = (requirement: Requirement): string =>
    'level' in requirement
        ? t('layersNeedsLevel', { level: requirement.level })
        : t('layersNeedsFind', {
              hint: pickText(secretByKey(requirement.secret)?.hint),
          });

const EXPERIMENT_TEXT: Record<ExperimentId, { title: string; hint: string }> = {
    crt: { title: 'xCrt', hint: 'xCrtHint' },
    rain: { title: 'xRain', hint: 'xRainHint' },
    whispers: { title: 'xWhispers', hint: 'xWhispersHint' },
    glitch: { title: 'xGlitch', hint: 'xGlitchHint' },
    psyche: { title: 'xPsyche', hint: 'xPsycheHint' },
    invert: { title: 'xInvert', hint: 'xInvertHint' },
};

const rows = computed(() =>
    EXPERIMENTS.map((entry) => ({
        id: entry.id,
        title: t(EXPERIMENT_TEXT[entry.id].title),
        hint: t(EXPERIMENT_TEXT[entry.id].hint),
        open: isUnlocked(entry.requires, level.value, found.value),
        reason: lockReason(entry.requires),
    })),
);

const galleryOpen = computed(() => (level.value ?? 1) >= GALLERY_LEVEL);

/* ------------------------------------------------------------ terminal --- */

type Line = { text: string; tone?: 'in' | 'ok' | 'bad' | 'dim' };

const log = ref<Line[]>([{ text: t('layersTerminalHint'), tone: 'dim' }]);
const input = ref('');
const busy = ref(false);
const screen = ref<HTMLElement | null>(null);

const print = (lines: Line[]): void => {
    log.value = [...log.value, ...lines].slice(-60);
    void nextTick(() => {
        if (screen.value) {
            screen.value.scrollTop = screen.value.scrollHeight;
        }
    });
};

const submit = async (): Promise<void> => {
    const line = input.value;
    input.value = '';

    if (busy.value || line.trim() === '') {
        return;
    }

    print([{ text: `> ${line}`, tone: 'in' }]);

    const action = runCommand(line, {
        who: layers.address.value,
        readme: t('layersReadme').split('\n'),
        help: t('layersHelp').split('\n'),
    });

    if (action.kind === 'print') {
        print(action.lines.map((text) => ({ text })));

        return;
    }

    if (action.kind === 'clear') {
        log.value = [];

        return;
    }

    if (action.kind === 'open') {
        const needs =
            action.target === 'gallery' ? GALLERY_LEVEL : UNDERGROUND_LEVEL;

        if ((level.value ?? 1) >= needs) {
            if (action.target === 'gallery') {
                emit('gallery');
            } else {
                emit('underground');
            }
        } else {
            print([
                {
                    text: t('layersGalleryLocked', { level: needs }),
                    tone: 'bad',
                },
            ]);
        }

        return;
    }

    if (action.kind !== 'claim') {
        return;
    }

    if (!signedIn.value) {
        print([{ text: t('layersSignInToKeep'), tone: 'bad' }]);

        return;
    }

    busy.value = true;

    try {
        const answer = await claimSecret(action.code);
        layers.apply(answer);
        const claim = answer.claim;
        const title = pickText(claim.secret?.title);

        if (!claim.found) {
            print([{ text: missLine(action.code), tone: 'dim' }]);
        } else if (claim.fresh) {
            print([
                {
                    text: t('layersAccepted', { title, xp: claim.granted }),
                    tone: 'ok',
                },
            ]);
        } else {
            print([{ text: t('layersAlready', { title }), tone: 'dim' }]);
        }
    } catch (error) {
        print([
            {
                text: error instanceof Error ? error.message : String(error),
                tone: 'bad',
            },
        ]);
    } finally {
        busy.value = false;
    }
};

const freshNote = computed(() =>
    fresh.value?.secret
        ? t('layersFresh', {
              xp: fresh.value.granted,
              title: pickText(fresh.value.secret.title),
          })
        : null,
);

/** Found first, then what is still a hint. */
const finds = computed(() =>
    [...(board.value?.secrets ?? [])].sort(
        (a, b) => Number(b.found) - Number(a.found),
    ),
);
</script>

<template>
    <div class="cw-stack cw-screen">
        <button type="button" class="cw-back" @click="emit('back')">
            ← {{ t('layersDoor') }}
        </button>

        <div style="margin: 24px 0 20px">
            <div class="cw-layer-tag">{{ t('layersLayer', { n: '03' }) }}</div>
            <h2 class="cw-title" style="font-size: 22px; margin-top: 6px">
                {{ t('layersExperimentsDoor') }}
            </h2>
            <p class="cw-prose" style="margin-top: 8px">
                {{ t('layers03Body') }}
            </p>
        </div>

        <p v-if="freshNote" class="cw-note cw-layer-fresh">
            {{ freshNote }}
        </p>

        <div class="cw-card" style="padding: 0; margin-bottom: 22px">
            <label
                v-for="(row, index) in rows"
                :key="row.id"
                class="cw-row"
                :style="{
                    padding: '14px 16px',
                    borderTop:
                        index === 0 ? 'none' : '1px solid var(--cw-line)',
                    cursor: row.open ? 'pointer' : 'default',
                    opacity: row.open ? 1 : 0.62,
                }"
            >
                <span style="flex: 1; min-width: 0">
                    <span class="cw-data" style="display: block">{{
                        row.title
                    }}</span>
                    <span
                        class="cw-label"
                        style="
                            display: block;
                            margin-top: 4px;
                            text-transform: none;
                            letter-spacing: 0;
                        "
                        >{{ row.open ? row.hint : row.reason }}</span
                    >
                </span>
                <input
                    type="checkbox"
                    :checked="isOn(row.id)"
                    :disabled="!row.open && !isOn(row.id)"
                    style="
                        width: 20px;
                        height: 20px;
                        accent-color: var(--cw-accent);
                    "
                    @change="
                        toggle(
                            row.id,
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
            </label>
        </div>

        <div class="cw-label" style="margin-bottom: 9px">
            {{ t('layersTerminal') }}
        </div>
        <div class="cw-term">
            <div ref="screen" class="cw-term-screen">
                <div
                    v-for="(line, index) in log"
                    :key="index"
                    :class="line.tone ? `cw-term-${line.tone}` : ''"
                >
                    {{ line.text }}
                </div>
            </div>
            <form class="cw-term-prompt" @submit.prevent="submit">
                <span aria-hidden="true">lain@wired:~$</span>
                <input
                    v-model="input"
                    type="text"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    :aria-label="t('layersTerminal')"
                    :disabled="busy"
                />
            </form>
        </div>
        <button
            v-if="!signedIn"
            type="button"
            class="cw-btn cw-btn-secondary"
            style="height: 44px; margin-top: 10px"
            :disabled="signingIn || layers.address.value === null"
            @click="layers.signIn()"
        >
            {{ signingIn ? t('dailySigningIn') : t('dailySignIn') }}
        </button>

        <div class="cw-label" style="margin: 24px 0 9px">
            {{ t('layersFinds') }}
            ·
            {{
                t('layersFound', {
                    found: found.length,
                    total: board?.secrets?.length ?? 0,
                })
            }}
        </div>
        <div class="cw-stack" style="gap: 0; margin-bottom: 24px">
            <div
                v-for="secret in finds"
                :key="secret.key"
                class="cw-line-row"
                style="cursor: default"
            >
                <span
                    class="cw-layer-mark"
                    :class="{ 'cw-layer-mark-on': secret.found }"
                    aria-hidden="true"
                    >{{ secret.found ? '■' : '□' }}</span
                >
                <span style="flex: 1; min-width: 0">
                    {{
                        secret.found
                            ? pickText(secret.title)
                            : pickText(secret.hint)
                    }}
                </span>
                <span class="cw-label">+{{ secret.xp }}</span>
            </div>
        </div>

        <button
            type="button"
            class="cw-line-row"
            :disabled="!galleryOpen"
            :style="{ opacity: galleryOpen ? 1 : 0.62 }"
            @click="emit('gallery')"
        >
            <span style="flex: 1">
                {{ t('layersGalleryDoor') }}
                <span
                    v-if="!galleryOpen"
                    class="cw-label"
                    style="
                        display: block;
                        margin-top: 4px;
                        text-transform: none;
                        letter-spacing: 0;
                    "
                    >{{
                        t('layersGalleryLockedNote', { level: GALLERY_LEVEL })
                    }}</span
                >
            </span>
            <span class="cw-layer-tag">{{
                t('layersLayer', { n: '07' })
            }}</span>
            →
        </button>
    </div>
</template>
