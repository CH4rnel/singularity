<script setup lang="ts">
import { ExternalLink } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { useWalletLayers } from '@/composables/useWalletLayers';
import {
    HOMEPAGE_MAX_BYTES,
    HOMEPAGE_TEMPLATES,
    SUBDOMAIN_PATTERN,
    buildHomepage,
    suggestSubdomain,
} from '@/lib/wallet/homepages';
import type { HomepageTemplate } from '@/lib/wallet/homepages';
import { formatBytes } from '@/lib/wallet/ipfs';
import {
    entryLinks,
    fetchUnderground,
    publishHomepage,
    tokensBy,
} from '@/lib/wallet/underground';
import type {
    UndergroundDirectory,
    UndergroundEntry,
} from '@/lib/wallet/underground';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Layer 09: the workshop.
 *
 * Where somebody who launched a token gives it a homepage. Neocities, more or
 * less, for tokens: pick a template, change any of it, look at it, publish.
 * The page goes up on the token's own subdomain, joins the ring and is pinned
 * to IPFS by the launchpad's existing upload — signed with this wallet's key,
 * which the server checks against the creator it read off the chain.
 *
 * Reached from the underground (layer 08) and, for the practical-minded, from
 * the launchpad's own token screen: a creator should not have to find seven
 * secrets to build their homepage.
 */

const props = defineProps<{
    wallet: MultiWallet;
    /** A token to open straight away, from the launchpad or layer 08. */
    preset: string | null;
}>();

const emit = defineEmits<{ back: []; launchpad: [] }>();

const { locale, t } = useLocale(walletMessages);

const layers = useWalletLayers(props.wallet, '09');
const { fresh } = layers;

const directory = ref<UndergroundDirectory | null>(null);
const failure = ref<string | null>(null);
const loading = ref(true);

const selected = ref<UndergroundEntry | null>(null);
const template = ref<HomepageTemplate>('homestead');
const subdomain = ref('');
const html = ref('');
/** Typed into the code by hand: a subdomain change must not throw it away. */
const edited = ref(false);
const preview = ref('');
const busy = ref(false);
const published = ref<string | null>(null);
const publishError = ref<string | null>(null);

const myAddresses = computed(() =>
    props.wallet.accounts.value
        .filter(
            (account) => account.family === 'evm' && account.capabilities.send,
        )
        .map((account) => account.address.toLowerCase()),
);

const mine = computed(() =>
    directory.value === null
        ? []
        : tokensBy(directory.value, myAddresses.value),
);

const load = async (): Promise<void> => {
    loading.value = true;
    failure.value = null;

    try {
        directory.value = await fetchUnderground();
    } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error);
    } finally {
        loading.value = false;
    }
};

const regenerate = (): void => {
    const entry = selected.value;

    if (entry === null || directory.value === null) {
        return;
    }

    html.value = buildHomepage(template.value, {
        name: entry.name ?? entry.symbol ?? '',
        symbol: entry.symbol ?? '',
        description: entry.description ?? '',
        subdomain: subdomain.value || 'yourname',
        address: entry.address,
        undergroundUrl: directory.value.underground_url,
        buyUrl: entry.buy_url,
        links: entryLinks(entry),
    });
    edited.value = false;
};

const open = (entry: UndergroundEntry): void => {
    selected.value = entry;
    subdomain.value =
        entry.site_subdomain ?? suggestSubdomain(entry.symbol ?? '');
    published.value = null;
    publishError.value = null;
    regenerate();
};

onMounted(async () => {
    await load();

    const wanted = props.preset?.toLowerCase();
    const entry = mine.value.find((candidate) => candidate.address === wanted);

    if (entry) {
        open(entry);
    }
});

const chooseTemplate = (next: HomepageTemplate): void => {
    template.value = next;
    regenerate();
};

watch(subdomain, () => {
    if (!edited.value) {
        regenerate();
    }
});

