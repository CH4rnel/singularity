<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Copy, ExternalLink, Magnet } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import PageHero from '@/components/web3/PageHero.vue';
import { useLocale } from '@/composables/useLocale';
import { trackerMessages } from '@/lib/trackerMessages';
import { formatBytes, ipfsHttpUrl } from '@/lib/wallet/ipfs';
import { isVideo } from '@/lib/wallet/tracker';
import type { TrackerContext, TrackerRelease } from '@/lib/wallet/tracker';
import { wallet } from '@/routes';
import { index as trackerIndex, show as trackerShow } from '@/routes/tracker';

/**
 * The tracker, on the open web.
 *
 * This page is the difference between a tracker and a feature inside an app: a
 * release has an address anybody can open, paste into a message and archive,
 * with no wallet, no account and no client. What it can do here is bounded and
 * says so — the magnet goes to whatever torrent client the reader already has,
 * and the only thing that plays in place is the sample the publisher pinned to
 * IPFS, because a browser tab cannot join a swarm.
 *
 * Every row carries the token it was minted as, and that is not decoration:
 * this index is a view over the chain, and the link is how a reader checks
 * that for themselves rather than trusting the page.
 */

const props = defineProps<{
    /** The list, when this is the index. Null on a release's own page. */
    results: {
        releases: TrackerRelease[];
        total: number;
        page: number;
        pages: number;
        filters: { q: string; category: string; sort: string; owner: string };
    } | null;
    context: TrackerContext;
    /** The one release, when this is a release's page. */
    release: TrackerRelease | null;
}>();

const { t } = useLocale(trackerMessages);

const query = ref(props.results?.filters.q ?? '');
const copied = ref(false);

let debounce: number | undefined;

const preview = computed(() => ipfsHttpUrl(props.release?.preview_url));
const cover = computed(() => ipfsHttpUrl(props.release?.cover_url));

/**
 * Filters live in the address.
 *
 * Which makes them shareable and makes the back button undo one — the same
 * reason the console keeps its filters there. `replace` so a search does not
 * leave one history entry per keystroke.
 */
const navigate = (changes: Record<string, string | number>): void => {
    const current = props.results?.filters ?? {
        q: '',
        category: '',
        sort: 'new',
        owner: '',
    };

    const params: Record<string, string> = {};

    for (const [key, value] of Object.entries({ ...current, ...changes })) {
        if (value !== '' && value !== null && value !== undefined) {
            params[key] = String(value);
        }
    }

    router.get(trackerIndex().url, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

watch(query, (value) => {
    window.clearTimeout(debounce);
    debounce = window.setTimeout(() => navigate({ q: value, page: 1 }), 350);
});

const copyMagnet = async (magnet: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(magnet);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 1500);
    } catch {
        // A browser that refuses the clipboard leaves the link on the page to
        // be selected by hand, which is where it was before this button.
    }
};

const day = (value: string | null): string =>
    value === null ? '—' : new Date(value).toLocaleDateString();

const short = (address: string): string =>
    `${address.slice(0, 10)}…${address.slice(-6)}`;
</script>

