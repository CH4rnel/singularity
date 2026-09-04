<script setup lang="ts">
import { ExternalLink, FolderOpen, Files } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import GasSponsor from '@/components/wallet/GasSponsor.vue';
import HoldButton from '@/components/wallet/HoldButton.vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { mintableChains } from '@/lib/nftChains';
import { formatUnits, walletChains } from '@/lib/wallet';
import { parseMagnet, parseTorrent } from '@/lib/wallet/bencode';
import type { TorrentFileEntry } from '@/lib/wallet/bencode';
import { formatBytes, pinFile, pinJson } from '@/lib/wallet/ipfs';
import { mintTxUrl, quoteMint, waitForMintedToken } from '@/lib/wallet/nft';
import type { MintQuote } from '@/lib/wallet/nft';
import { canSeed, torrentBridge } from '@/lib/wallet/torrent';
import { buildReleaseMetadata, registerRelease } from '@/lib/wallet/tracker';
import type { TrackerRelease } from '@/lib/wallet/tracker';
import { walletMessages } from '@/lib/walletMessages';
import { show as trackerShow } from '@/routes/tracker';

/**
 * Publishing a release: making the torrent, minting it, listing it.
 *
 * The order is the design. The torrent exists first — it has an info hash and
 * somebody is seeding it — then a token is minted that names it, and only then
 * does the index hear about it. The index is told a chain and a token id and
 * nothing else; it reads the rest off the chain itself, which is why there is
 * no field on this screen that a server has to take anybody's word for.
 *
 * Where the torrent comes from depends on the machine, and the screen says so
 * rather than offering three buttons of which two do nothing. The desktop app
 * can *make* one: it hashes the files, writes this site's tracker into it and
 * starts seeding, which is the only way a release published here has anybody
 * on it. A browser can only describe a torrent that already exists somewhere —
 * a `.torrent` file it reads locally, or a magnet — and it says plainly that
 * somebody has to be seeding it, because a release with no seeder is a page
 * about a file nobody can get.
 */

const props = defineProps<{
    wallet: MultiWallet;
    ipfs: { enabled: boolean; maxBytes: number };
}>();

const emit = defineEmits<{ back: []; published: [release: TrackerRelease] }>();

const { t } = useLocale(walletMessages);

const bridge = torrentBridge();

type Stage = 'source' | 'details' | 'confirm' | 'listing' | 'done';

const stage = ref<Stage>('source');
const busy = ref(false);
const failure = ref<string | null>(null);

/** The torrent this release is about, however it was arrived at. */
const infoHash = ref('');
const files = ref<TorrentFileEntry[]>([]);
const length = ref(0);
/** True when this machine is actually seeding it, which is not always. */
const seeding = ref(false);

const name = ref('');
const description = ref('');
const category = ref('video');
const cover = ref<File | null>(null);
const preview = ref<File | null>(null);

const magnetInput = ref('');
const announceUrl = ref('');

const tokenUri = ref('');
const quote = ref<MintQuote | null>(null);
const hash = ref<string | null>(null);
const tokenId = ref<string | null>(null);
const released = ref<TrackerRelease | null>(null);

const CATEGORIES = ['video', 'audio', 'image', 'software', 'text', 'other'];

const target = computed(() => mintableChains()[0] ?? null);

const chainId = computed(() => {
    const evmChainId = target.value?.chain.chainId;

    return (
        walletChains().find((chain) => chain.chainId === evmChainId)?.id ?? null
    );
});

const account = computed(() =>
    chainId.value === null
        ? null
        : (props.wallet.accounts.value.find(
              (entry) => entry.chain === chainId.value,
          ) ?? null),
);

const symbol = computed(() => target.value?.chain.nativeCurrency.symbol ?? '');

/** A watched address can hold a release and can never publish one. */
const canSign = computed(() => account.value?.capabilities.send ?? false);

const gasBalance = computed(() =>
    chainId.value === null
        ? null
        : (props.wallet.balances.value[chainId.value]?.value ?? null),
);

const feeText = computed(() =>
    quote.value === null
        ? '—'
        : `${formatUnits(quote.value.fee, 18, 6)} ${symbol.value}`,
);

const magnetValid = computed(() => parseMagnet(magnetInput.value) !== null);

/* ------------------------------------------------------------- source -- */