/* The preview reloads a whole document, so it follows the code at a walk. */
let previewTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    html,
    (next) => {
        if (previewTimer !== null) {
            clearTimeout(previewTimer);
        }

        previewTimer = setTimeout(() => (preview.value = next), 450);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (previewTimer !== null) {
        clearTimeout(previewTimer);
    }
});

const onCode = (event: Event): void => {
    html.value = (event.target as HTMLTextAreaElement).value;
    edited.value = true;
};

const loadFile = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (file) {
        html.value = await file.text();
        edited.value = true;
    }
};

const bytes = computed(() => new Blob([html.value]).size);
const tooBig = computed(() => bytes.value > HOMEPAGE_MAX_BYTES);
const subdomainOk = computed(() => SUBDOMAIN_PATTERN.test(subdomain.value));

const publish = async (): Promise<void> => {
    const entry = selected.value;

    if (
        entry === null ||
        busy.value ||
        tooBig.value ||
        !subdomainOk.value ||
        html.value.trim() === ''
    ) {
        return;
    }

    busy.value = true;
    published.value = null;
    publishError.value = null;

    try {
        const token = await publishHomepage({
            token: entry.address,
            chainId: entry.chain_id,
            html: html.value,
            subdomain: subdomain.value,
            sign: (message) => props.wallet.signMessage('cyberia', message),
        });

        published.value = token.site_url;
        await load();
        selected.value =
            mine.value.find(
                (candidate) => candidate.address === entry.address,
            ) ?? entry;
    } catch (error) {
        publishError.value =
            error instanceof Error ? error.message : String(error);
    } finally {
        busy.value = false;
    }
};

const TEMPLATE_LABEL: Record<HomepageTemplate, string> = {
    homestead: 'hpHomestead',
    wired: 'hpWired',
    wires: 'hpWires',
    protocol7: 'hpProtocol7',
    jodi: 'hpJodi',
    shrine: 'hpShrine',
};

const ticker = (entry: UndergroundEntry): string =>
    `$${(entry.symbol ?? '').toUpperCase()}`;

