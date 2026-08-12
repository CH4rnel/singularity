<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Download as DownloadIcon,
    Globe,
    Laptop,
    Monitor,
    Puzzle,
    ShieldCheck,
    Smartphone,
    TabletSmartphone,
    Terminal,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import PageHero from '@/components/web3/PageHero.vue';
import { useLocale } from '@/composables/useLocale';
import { downloadMessages } from '@/lib/downloadMessages';
import { wallet } from '@/routes';
import type { DownloadBuild, DownloadCatalog } from '@/types';

const props = defineProps<{ catalog: DownloadCatalog }>();

// `locale` stays for date formatting below; switching languages belongs to the
// header menu, which every page shares.
const { locale, t } = useLocale(downloadMessages);

type PlatformCard = {
    id: string;
    icon: typeof Terminal;
    note: string;
    builds: DownloadBuild[];
};

/**
 * iOS is in the list without ever having a build: Apple's guideline 3.1.5(b)
 * keeps a wallet like this out of the store, and a platform that silently goes
 * missing from a download page reads as a bug rather than as an answer.
 */
const PLATFORMS: { id: string; icon: typeof Terminal; note: string }[] = [
    { id: 'windows', icon: Monitor, note: 'windowsNote' },
    { id: 'macos', icon: Laptop, note: 'macosNote' },
    { id: 'linux', icon: Terminal, note: 'linuxNote' },
    { id: 'android', icon: Smartphone, note: 'androidNote' },
    { id: 'ios', icon: TabletSmartphone, note: 'iosNote' },
    // The extension belongs to a browser rather than to an operating system,
    // so it is never the "for this device" card — it is offered alongside
    // whichever one is.
    { id: 'extension', icon: Puzzle, note: 'extensionNote' },
];

const cards = computed<PlatformCard[]>(() =>
    PLATFORMS.map((platform) => ({
        ...platform,
        builds: props.catalog.builds
            .filter((build) => build.platform === platform.id)
            .sort((a, b) => Number(b.primary) - Number(a.primary)),
    })),
);

/** Best guess at what the visitor is holding, so the right card comes first. */
function detectPlatform(): string {
    if (typeof navigator === 'undefined') {
        return '';
    }

    const ua = navigator.userAgent;

    if (/android/i.test(ua)) {
        return 'android';
    }

    if (/iphone|ipod|ipad/i.test(ua)) {
        return 'ios';
    }

    if (/macintosh|mac os x/i.test(ua)) {
        // An iPad has called itself a Macintosh since iPadOS 13; the touch
        // points are what still tell the two apart.
        return navigator.maxTouchPoints > 2 ? 'ios' : 'macos';
    }

    if (/windows/i.test(ua)) {
        return 'windows';
    }

    return /linux|x11|cros/i.test(ua) ? 'linux' : '';
}

const detected = detectPlatform();

const featured = computed(() =>
    cards.value.find((card) => card.id === detected && card.builds.length > 0),
);

const rest = computed(() =>
    cards.value.filter((card) => card.id !== featured.value?.id),
);

const publishedAt = computed(() => {
    if (!props.catalog.publishedAt) {
        return '';
    }

    return new Date(props.catalog.publishedAt).toLocaleDateString(
        locale.value === 'ru' ? 'ru-RU' : 'en-GB',
        { year: 'numeric', month: 'long', day: 'numeric' },
    );
});

function size(build: DownloadBuild): string {
    return build.size ? `${(build.size / 1024 / 1024).toFixed(1)} MB` : '';
}
</script>