/**
 * Make a torrent out of files on this machine and start sharing them.
 *
 * The page asks for a picker and never for a path: the shell opens a native
 * dialog, hashes what was chosen, writes this site's tracker into the torrent
 * and seeds it. What comes back already exists in a swarm — which is what
 * makes the release that follows worth publishing.
 */
const createTorrent = async (mode: 'files' | 'folder'): Promise<void> => {
    if (!bridge?.seed) {
        return;
    }

    busy.value = true;
    failure.value = null;

    try {
        const summary = await bridge.seed(mode);

        // Cancelling a file picker is not a failure and says nothing.
        if (summary === null) {
            return;
        }

        infoHash.value = summary.infoHash;
        files.value = summary.files.map((file) => ({
            path: file.path,
            length: file.length,
        }));
        length.value = summary.length;
        name.value = summary.name;
        seeding.value = true;
        stage.value = 'details';
    } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error);
    } finally {
        busy.value = false;
    }
};

/**
 * A `.torrent` somebody already has, read here in the browser.
 *
 * The info hash is computed over the file's own bytes rather than taken from
 * anywhere: it is SHA-1 of the info dictionary exactly as the client that made
 * the torrent wrote it, and a hash derived any other way names a swarm nobody
 * is in.
 */
const readTorrentFile = async (event: Event): Promise<void> => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    if (!file) {
        return;
    }

    busy.value = true;
    failure.value = null;

    try {
        const parsed = await parseTorrent(
            new Uint8Array(await file.arrayBuffer()),
        );

        if (parsed.v2Only) {
            throw new Error(t('publishV2Only'));
        }

        infoHash.value = parsed.infoHash;
        files.value = parsed.files;
        length.value = parsed.length;
        name.value = parsed.name;
        seeding.value = false;
        stage.value = 'details';
    } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error);
    } finally {
        busy.value = false;
    }
};

/**
 * A magnet, which is an identity and a name and nothing else.
 *
 * No file list and no size, and neither is invented: whatever this page
 * guessed would be on chain forever, and the swarm tells the truth to anybody
 * who opens the link.
 */
const takeMagnet = (): void => {
    const parsed = parseMagnet(magnetInput.value);

    if (parsed === null) {
        return;
    }

    infoHash.value = parsed.infoHash;
    files.value = [];
    length.value = 0;
    name.value = parsed.name || parsed.infoHash.slice(0, 12);
    seeding.value = false;
    stage.value = 'details';
};

const pick = (event: Event, into: 'cover' | 'preview'): void => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    if (file && file.size > props.ipfs.maxBytes) {
        failure.value = t('ipfsTooLarge', {
            size: formatBytes(props.ipfs.maxBytes),
        });

        return;
    }

    failure.value = null;

    if (into === 'cover') {
        cover.value = file;
    } else {
        preview.value = file;
    }
};

/* -------------------------------------------------------------- minting -- */

/**
 * Pin the document, then price the mint against it.
 *
 * Nothing is signed here. It ends on a screen saying exactly what will be
 * written on chain and what it will cost, which is the last moment where
 * changing your mind is free.
 */
const prepare = async (): Promise<void> => {
    if (!target.value || !account.value) {
        return;
    }

    busy.value = true;
    failure.value = null;

    try {
        const pinnedCover = cover.value ? await pinFile(cover.value) : null;
        const pinnedPreview = preview.value
            ? await pinFile(preview.value)
            : null;

        const metadata = await pinJson(
            buildReleaseMetadata({
                name: name.value,
                description: description.value,
                infoHash: infoHash.value,
                files: files.value,
                length: length.value,
                category: category.value,
                cover: pinnedCover?.uri ?? null,
                preview: pinnedPreview?.uri ?? null,
                announceUrl: announceUrl.value,
                siteUrl: `${window.location.origin}${trackerShow(infoHash.value).url}`,
            }),
            'release.json',
        );

        tokenUri.value = metadata.uri;
        quote.value = await quoteMint(
            tokenUri.value,
            account.value.address,
            target.value,
        );
        stage.value = 'confirm';
    } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error);
    } finally {
        busy.value = false;
    }
};

/**
 * List the token that was just minted.
 *
 * Kept as its own step, and retryable on its own, because the two halves fail
 * differently and only one of them costs money. Once the mint is mined the
 * release exists whether or not this index has heard of it — so a failure here
 * is "not listed yet", never "publication failed", and the retry re-reads the
 * same token rather than minting a second one.
 */
