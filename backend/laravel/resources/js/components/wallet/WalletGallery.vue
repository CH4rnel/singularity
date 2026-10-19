<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { useWalletLayers } from '@/composables/useWalletLayers';
import { pinFile, pinJson } from '@/lib/wallet/ipfs';
import { isUnlocked } from '@/lib/wallet/layers';
import type { Requirement } from '@/lib/wallet/layers';
import {
    PIECES,
    drawLocked,
    drawPiece,
    randomSeed,
    renderPiecePng,
} from '@/lib/wallet/netart';
import type { Piece, PieceId } from '@/lib/wallet/netart';
import { buildMetadata } from '@/lib/wallet/nft';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Layer 07: the gallery. The bottom.
 *
 * Net art drawn by code, a picture per algorithm and an edition per seed, so
 * there is always another one. Pieces open with level and with finds; a closed
 * one shows static and says what opens it.
 *
 * Minting is the NFT screen's own flow and not a second one: the edition is
 * rendered at full size, pinned with its metadata, and the mint screen opens
 * on that URI — which is where the fee is quoted and the hold is signed.
 */

const props = defineProps<{
    wallet: MultiWallet;
    ipfs: { enabled: boolean; maxBytes: number };
}>();

const emit = defineEmits<{ back: []; mint: [uri: string] }>();

const { locale, t } = useLocale(walletMessages);

const layers = useWalletLayers(props.wallet, '07');
const { board, level, found, fresh } = layers;

const seeds = reactive<Record<string, number>>({});
const seedOf = (id: PieceId): number => seeds[id] ?? 1;

const selected = ref<Piece | null>(null);
const busy = ref(false);
const failure = ref<string | null>(null);

const hintOf = (key: string): string => {
    const hint = board.value?.secrets?.find(
        (secret) => secret.key === key,
    )?.hint;

    return hint ? (hint[locale.value] ?? hint.en ?? '') : '';
};

const lockReason = (requirement: Requirement): string =>
    'level' in requirement
        ? t('layersNeedsLevel', { level: requirement.level })
        : t('layersNeedsFind', { hint: hintOf(requirement.secret) });

const tiles = computed(() =>
    PIECES.map((piece) => ({
        piece,
        open: isUnlocked(piece.requires, level.value, found.value),
        reason: lockReason(piece.requires),
    })),
);

/**
 * Draw a canvas once per (piece, seed, size). Function refs run on every
 * render, and redrawing a megapixel of noise because a button changed colour
 * would be the slowest thing in the wallet.
 */
const paint = (
    element: unknown,
    piece: PieceId | null,
    seed: number,
    size: number,
): void => {
    if (!(element instanceof HTMLCanvasElement)) {
        return;
    }

    const key = `${piece ?? 'locked'}:${seed}:${size}`;

    if (element.dataset.drawn === key) {
        return;
    }

    const ctx = element.getContext('2d', { willReadFrequently: true });

    if (ctx === null) {
        return;
    }

    element.width = size;
    element.height = size;

    if (piece === null) {
        drawLocked(ctx, size);
    } else {
        drawPiece(ctx, piece, seed, size);
    }

    element.dataset.drawn = key;
};

const open = (piece: Piece): void => {
    selected.value = piece;
    failure.value = null;
};

const another = (): void => {
    if (selected.value) {
        seeds[selected.value.id] = randomSeed();
    }
};

const filename = (piece: Piece): string => `${piece.id}-${seedOf(piece.id)}`;

const savePng = async (): Promise<void> => {
    const piece = selected.value;

    if (piece === null) {
        return;
    }

    const png = await renderPiecePng(piece.id, seedOf(piece.id));
    const link = document.createElement('a');
    link.href = URL.createObjectURL(png);
    link.download = `${filename(piece)}.png`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 10_000);
};