<template>
    <Head :title="t('title')" />

    <div class="mx-auto max-w-4xl space-y-8 p-6">
        <PageHero
            :eyebrow="t('eyebrow')"
            :title="t('title')"
            :description="t('intro')"
        />

        <div class="flex flex-wrap items-center gap-3 text-sm">
            <a
                v-if="catalog.version"
                :href="catalog.releaseUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="rounded-full border border-brand-cyan/40 px-3 py-1 font-mono text-xs text-brand-cyan"
            >
                {{ t('version', { version: catalog.version }) }}
            </a>
            <span v-if="publishedAt" class="text-xs text-muted-foreground">
                {{ t('published', { date: publishedAt }) }}
            </span>
        </div>

        <p
            v-if="catalog.status === 'unknown'"
            class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm text-muted-foreground"
        >
            {{ t('unverified') }}
        </p>

        <p
            v-else-if="catalog.status === 'none'"
            class="rounded-lg border bg-card p-4 text-sm text-muted-foreground"
        >
            <span class="font-medium text-foreground">
                {{ t('unpublished') }}
            </span>
            {{ ' ' }}{{ t('unpublishedHint') }}
        </p>

        <!-- The platform the visitor is on, opened up and put first. -->
        <section v-if="featured" class="space-y-3">
            <h2
                class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
            >
                {{ t('forYou') }}
            </h2>
            <article
                class="rounded-xl border border-brand-cyan/40 bg-card p-5 shadow-xs"
            >
                <div class="flex items-center gap-3">
                    <component :is="featured.icon" class="size-5 text-brand-cyan" />
                    <h3 class="text-lg font-semibold">{{ t(featured.id) }}</h3>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <Button as-child size="lg">
                        <a :href="featured.builds[0].url">
                            <DownloadIcon class="mr-1 size-4" />
                            {{ t('download') }} · {{ t(featured.builds[0].id) }}
                        </a>
                    </Button>
                    <span class="font-mono text-xs text-muted-foreground">
                        {{ featured.builds[0].file }}
                        <template v-if="size(featured.builds[0])">
                            · {{ size(featured.builds[0]) }}
                        </template>
                    </span>
                </div>

                <div
                    v-if="featured.builds.length > 1"
                    class="mt-3 flex flex-wrap gap-4 text-sm"
                >
                    <a
                        v-for="build in featured.builds.slice(1)"
                        :key="build.id"
                        :href="build.url"
                        class="text-brand-cyan hover:underline"
                    >
                        {{ t(build.id) }}
                    </a>
                </div>

                <p class="mt-4 text-sm leading-6 text-muted-foreground">
                    {{ t(featured.note) }}
                </p>
            </article>
        </section>

        <section class="space-y-3">
            <h2
                v-if="featured"
                class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
            >
                {{ t('otherPlatforms') }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <article
                    v-for="card in rest"
                    :key="card.id"
                    class="flex flex-col rounded-xl border bg-card p-5 shadow-xs"
                >
                    <div class="flex items-center gap-3">
                        <component
                            :is="card.icon"
                            class="size-5 text-muted-foreground"
                        />
                        <h3 class="font-semibold">{{ t(card.id) }}</h3>
                    </div>

                    <div v-if="card.builds.length > 0" class="mt-4 space-y-2">
                        <a
                            v-for="build in card.builds"
                            :key="build.id"
                            :href="build.url"
                            class="flex items-baseline justify-between gap-3 rounded-lg border px-3 py-2 text-sm hover:border-brand-cyan/50 hover:bg-accent"
                        >
                            <span>{{ t(build.id) }}</span>
                            <span class="font-mono text-xs text-muted-foreground">
                                {{ size(build) || build.file.split('.').pop() }}
                            </span>
                        </a>
                    </div>

                    <!-- iOS never has a file; every other platform can be
                         between releases, and says so instead of 404ing. -->
                    <div v-else-if="card.id === 'ios'" class="mt-4">
                        <Button as-child variant="outline" size="sm">
                            <Link :href="wallet().url">
                                <Globe class="mr-1 size-4" />
                                {{ t('openInBrowser') }}
                            </Link>
                        </Button>
                    </div>

                    <div v-else class="mt-4 text-sm">
                        <p class="text-muted-foreground">
                            {{ t('notPublished') }}
                        </p>
                        <a
                            :href="catalog.repoUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-brand-cyan hover:underline"
                        >
                            {{ t('buildFromSource') }}
                        </a>
                    </div>

                    <p class="mt-4 text-sm leading-6 text-muted-foreground">
                        {{ t(card.note) }}
                    </p>
                </article>
            </div>
        </section>

        <section class="rounded-xl border bg-card p-5 text-sm">
            <div class="flex items-start gap-3">
                <ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-cyan" />
                <div class="space-y-2 leading-6 text-muted-foreground">
                    <p>{{ t('keysNote') }}</p>
                    <p>
                        {{ t('webAlternative') }}
                        <Link
                            :href="wallet().url"
                            class="text-brand-cyan hover:underline"
                        >
                            {{ t('openInBrowser') }}
                        </Link>
                    </p>
                </div>
            </div>
        </section>

        <div
            class="flex flex-wrap gap-4 border-t pt-4 text-xs text-muted-foreground"
        >
            <a
                v-if="catalog.checksumsUrl"
                :href="catalog.checksumsUrl"
                class="hover:text-foreground"
            >
                {{ t('checksums') }}
            </a>
            <a
                :href="catalog.releaseUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="hover:text-foreground"
            >
                {{ t('allReleases') }}
            </a>
            <a
                :href="catalog.repoUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="hover:text-foreground"
            >
                {{ t('sourceCode') }}
            </a>
        </div>
    </div>
</template>
