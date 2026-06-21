<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AtSign, Lock, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Actor = {
    id: string | null;
    type: string | null;
    username: string | null;
    name: string | null;
    summary: string | null;
    url: string | null;
    icon: string | null;
    image: string | null;
    inbox: string | null;
    outbox: string | null;
    followers: string | null;
    following: string | null;
    manuallyApprovesFollowers: boolean | null;
    published: string | null;
    publicKeyPem: string | null;
    webfinger: { subject: string | null; aliases: string[] } | null;
    restricted: boolean;
};

type Post = {
    id: string | null;
    url: string | null;
    published: string | null;
    content: string | null;
    images: string[];
    sensitive: boolean;
};

const props = defineProps<{
    handle: string;
    actor: Actor | null;
    raw: Record<string, unknown> | null;
    posts: Post[];
    error: string | null;
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'Fediverse', href: '/fediverse' }],
    }),
});

const query = ref(props.handle ?? '');
const loading = ref(false);

const lookup = () => {
    const handle = query.value.trim();

    if (handle === '') {
        return;
    }

    router.get(
        '/fediverse',
        { handle },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
};

const host = (url: string | null): string | null => {
    if (!url) {
        return null;
    }

    try {
        return new URL(url).host;
    } catch {
        return null;
    }
};

const fullHandle = computed(() => {
    if (!props.actor?.username) {
        return null;
    }

    const instance = host(props.actor.id ?? props.actor.url);

    return instance
        ? `@${props.actor.username}@${instance}`
        : `@${props.actor.username}`;
});

const endpoints = computed(() =>
    [
        { label: 'Profile', href: props.actor?.url },
        { label: 'Followers', href: props.actor?.followers },
        { label: 'Following', href: props.actor?.following },
        { label: 'Inbox', href: props.actor?.inbox },
        { label: 'Outbox', href: props.actor?.outbox },
    ].filter((e): e is { label: string; href: string } => Boolean(e.href)),
);

const rawJson = computed(() =>
    props.raw ? JSON.stringify(props.raw, null, 2) : '',
);
</script>

<template>
    <Head title="Fediverse" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold">Fediverse lookup</h1>
            <p class="max-w-2xl text-sm text-muted-foreground">
                Resolve any ActivityPub actor by its handle or profile URL via
                WebFinger. The bridge between a Cyberia wallet and a Fediverse
                identity starts here.
            </p>
        </header>

        <form
            class="flex w-full max-w-2xl items-center gap-2"
            @submit.prevent="lookup"
        >
            <div class="relative flex-1">
                <AtSign
                    class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="query"
                    type="text"
                    spellcheck="false"
                    autocapitalize="none"
                    placeholder="gargron@mastodon.social"
                    class="w-full rounded-lg border border-sidebar-border/70 bg-background py-2 pr-3 pl-9 text-sm outline-none focus:border-sidebar-border dark:border-sidebar-border"
                />
            </div>
            <button
                type="submit"
                :disabled="loading"
                class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
            >
                <Search class="h-4 w-4" />
                {{ loading ? 'Resolving…' : 'Look up' }}
            </button>
        </form>

        <div
            v-if="error"
            class="max-w-2xl rounded-lg border border-red-500/30 bg-red-500/5 px-4 py-3 text-sm text-red-600 dark:text-red-400"
        >
            {{ error }}
        </div>

        <article
            v-if="actor"
            class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <div
                class="h-32 w-full bg-gradient-to-r from-indigo-500/20 to-purple-500/20"
            >
                <img
                    v-if="actor.image"
                    :src="actor.image"
                    alt=""
                    class="h-full w-full object-cover"
                />
            </div>

            <div class="p-6">
                <div
                    v-if="actor.restricted"
                    class="mb-4 flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/5 px-4 py-3 text-sm text-amber-700 dark:text-amber-400"
                >
                    <Lock class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        This instance runs in <strong>authorized-fetch</strong>
                        (secure) mode and only serves the full profile to
                        HTTP-signed requests. Showing what WebFinger discovered.
                    </span>
                </div>

                <div class="-mt-16 flex items-end gap-4">
                    <img
                        v-if="actor.icon"
                        :src="actor.icon"
                        alt=""
                        class="h-24 w-24 rounded-xl border-4 border-background bg-muted object-cover"
                    />
                    <div
                        v-else
                        class="flex h-24 w-24 items-center justify-center rounded-xl border-4 border-background bg-muted"
                    >
                        <AtSign class="h-8 w-8 text-muted-foreground" />
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-semibold">
                        {{ actor.name }}
                    </h2>
                    <span
                        v-if="actor.type"
                        class="rounded bg-indigo-500/10 px-2 py-0.5 text-xs font-medium text-indigo-500"
                    >
                        {{ actor.type }}
                    </span>
                    <span
                        v-if="actor.manuallyApprovesFollowers"
                        class="inline-flex items-center gap-1 rounded bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-600"
                    >
                        <Lock class="h-3 w-3" /> manually approves
                    </span>
                </div>

                <a
                    v-if="fullHandle"
                    :href="actor.url ?? actor.id ?? undefined"
                    target="_blank"
                    rel="noopener"
                    class="font-mono text-sm text-muted-foreground hover:underline"
                >
                    {{ fullHandle }}
                </a>

                <p
                    v-if="actor.summary"
                    class="mt-3 max-w-2xl text-sm whitespace-pre-line text-foreground/90"
                >
                    {{ actor.summary }}
                </p>

                <div v-if="endpoints.length" class="mt-5 flex flex-wrap gap-2">
                    <a
                        v-for="e in endpoints"
                        :key="e.label"
                        :href="e.href"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md border border-sidebar-border/70 px-3 py-1 text-xs hover:bg-muted dark:border-sidebar-border"
                    >
                        {{ e.label }}
                    </a>
                </div>

                <dl class="mt-5 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div
                        v-if="actor.published"
                        class="flex justify-between gap-4"
                    >
                        <dt class="text-muted-foreground">Joined</dt>
                        <dd class="font-mono text-xs">{{ actor.published }}</dd>
                    </div>
                    <div
                        v-if="actor.webfinger?.subject"
                        class="flex justify-between gap-4"
                    >
                        <dt class="text-muted-foreground">WebFinger</dt>
                        <dd class="truncate font-mono text-xs">
                            {{ actor.webfinger.subject }}
                        </dd>
                    </div>
                </dl>

                <details v-if="actor.publicKeyPem" class="mt-5">
                    <summary
                        class="cursor-pointer text-sm font-medium text-muted-foreground"
                    >
                        Public key
                    </summary>
                    <pre
                        class="mt-2 overflow-x-auto rounded-lg bg-muted p-3 font-mono text-xs"
                        >{{ actor.publicKeyPem }}</pre
                    >
                </details>

                <details v-if="rawJson" class="mt-3">
                    <summary
                        class="cursor-pointer text-sm font-medium text-muted-foreground"
                    >
                        Raw ActivityStreams object
                    </summary>
                    <pre
                        class="mt-2 max-h-96 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs"
                        >{{ rawJson }}</pre
                    >
                </details>
            </div>
        </article>

        <div
            v-else-if="!error"
            class="flex max-w-2xl flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center text-sm text-muted-foreground dark:border-sidebar-border"
        >
            <AtSign class="h-8 w-8" />
            <p>Search a handle to inspect its ActivityPub actor.</p>
        </div>

        <section v-if="actor && posts.length" class="max-w-2xl space-y-3">
            <h3 class="text-sm font-semibold text-muted-foreground">
                Recent posts
            </h3>
            <article
                v-for="post in posts"
                :key="post.id ?? post.url ?? ''"
                class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <p
                    v-if="post.content"
                    class="text-sm whitespace-pre-line text-foreground/90"
                >
                    {{ post.content }}
                </p>
                <p v-else class="text-sm text-muted-foreground italic">
                    (no text)
                </p>

                <div
                    v-if="post.images.length"
                    class="mt-3 grid gap-2"
                    :class="
                        post.images.length > 1 ? 'grid-cols-2' : 'grid-cols-1'
                    "
                >
                    <img
                        v-for="(img, i) in post.images"
                        :key="i"
                        :src="img"
                        alt=""
                        loading="lazy"
                        class="max-h-72 w-full rounded-lg object-cover"
                        :class="{ 'blur-md': post.sensitive }"
                    />
                </div>

                <a
                    v-if="post.url"
                    :href="post.url"
                    target="_blank"
                    rel="noopener"
                    class="mt-3 inline-block text-xs text-muted-foreground hover:underline"
                >
                    {{ post.published ?? 'open post' }} →
                </a>
            </article>
        </section>
    </div>
</template>