<template>
    <Head :title="release ? release.name : 'Cyberia Tracker'" />

    <div class="mx-auto max-w-4xl px-4 py-6 pb-16">
        <!-- --------------------------------------------------- one release --- -->
        <template v-if="release">
            <Link
                :href="trackerIndex().url"
                class="text-xs font-semibold tracking-widest text-brand-cyan uppercase"
            >
                ← {{ t('back') }}
            </Link>

            <PageHero
                class="mt-6"
                :eyebrow="t(`cat_${release.category}`)"
                :title="release.name"
                :description="release.description || undefined"
            />

            <div class="grid gap-6 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div>
                    <img
                        v-if="cover"
                        :src="cover"
                        alt=""
                        class="mb-6 w-full border border-border"
                    />

                    <!-- What a browser can honestly play: the pinned sample,
                         never the swarm. -->
                    <template v-if="preview">
                        <h2 class="mb-2 text-sm font-semibold">
                            {{ t('preview') }}
                        </h2>
                        <video
                            v-if="isVideo(release.preview_url ?? '')"
                            :src="preview"
                            controls
                            playsinline
                            class="w-full bg-black"
                        ></video>
                        <audio
                            v-else
                            :src="preview"
                            controls
                            class="w-full"
                        ></audio>
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ t('previewNote') }}
                        </p>
                    </template>

                    <h2 class="mt-8 mb-2 text-sm font-semibold">
                        {{ t('fileList') }}
                    </h2>
                    <ul
                        class="space-y-1 font-mono text-xs text-muted-foreground"
                    >
                        <li
                            v-for="file in release.files.slice(0, 100)"
                            :key="file.path"
                            class="flex justify-between gap-4"
                        >
                            <span class="truncate">{{ file.path }}</span>
                            <span class="shrink-0">{{
                                formatBytes(file.length)
                            }}</span>
                        </li>
                        <li v-if="release.files.length > 100">
                            {{
                                t('andMore', {
                                    count: release.files.length - 100,
                                })
                            }}
                        </li>
                        <li v-if="release.files.length === 0">
                            {{ formatBytes(release.size_bytes) }}
                        </li>
                    </ul>
                </div>

                <aside class="space-y-4">
                    <div class="grid grid-cols-2 gap-px border border-border">
                        <div class="bg-background p-3">
                            <p
                                class="text-[10px] text-muted-foreground uppercase"
                            >
                                {{ t('seeders') }}
                            </p>
                            <p class="text-2xl font-bold">
                                {{ release.seeders }}
                            </p>
                        </div>
                        <div class="bg-background p-3">
                            <p
                                class="text-[10px] text-muted-foreground uppercase"
                            >
                                {{ t('leechers') }}
                            </p>
                            <p class="text-2xl font-bold">
                                {{ release.leechers }}
                            </p>
                        </div>
                        <div class="bg-background p-3">
                            <p
                                class="text-[10px] text-muted-foreground uppercase"
                            >
                                {{ t('size') }}
                            </p>
                            <p class="text-sm">
                                {{ formatBytes(release.size_bytes) }}
                            </p>
                        </div>
                        <div class="bg-background p-3">
                            <p
                                class="text-[10px] text-muted-foreground uppercase"
                            >
                                {{ t('completed') }}
                            </p>
                            <p class="text-sm">{{ release.completed }}</p>
                        </div>
                    </div>

                    <a
                        :href="release.magnet"
                        class="flex w-full items-center justify-center gap-2 bg-brand-cyan px-4 py-3 text-sm font-semibold text-black"
                    >
                        <Magnet :size="14" aria-hidden="true" />
                        {{ t('openMagnet') }}
                    </a>
                    <button
                        type="button"
                        class="flex w-full items-center justify-center gap-2 border border-border px-4 py-2 text-xs"
                        @click="copyMagnet(release.magnet)"
                    >
                        <Copy :size="13" aria-hidden="true" />
                        {{ copied ? t('copied') : t('copyMagnet') }}
                    </button>

                    <div class="space-y-2 border border-border p-3 text-xs">
                        <p class="text-[10px] text-muted-foreground uppercase">
                            {{ t('token') }}
                        </p>
                        <p class="flex justify-between gap-3">
                            <span class="text-muted-foreground">{{
                                t('owner')
                            }}</span>
                            <span class="font-mono">{{
                                short(release.owner)
                            }}</span>
                        </p>
                        <p class="flex justify-between gap-3">
                            <span class="text-muted-foreground">{{
                                t('tokenId')
                            }}</span>
                            <span class="font-mono"
                                >#{{ release.token_id }}</span
                            >
                        </p>
                        <p class="break-all text-muted-foreground">
                            {{ t('infoHash') }}:
                            <span class="font-mono">{{
                                release.info_hash
                            }}</span>
                        </p>
                        <a
                            v-if="release.token_url"
                            :href="release.token_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 text-brand-cyan"
                        >
                            {{ t('onChain') }}
                            <ExternalLink :size="12" aria-hidden="true" />
                        </a>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{ t('published', { date: day(release.published_at) })
                        }}<br />
                        {{
                            release.last_announce_at
                                ? t('lastSeen', {
                                      date: day(release.last_announce_at),
                                  })
                                : t('neverAnnounced')
                        }}
                    </p>
                </aside>
            </div>

            <p class="mt-10 text-xs text-muted-foreground">
                {{ t('lawNote') }}
            </p>
        </template>

        <!-- -------------------------------------------------------- index --- -->
        <template v-else-if="results">
            <PageHero
                :eyebrow="t('eyebrow')"
                :title="t('title')"
                :description="t('intro')"
            >
                <template #actions>
                    <Link
                        :href="wallet().url"
                        class="bg-brand-cyan px-4 py-2 text-xs font-semibold text-black"
                    >
                        {{ t('publishCta') }}
                    </Link>
                </template>
            </PageHero>

            <input
                v-model="query"
                type="search"
                class="w-full border border-border bg-background px-3 py-2 text-sm"
                :placeholder="t('search')"
            />

            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                <button
                    type="button"
                    class="border border-border px-3 py-1"
                    :class="
                        results.filters.category === ''
                            ? 'border-brand-cyan text-brand-cyan'
                            : ''
                    "
                    @click="navigate({ category: '', page: 1 })"
                >
                    {{ t('catAll') }}
                </button>
                <button
                    v-for="entry in context.categories"
                    :key="entry"
                    type="button"
                    class="border border-border px-3 py-1"
                    :class="
                        results.filters.category === entry
                            ? 'border-brand-cyan text-brand-cyan'
                            : ''
                    "
                    @click="navigate({ category: entry, page: 1 })"
                >
                    {{ t(`cat_${entry}`) }}
                </button>
            </div>

            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                <button
                    v-for="entry in context.sorts"
                    :key="entry"
                    type="button"
                    class="px-2 py-1 text-muted-foreground"
                    :class="
                        results.filters.sort === entry ? 'text-brand-cyan' : ''
                    "
                    @click="navigate({ sort: entry, page: 1 })"
                >
                    {{ t(`sort_${entry}`) }}
                </button>
            </div>

            <p class="mt-4 text-xs text-muted-foreground">
                {{ t('count', { count: results.total }) }}
            </p>

            <p v-if="results.releases.length === 0" class="mt-8 text-sm">
                {{
                    results.filters.q === '' && results.filters.category === ''
                        ? t('empty')
                        : t('noMatch')
                }}
            </p>

            <ul v-else class="mt-4 divide-y divide-border border border-border">
                <li v-for="entry in results.releases" :key="entry.info_hash">
                    <Link
                        :href="trackerShow(entry.info_hash).url"
                        class="flex flex-wrap items-baseline justify-between gap-3 p-4 hover:bg-muted/40"
                    >
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-sm font-semibold"
                                >{{ entry.name }}</span
                            >
                            <span class="text-xs text-muted-foreground">
                                {{ t(`cat_${entry.category}`) }} ·
                                {{ formatBytes(entry.size_bytes) }} ·
                                {{ t('files', { count: entry.file_count }) }}
                            </span>
                        </span>
                        <span class="font-mono text-xs">
                            <span class="text-brand-cyan">{{
                                entry.seeders
                            }}</span>
                            /
                            <span class="text-muted-foreground">{{
                                entry.leechers
                            }}</span>
                        </span>
                    </Link>
                </li>
            </ul>

            <div
                v-if="results.pages > 1"
                class="mt-4 flex items-center gap-4 text-xs"
            >
                <button
                    type="button"
                    class="border border-border px-3 py-1 disabled:opacity-40"
                    :disabled="results.page <= 1"
                    @click="navigate({ page: results.page - 1 })"
                >
                    ←
                </button>
                <span class="text-muted-foreground"
                    >{{ results.page }} / {{ results.pages }}</span
                >
                <button
                    type="button"
                    class="border border-border px-3 py-1 disabled:opacity-40"
                    :disabled="results.page >= results.pages"
                    @click="navigate({ page: results.page + 1 })"
                >
                    →
                </button>
            </div>

            <section class="mt-12 border border-border p-4 text-sm">
                <h2 class="font-semibold">{{ t('howTitle') }}</h2>
                <p class="mt-2 text-xs text-muted-foreground">
                    {{ t('howBody') }}
                </p>
                <p class="mt-3 text-xs text-muted-foreground">
                    {{ t('publishNote') }}
                </p>

                <h3 class="mt-6 text-xs font-semibold uppercase">
                    {{ t('announce') }}
                </h3>
                <p class="mt-1 font-mono text-xs break-all">
                    {{ context.announce_url }}
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    {{ t('announceNote') }}
                </p>
            </section>

            <p class="mt-8 text-xs text-muted-foreground">{{ t('lawNote') }}</p>
        </template>
    </div>
</template>