const list = async (): Promise<void> => {
    if (!target.value || tokenId.value === null) {
        return;
    }

    busy.value = true;
    failure.value = null;

    try {
        released.value = await registerRelease(
            target.value.chain.chainId,
            tokenId.value,
        );
        stage.value = 'done';
        emit('published', released.value);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error);
    } finally {
        busy.value = false;
    }
};

const publish = async (): Promise<void> => {
    if (!chainId.value || quote.value === null || !target.value) {
        return;
    }

    busy.value = true;
    failure.value = null;

    try {
        hash.value = await props.wallet.mintNft(
            chainId.value,
            tokenUri.value,
            quote.value,
        );
        stage.value = 'listing';

        // The id is in the mint's own log — a contract's return value is
        // invisible to a browser, and reading `nextId()` would race everybody
        // else minting into the same shared collection.
        tokenId.value = await waitForMintedToken(hash.value, target.value);

        await list();
    } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error);

        if (stage.value === 'confirm') {
            stage.value = 'confirm';
        }
    } finally {
        busy.value = false;
    }
};

onMounted(async () => {
    if (!bridge) {
        return;
    }

    try {
        announceUrl.value = (await bridge.info()).announceUrl ?? '';
    } catch {
        // An engine that cannot describe itself still seeds; the tracker then
        // comes from the torrent the shell writes, which is the same URL.
    }
});
</script>