const mint = async (): Promise<void> => {
    const piece = selected.value;

    if (piece === null || busy.value) {
        return;
    }

    busy.value = true;
    failure.value = null;

    try {
        const seed = seedOf(piece.id);
        const png = await renderPiecePng(piece.id, seed);
        const image = await pinFile(png, `${filename(piece)}.png`);
        const metadata = buildMetadata({
            name: `${piece.title} #${seed}`,
            description: t('layersNftDescription', {
                title: piece.title,
                seed,
            }),
            image: image.uri,
            externalUrl: 'https://cyberia.church/wallet',
            attributes: [
                { trait: 'Layer', value: '07' },
                { trait: 'Work', value: piece.title },
                { trait: 'Edition', value: String(seed) },
            ],
        });
        const pinned = await pinJson(metadata, `${filename(piece)}.json`);

        emit('mint', pinned.uri);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error);
    } finally {
        busy.value = false;
    }
};

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
        <button
            v-if="selected"
            type="button"
            class="cw-back"
            @click="selected = null"
        >
            ← {{ t('layersGalleryDoor') }}
        </button>
        <button v-else type="button" class="cw-back" @click="emit('back')">
            ← {{ t('layersExperimentsDoor') }}
        </button>

        <template v-if="selected">
            <div style="margin: 22px 0 14px">
                <div class="cw-layer-tag">
                    {{ t('layersEdition', { seed: seedOf(selected.id) }) }}
                </div>
                <h2 class="cw-title" style="font-size: 22px; margin-top: 6px">
                    {{ selected.title }}
                </h2>
            </div>

            <canvas
                :ref="
                    (el) => paint(el, selected!.id, seedOf(selected!.id), 1024)
                "
                class="cw-art-full"
                role="img"
                :aria-label="selected.title"
            />

            <div class="cw-art-actions">
                <button
                    type="button"
                    class="cw-btn cw-btn-secondary"
                    style="height: 44px"
                    @click="another"
                >
                    {{ t('layersAnother') }}
                </button>
                <button
                    type="button"
                    class="cw-btn cw-btn-secondary"
                    style="height: 44px"
                    @click="savePng"
                >
                    {{ t('layersSavePng') }}
                </button>
            </div>
            <button
                type="button"
                class="cw-btn cw-btn-primary"
                style="height: 48px; margin-top: 10px"
                :disabled="busy || !props.ipfs.enabled"
                @click="mint"
            >
                {{ busy ? t('layersMinting') : t('layersMint') }}
            </button>
            <span
                v-if="!props.ipfs.enabled"
                class="cw-hint"
                style="margin-top: 8px"
            >
                {{ t('layersMintOff') }}
            </span>
            <p
                v-if="failure"
                class="cw-hint"
                style="margin-top: 8px; color: var(--cw-bad-soft)"
            >
                {{ failure }}
            </p>
        </template>

        <template v-else>
            <div style="margin: 24px 0 18px">
                <div class="cw-layer-tag">
                    {{ t('layersLayer', { n: '07' }) }}
                </div>
                <h2 class="cw-title" style="font-size: 22px; margin-top: 6px">
                    {{ t('layersGalleryDoor') }}
                </h2>
                <p class="cw-prose" style="margin-top: 8px">
                    {{ t('layers07Body') }}
                </p>
            </div>

            <p v-if="freshNote" class="cw-note cw-layer-fresh">
                {{ freshNote }}
            </p>

            <div class="cw-art-grid">
                <button
                    v-for="tile in tiles"
                    :key="tile.piece.id"
                    type="button"
                    class="cw-art-tile"
                    :disabled="!tile.open"
                    @click="open(tile.piece)"
                >
                    <canvas
                        :ref="
                            (el) =>
                                paint(
                                    el,
                                    tile.open ? tile.piece.id : null,
                                    seedOf(tile.piece.id),
                                    240,
                                )
                        "
                        :class="{ 'cw-art-locked': !tile.open }"
                        aria-hidden="true"
                    />
                    <span class="cw-art-name">{{ tile.piece.title }}</span>
                    <span v-if="!tile.open" class="cw-art-why">{{
                        tile.reason
                    }}</span>
                </button>
            </div>
        </template>
    </div>
</template>