const host = (url: string | null): string =>
    (url ?? '').replace(/^https?:\/\//, '').replace(/\/$/, '');

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
            ← {{ t('ugWorkshop') }}
        </button>
        <button v-else type="button" class="cw-back" @click="emit('back')">
            ← {{ t('ugTitle') }}
        </button>

        <template v-if="!selected">
            <div style="margin: 24px 0 18px">
                <div class="cw-layer-tag">
                    {{ t('layersLayer', { n: '09' }) }}
                </div>
                <h2 class="cw-title" style="font-size: 22px; margin-top: 6px">
                    {{ t('ugWorkshop') }}
                </h2>
                <p class="cw-prose" style="margin-top: 8px">
                    {{ t('wsBody') }}
                </p>
            </div>

            <p v-if="freshNote" class="cw-note cw-layer-fresh">
                {{ freshNote }}
            </p>

            <p v-if="failure" class="cw-hint" style="color: var(--cw-bad-soft)">
                {{ failure }}
            </p>

            <template v-if="!loading && mine.length === 0">
                <p class="cw-prose">{{ t('wsNone') }}</p>
                <button
                    type="button"
                    class="cw-btn cw-btn-primary"
                    style="height: 48px; margin-top: 14px"
                    @click="emit('launchpad')"
                >
                    {{ t('wsLaunch') }}
                </button>
            </template>

            <div class="cw-stack" style="gap: 0">
                <button
                    v-for="entry in mine"
                    :key="`${entry.chain_id}:${entry.address}`"
                    type="button"
                    class="cw-line-row"
                    @click="open(entry)"
                >
                    <img
                        :src="entry.cover_url"
                        alt=""
                        class="cw-ws-thumb"
                        width="64"
                        height="48"
                    />
                    <span style="flex: 1; min-width: 0">
                        <span class="cw-data" style="display: block">
                            {{ ticker(entry) }} · {{ entry.name }}
                        </span>
                        <span
                            class="cw-label"
                            style="
                                display: block;
                                margin-top: 4px;
                                text-transform: none;
                                letter-spacing: 0;
                            "
                        >
                            {{
                                entry.site_url
                                    ? t('wsHasHome', {
                                          host: host(entry.site_url),
                                      })
                                    : t('wsNoHome')
                            }}
                        </span>
                    </span>
                    →
                </button>
            </div>
        </template>

        <template v-else>
            <div style="margin: 22px 0 16px">
                <div class="cw-layer-tag">{{ ticker(selected) }}</div>
                <h2 class="cw-title" style="font-size: 22px; margin-top: 6px">
                    {{ selected.name }}
                </h2>
            </div>

            <div class="cw-label" style="margin-bottom: 9px">
                {{ t('wsTemplate') }}
            </div>
            <div class="cw-seg cw-ws-templates">
                <button
                    v-for="option in HOMEPAGE_TEMPLATES"
                    :key="option"
                    type="button"
                    class="cw-seg-item"
                    :aria-pressed="template === option"
                    @click="chooseTemplate(option)"
                >
                    {{ t(TEMPLATE_LABEL[option]) }}
                </button>
            </div>

            <div class="cw-label" style="margin: 18px 0 9px">
                {{ t('wsSubdomain') }}
            </div>
            <label class="cw-ws-host">
                <input
                    v-model.trim="subdomain"
                    class="cw-input"
                    type="text"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    maxlength="63"
                    :aria-label="t('wsSubdomain')"
                />
                <span>.cyberia.church</span>
            </label>
            <span
                v-if="subdomain !== '' && !subdomainOk"
                class="cw-hint"
                style="margin-top: 6px; color: var(--cw-bad-soft)"
                >{{ t('wsBadSubdomain') }}</span
            >

            <div class="cw-label" style="margin: 18px 0 9px">
                {{ t('wsPreview') }}
            </div>
            <iframe
                class="cw-ws-preview"
                :srcdoc="preview"
                sandbox="allow-scripts"
                :title="t('wsPreview')"
            />

            <details class="cw-ws-code">
                <summary class="cw-label">{{ t('wsCode') }}</summary>
                <span class="cw-hint" style="margin: 8px 0">{{
                    t('wsCodeHint')
                }}</span>
                <textarea
                    class="cw-textarea"
                    :value="html"
                    spellcheck="false"
                    rows="14"
                    @input="onCode"
                />
                <label
                    class="cw-ghost"
                    style="cursor: pointer; margin-top: 8px"
                >
                    {{ t('wsUpload') }}
                    <input
                        type="file"
                        accept=".html,.htm,text/html"
                        style="display: none"
                        @change="loadFile"
                    />
                </label>
            </details>

            <span
                class="cw-hint"
                style="margin-top: 10px"
                :style="{ color: tooBig ? 'var(--cw-bad-soft)' : undefined }"
                >{{
                    tooBig
                        ? t('wsTooBig')
                        : t('wsSize', { size: formatBytes(bytes) })
                }}</span
            >

            <button
                type="button"
                class="cw-btn cw-btn-primary"
                style="height: 48px; margin-top: 14px"
                :disabled="busy || tooBig || !subdomainOk || html.trim() === ''"
                @click="publish"
            >
                {{ busy ? t('wsPublishing') : t('wsPublish') }}
            </button>
            <span class="cw-hint" style="margin-top: 8px">{{
                t('wsSignNote')
            }}</span>

            <a
                v-if="published"
                class="cw-note cw-layer-fresh"
                style="margin-top: 14px; text-decoration: none"
                :href="published"
                target="_blank"
                rel="noopener"
            >
                {{ t('wsPublished', { url: host(published) }) }}
                <ExternalLink :size="14" aria-hidden="true" />
            </a>
            <p
                v-if="publishError"
                class="cw-hint"
                style="margin-top: 10px; color: var(--cw-bad-soft)"
            >
                {{ publishError }}
            </p>
        </template>
    </div>
</template>