<template>
    <div class="cw-stack">
        <button type="button" class="cw-back" @click="emit('back')">
            ← {{ t('trackerTitle') }}
        </button>

        <!-- ------------------------------------------------------- done --- -->
        <template v-if="stage === 'done' && released">
            <h2 class="cw-title" style="margin: 22px 0 8px">
                {{ t('publishDoneTitle') }}
            </h2>
            <p class="cw-prose">{{ t('publishDoneBody') }}</p>

            <div class="cw-card" style="margin-top: 16px; padding: 14px 16px">
                <div class="cw-kv">
                    <span class="cw-kv-key">{{ t('trackerTokenId') }}</span>
                    <span class="cw-kv-val">#{{ released.token_id }}</span>
                </div>
                <div class="cw-kv">
                    <span class="cw-kv-key">{{ t('trackerInfoHash') }}</span>
                    <span
                        class="cw-kv-val"
                        style="overflow-wrap: anywhere; text-align: right"
                    >
                        {{ released.info_hash }}
                    </span>
                </div>
            </div>

            <p
                v-if="!seeding"
                class="cw-note cw-note-warn"
                style="margin-top: 14px"
            >
                <span>{{ t('publishNeedsSeeder') }}</span>
            </p>

            <a
                class="cw-btn cw-btn-primary"
                style="height: 48px; margin-top: 18px; text-decoration: none"
                :href="trackerShow(released.info_hash).url"
            >
                {{ t('publishOpenRelease') }}
                <ExternalLink :size="14" aria-hidden="true" />
            </a>
            <button
                type="button"
                class="cw-ghost"
                style="margin-top: 10px"
                @click="emit('back')"
            >
                {{ t('publishBackToIndex') }}
            </button>
        </template>

        <!-- ------------------------------------ minted, not yet listed --- -->
        <template v-else-if="stage === 'listing'">
            <h2 class="cw-title" style="margin: 22px 0 8px">
                {{ t('publishListingTitle') }}
            </h2>
            <p class="cw-prose">
                {{
                    tokenId === null
                        ? t('publishWaitingMint')
                        : t('publishListingBody')
                }}
            </p>

            <a
                v-if="hash && target"
                class="cw-btn cw-btn-secondary"
                style="margin-top: 16px; text-decoration: none"
                :href="mintTxUrl(target, hash)"
                target="_blank"
                rel="noopener noreferrer"
            >
                {{ t('mintExplorer') }}
                <ExternalLink :size="13" aria-hidden="true" />
            </a>

            <p
                v-if="failure"
                class="cw-note cw-note-bad"
                style="margin-top: 16px"
            >
                <span>{{ failure }}</span>
            </p>

            <!-- The mint is paid for and mined; only the listing is missing,
                 and retrying it re-reads the same token. -->
            <button
                v-if="failure && tokenId !== null"
                type="button"
                class="cw-btn cw-btn-primary"
                style="height: 48px; margin-top: 14px"
                :disabled="busy"
                @click="list"
            >
                {{ t('publishRetryListing') }}
            </button>
        </template>

        <!-- ---------------------------------------------------- confirm --- -->
        <template v-else-if="stage === 'confirm'">
            <h2 class="cw-title" style="margin: 22px 0 8px">
                {{ t('publishConfirmTitle') }}
            </h2>
            <p class="cw-prose">{{ t('publishConfirmBody') }}</p>

            <div class="cw-card" style="margin-top: 16px; padding: 14px 16px">
                <div class="cw-kv">
                    <span class="cw-kv-key">{{ t('publishName') }}</span>
                    <span class="cw-kv-val">{{ name }}</span>
                </div>
                <div class="cw-kv">
                    <span class="cw-kv-key">{{ t('trackerInfoHash') }}</span>
                    <span
                        class="cw-kv-val"
                        style="overflow-wrap: anywhere; text-align: right"
                    >
                        {{ infoHash }}
                    </span>
                </div>
                <div class="cw-kv">
                    <span class="cw-kv-key">{{ t('publishPointsAt') }}</span>
                    <span
                        class="cw-kv-val"
                        style="overflow-wrap: anywhere; text-align: right"
                    >
                        {{ tokenUri }}
                    </span>
                </div>
                <div class="cw-kv">
                    <span class="cw-kv-key">{{ t('mintFee') }}</span>
                    <span class="cw-kv-val">{{ feeText }}</span>
                </div>
            </div>

            <GasSponsor
                v-if="chainId !== null"
                :chain="chainId"
                :address="account?.address"
                :fee="quote?.fee ?? null"
                :gas-balance="gasBalance"
                :symbol="symbol"
                :decimals="18"
                @funded="props.wallet.refreshBalances()"
            />

            <p
                v-if="failure"
                class="cw-note cw-note-bad"
                style="margin-top: 16px"
            >
                <span>{{ failure }}</span>
            </p>

            <p
                v-if="!canSign"
                class="cw-note cw-note-warn"
                style="margin-top: 14px"
            >
                <span>{{ t('publishWatchOnly') }}</span>
            </p>

            <div style="margin-top: 18px">
                <HoldButton
                    :label="t('publishHold')"
                    :disabled="busy || !canSign"
                    @complete="publish"
                />
            </div>

            <button
                type="button"
                class="cw-ghost"
                style="margin-top: 10px"
                @click="stage = 'details'"
            >
                {{ t('back') }}
            </button>
        </template>

        <!-- ---------------------------------------------------- details --- -->
        <template v-else-if="stage === 'details'">
            <h2 class="cw-title" style="margin: 22px 0 8px">
                {{ t('publishDetailsTitle') }}
            </h2>
            <p class="cw-prose">{{ t('publishDetailsBody') }}</p>

            <div class="cw-card" style="margin-top: 16px; padding: 12px 14px">
                <div class="cw-kv">
                    <span class="cw-kv-key">{{ t('trackerInfoHash') }}</span>
                    <span
                        class="cw-kv-val"
                        style="overflow-wrap: anywhere; text-align: right"
                    >
                        {{ infoHash }}
                    </span>
                </div>
                <div class="cw-kv">
                    <span class="cw-kv-key">{{ t('publishSeeding') }}</span>
                    <span class="cw-kv-val">
                        {{
                            seeding
                                ? t('publishSeedingHere')
                                : t('publishSeedingElsewhere')
                        }}
                    </span>
                </div>
                <div v-if="files.length > 0" class="cw-kv">
                    <span class="cw-kv-key">{{
                        t('trackerFiles', { count: files.length })
                    }}</span>
                    <span class="cw-kv-val">{{ formatBytes(length) }}</span>
                </div>
            </div>

            <label class="cw-label" style="display: block; margin: 18px 0 6px">
                {{ t('publishName') }}
            </label>
            <input
                v-model="name"
                class="cw-input"
                type="text"
                maxlength="200"
            />

            <label class="cw-label" style="display: block; margin: 14px 0 6px">
                {{ t('publishDescription') }}
            </label>
            <textarea
                v-model="description"
                class="cw-textarea"
                rows="4"
                maxlength="4000"
                :placeholder="t('publishDescriptionHint')"
            ></textarea>

            <label class="cw-label" style="display: block; margin: 14px 0 6px">
                {{ t('publishCategory') }}
            </label>
            <div class="cw-seg" style="flex-wrap: wrap">
                <button
                    v-for="entry in CATEGORIES"
                    :key="entry"
                    type="button"
                    class="cw-seg-item"
                    :aria-pressed="category === entry"
                    @click="category = entry"
                >
                    {{ t(`trackerCat_${entry}`) }}
                </button>
            </div>

            <template v-if="ipfs.enabled">
                <label
                    class="cw-label"
                    style="display: block; margin: 18px 0 6px"
                >
                    {{ t('publishCover') }}
                </label>
                <input
                    class="cw-input"
                    type="file"
                    accept="image/*"
                    @change="pick($event, 'cover')"
                />

                <label
                    class="cw-label"
                    style="display: block; margin: 14px 0 6px"
                >
                    {{ t('publishPreview') }}
                </label>
                <input
                    class="cw-input"
                    type="file"
                    accept="audio/*,video/*"
                    @change="pick($event, 'preview')"
                />
                <p class="cw-data" style="margin-top: 6px">
                    {{
                        t('publishPreviewHint', {
                            size: formatBytes(ipfs.maxBytes),
                        })
                    }}
                </p>
            </template>
            <p v-else class="cw-note cw-note-warn" style="margin-top: 16px">
                <span>{{ t('ipfsOff') }}</span>
            </p>

            <p
                v-if="failure"
                class="cw-note cw-note-bad"
                style="margin-top: 16px"
            >
                <span>{{ failure }}</span>
            </p>

            <button
                type="button"
                class="cw-btn cw-btn-primary"
                style="height: 48px; margin-top: 18px"
                :disabled="busy || name.trim() === '' || !account"
                @click="prepare"
            >
                {{ busy ? t('publishPinning') : t('publishContinue') }}
            </button>
            <button
                type="button"
                class="cw-ghost"
                style="margin-top: 10px"
                @click="stage = 'source'"
            >
                {{ t('back') }}
            </button>
        </template>

        <!-- ----------------------------------------------------- source --- -->
        <template v-else>
            <h2 class="cw-title" style="margin: 22px 0 8px">
                {{ t('publishTitle') }}
            </h2>
            <p class="cw-prose">{{ t('publishBody') }}</p>

            <template v-if="canSeed(bridge)">
                <p class="cw-label" style="margin: 20px 0 8px">
                    {{ t('publishFromThisMachine') }}
                </p>
                <div style="display: flex; gap: 8px">
                    <button
                        type="button"
                        class="cw-btn cw-btn-primary"
                        style="flex: 1; height: 48px"
                        :disabled="busy"
                        @click="createTorrent('files')"
                    >
                        <Files :size="14" aria-hidden="true" />
                        {{ t('publishPickFiles') }}
                    </button>
                    <button
                        type="button"
                        class="cw-btn cw-btn-primary"
                        style="flex: 1; height: 48px"
                        :disabled="busy"
                        @click="createTorrent('folder')"
                    >
                        <FolderOpen :size="14" aria-hidden="true" />
                        {{ t('publishPickFolder') }}
                    </button>
                </div>
                <p class="cw-note cw-note-warn" style="margin-top: 12px">
                    <span>{{ t('publishSeedWarning') }}</span>
                </p>
            </template>
            <p v-else class="cw-note" style="margin-top: 18px">
                <span>{{ t('publishNoEngine') }}</span>
            </p>

            <p class="cw-label" style="margin: 22px 0 8px">
                {{ t('publishFromExisting') }}
            </p>
            <p class="cw-prose">{{ t('publishFromExistingBody') }}</p>

            <input
                class="cw-input"
                style="margin-top: 12px"
                type="file"
                accept=".torrent,application/x-bittorrent"
                @change="readTorrentFile"
            />

            <input
                v-model="magnetInput"
                class="cw-input"
                style="margin-top: 12px"
                type="text"
                spellcheck="false"
                placeholder="magnet:?xt=urn:btih:…"
                :aria-invalid="magnetInput.trim() !== '' && !magnetValid"
                @keyup.enter="takeMagnet"
            />
            <button
                type="button"
                class="cw-btn cw-btn-secondary"
                style="margin-top: 10px"
                :disabled="!magnetValid"
                @click="takeMagnet"
            >
                {{ t('publishUseMagnet') }}
            </button>

            <p
                v-if="failure"
                class="cw-note cw-note-bad"
                style="margin-top: 16px"
            >
                <span>{{ failure }}</span>
            </p>

            <p class="cw-note" style="margin-top: 18px">
                <span>{{ t('trackerLawNote') }}</span>
            </p>
        </template>
    </div>
</template>
