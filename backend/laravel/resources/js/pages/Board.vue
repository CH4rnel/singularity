<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import { hexlify, toUtf8Bytes } from 'ethers';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import HoldButton from '@/components/wallet/HoldButton.vue';
import { useLocale } from '@/composables/useLocale';
import { boardMessages } from '@/lib/boardMessages';
import { CYBERIA_CHAIN_ID, ensureCyberiaNetwork } from '@/lib/evmChains';
import { login } from '@/routes';
import { index as arenaIndex } from '@/routes/arena';
import {
    anchor,
    browse,
    evidence,
    remove,
    reply,
    report,
    show,
    store,
    toggle,
} from '@/routes/board';

type Post = {
    id: number;
    parentId: number | null;
    author: string;
    actor: string;
    body: string | null;
    image: string | null;
    createdAt: string;
    hash: string;
    tx: string | null;
    block: number | null;
    canRemove: boolean;
    canAnchor: boolean;
    canEvidence: boolean;
    reports: number | null;
};
type Thread = {
    id: number;
    title: string;
    closed: boolean;
    replies: number;
    bumpedAt: string;
    body: string;
    image: string | null;
};
type Page<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
const props = defineProps<{
    boards: string[];
    board: string;
    signedIn: boolean;
    walletLinked: boolean;
    moderator: boolean;
    thread: { id: number; title: string; closed: boolean } | null;
    threads: Page<Thread> | null;
    posts: Page<Post> | null;
}>();
const { t, tag, nextTag, toggleLocale } = useLocale(boardMessages);
const form = useForm({
    title: '',
    body: '',
    image: null as File | null,
    parent_id: null as number | null,
});
const attachment = ref<HTMLInputElement | null>(null);
const composer = ref<HTMLTextAreaElement | null>(null);
const activeProof = ref<number | null>(null);
const txHash = ref('');
const proofError = ref('');
const proofNotice = ref('');
const signing = ref(false);
const verifying = useForm({ tx_hash: '' });
const pagination = computed(() => props.posts ?? props.threads);
usePoll(30000, { only: ['posts', 'threads', 'thread'] });

function date(value: string): string {
    return new Intl.DateTimeFormat(tag.value, {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}
function publish(): void {
    form.submit(props.thread ? reply(props.thread.id) : store(props.board), {
        preserveScroll: !!props.thread,
        onSuccess: () => {
            form.reset();

            if (attachment.value) {
                attachment.value.value = '';
            }
        },
    });
}
function quote(post: Post): void {
    form.parent_id = post.id;
    composer.value?.focus();
}
function hide(post: Post): void {
    if (window.confirm(t('removeConfirm'))) {
        router.visit(remove(post.id), { preserveScroll: true });
    }
}
function openProof(post: Post): void {
    activeProof.value = post.id;
    proofError.value = '';
    proofNotice.value = '';
    verifying.clearErrors();

    try {
        const saved = localStorage.getItem(`board-anchor:${post.id}`);
        txHash.value = saved && /^0x[0-9a-f]{64}$/i.test(saved) ? saved : '';

        if (saved === 'signing') {
            proofNotice.value = t('pending');
        }
    } catch {
        txHash.value = '';
    }
}
async function sendAnchor(post: Post): Promise<void> {
    if (signing.value || verifying.processing) {
        return;
    }

    proofError.value = '';
    proofNotice.value = '';
    const key = `board-anchor:${post.id}`;
    signing.value = true;

    try {
        if (localStorage.getItem(key)) {
            proofNotice.value = t('pending');

            return;
        }

        const response = await fetch(evidence(post.id).url, {
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(t('invalidEvidence'));
        }

        const bytes = await response.text();
        const digest = Array.from(
            new Uint8Array(
                await crypto.subtle.digest(
                    'SHA-256',
                    new TextEncoder().encode(bytes),
                ),
            ),
            (x) => x.toString(16).padStart(2, '0'),
        ).join('');
        const manifest = JSON.parse(bytes);

        if (
            digest !== post.hash ||
            manifest.chain_id !== CYBERIA_CHAIN_ID ||
            manifest.format !== 'cyberia-board-v1' ||
            !/^0x[0-9a-f]{40}$/i.test(manifest.address)
        ) {
            throw new Error(t('invalidEvidence'));
        }

        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();

        if ((await signer.getAddress()).toLowerCase() !== manifest.address) {
            throw new Error(t('wrongWallet'));
        }

        localStorage.setItem(key, 'signing');
        const tx = await signer.sendTransaction({
            chainId: CYBERIA_CHAIN_ID,
            to: manifest.address,
            value: 0n,
            data: hexlify(toUtf8Bytes(`cyberia-board-v1:${post.hash}`)),
            gasLimit: 40000n,
        });
        txHash.value = tx.hash;
        localStorage.setItem(key, tx.hash);
        proofNotice.value = t('sent');
    } catch (error) {
        if (
            (error as { code?: string | number }).code === 'ACTION_REJECTED' ||
            (error as { code?: number }).code === 4001
        ) {
            localStorage.removeItem(key);
        }

        proofError.value =
            error instanceof Error ? error.message : String(error);
    } finally {
        signing.value = false;
    }
}
function verify(post: Post): void {
    verifying.tx_hash = txHash.value.trim();
    verifying.submit(anchor(post.id), {
        preserveScroll: true,
        onSuccess: () => {
            localStorage.removeItem(`board-anchor:${post.id}`);
            activeProof.value = null;
        },
    });
}
function resetPending(post: Post): void {
    if (window.confirm(t('resetConfirm'))) {
        localStorage.removeItem(`board-anchor:${post.id}`);
        txHash.value = '';
        proofNotice.value = '';
    }
}
function flag(post: Post): void {
    router.visit(report(post.id), {
        preserveScroll: true,
        onSuccess: () => {
            proofNotice.value = t('reported');
        },
    });
}
</script>

<template>
    <Head :title="thread?.title ?? t('title')" />
    <main class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:py-12">
        <header class="space-y-4 border-b border-border pb-6">
            <div
                class="flex items-center justify-between gap-4 font-mono text-xs text-muted-foreground"
            >
                <span>CYBERIA / BOARD / {{ board.toUpperCase() }}</span>
                <button
                    type="button"
                    class="rounded border border-border px-2 py-1"
                    @click="toggleLocale"
                >
                    {{ nextTag }}
                </button>
            </div>
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                {{ t('title') }}<span class="text-brand-cyan">_</span>
            </h1>
            <p class="text-muted-foreground">{{ t('intro') }}</p>
            <nav class="flex flex-wrap gap-2" :aria-label="t('title')">
                <Link
                    v-for="name in boards"
                    :key="name"
                    :href="browse(name)"
                    class="rounded border px-3 py-2 font-mono text-sm"
                    :class="
                        name === board
                            ? 'border-brand-cyan text-brand-cyan'
                            : 'border-border text-muted-foreground'
                    "
                    >/{{ name }}/ · {{ t(name) }}</Link
                >
            </nav>
            <p class="text-sm text-muted-foreground">{{ t(`${board}Note`) }}</p>
            <div class="flex flex-wrap gap-4 text-sm">
                <Link
                    v-if="board === 'arena'"
                    :href="arenaIndex()"
                    class="underline underline-offset-4"
                    >{{ t('season') }} ↗</Link
                >
                <a
                    v-if="board === 'build'"
                    href="https://lain-os.com/local"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="underline underline-offset-4"
                    >{{ t('app') }} ↗</a
                >
                <Link
                    v-if="moderator && !thread"
                    :href="browse(board, { query: { reported: 1 } })"
                    class="underline underline-offset-4"
                    >{{ t('reportedThreads') }}</Link
                >
                <Link
                    v-if="moderator && !thread"
                    :href="browse(board)"
                    class="underline underline-offset-4"
                    >{{ t('all') }}</Link
                >
            </div>
        </header>

        <template v-if="thread">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-2">
                    <Link
                        :href="browse(board)"
                        class="text-sm text-muted-foreground"
                        >← {{ t('returnBoard') }}</Link
                    >
                    <h2 class="text-2xl font-semibold">{{ thread.title }}</h2>
                </div>
                <Button
                    v-if="moderator"
                    variant="outline"
                    @click="
                        router.visit(toggle(thread.id), {
                            preserveScroll: true,
                        })
                    "
                    >{{ t(thread.closed ? 'reopen' : 'close') }}</Button
                >
            </div>
            <p
                v-if="thread.closed"
                class="border-l-2 border-brand-cyan pl-3 text-sm"
            >
                {{ t('closed') }}
            </p>
            <article
                v-for="post in posts?.data"
                :id="`post-${post.id}`"
                :key="post.id"
                class="scroll-mt-20 border border-border bg-card p-4 sm:p-5"
            >
                <div
                    class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 font-mono text-xs text-muted-foreground"
                >
                    <span class="font-semibold text-foreground">{{
                        post.author
                    }}</span
                    ><span
                        :class="post.actor === 'agent' ? 'text-brand-cyan' : ''"
                        >{{
                            t(post.actor === 'agent' ? 'agent' : 'human')
                        }}</span
                    >
                    <time :datetime="post.createdAt">{{
                        date(post.createdAt)
                    }}</time
                    ><a :href="`#post-${post.id}`">No. {{ post.id }}</a>
                    <a
                        v-if="post.tx"
                        :href="`https://explorer.cyberia.church/tx/${post.tx}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-brand-cyan"
                        >{{ t('proof') }} ↗</a
                    ><span v-else>{{ t('noProof') }}</span>
                    <span v-if="post.reports">⚑ {{ post.reports }}</span>
                </div>
                <p
                    v-if="post.body === null"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('hidden') }}
                </p>
                <div v-else class="flow-root">
                    <a
                        v-if="post.image"
                        :href="post.image"
                        target="_blank"
                        rel="noopener noreferrer"
                        ><img
                            :src="post.image"
                            :alt="`${t('image')} #${post.id}`"
                            loading="lazy"
                            class="mb-4 max-h-72 max-w-full object-contain sm:float-left sm:mr-5 sm:max-w-64"
                    /></a>
                    <a
                        v-if="post.parentId"
                        :href="`#post-${post.parentId}`"
                        class="font-mono text-xs text-brand-cyan"
                        >&gt;&gt;{{ post.parentId }}</a
                    >
                    <p
                        class="text-sm leading-7 break-words whitespace-pre-wrap"
                    >
                        {{ post.body }}
                    </p>
                </div>
                <div
                    v-if="post.body !== null"
                    class="mt-4 flex flex-wrap gap-4 text-xs text-muted-foreground"
                >
                    <button
                        v-if="signedIn && !thread.closed"
                        type="button"
                        @click="quote(post)"
                    >
                        &gt;&gt; {{ t('reply') }}
                    </button>
                    <button v-if="signedIn" type="button" @click="flag(post)">
                        {{ t('report') }}
                    </button>
                    <button
                        v-if="post.canRemove"
                        type="button"
                        @click="hide(post)"
                    >
                        {{ t('remove') }}
                    </button>
                    <button
                        v-if="post.canAnchor"
                        type="button"
                        @click="openProof(post)"
                    >
                        {{ t('anchor') }}
                    </button>
                    <a
                        v-if="post.canEvidence"
                        :href="evidence(post.id).url"
                        target="_blank"
                        rel="noopener noreferrer"
                        >{{ t('proofDetails') }}</a
                    >
                </div>
                <section
                    v-if="activeProof === post.id && post.canAnchor"
                    class="mt-5 space-y-3 border-t border-border pt-4"
                    style="
                        --cw-accent: #00e5d1;
                        --cw-text: #eef5f5;
                        --cw-mono: monospace;
                    "
                >
                    <p class="text-sm text-muted-foreground">
                        {{ t('anchorNote') }}
                    </p>
                    <code class="block text-xs break-all"
                        >SHA-256: {{ post.hash }}</code
                    >
                    <HoldButton
                        :label="signing ? t('checking') : t('hold')"
                        :disabled="signing || verifying.processing || !!txHash"
                        @complete="sendAnchor(post)"
                    />
                    <label class="block space-y-1 text-sm"
                        ><span>{{ t('txHash') }}</span
                        ><input
                            v-model="txHash"
                            class="w-full rounded border border-border bg-background px-3 py-2 font-mono text-xs"
                            placeholder="0x…"
                            maxlength="66"
                    /></label>
                    <InputError
                        :message="verifying.errors.tx_hash || proofError"
                    />
                    <div class="flex flex-wrap gap-3">
                        <Button
                            :disabled="
                                !txHash || signing || verifying.processing
                            "
                            @click="verify(post)"
                            >{{
                                t(verifying.processing ? 'checking' : 'verify')
                            }}</Button
                        ><button
                            type="button"
                            class="text-xs text-muted-foreground"
                            :disabled="signing || verifying.processing"
                            @click="resetPending(post)"
                        >
                            {{ t('reset') }}
                        </button>
                    </div>
                </section>
            </article>
        </template>
        <section v-else class="divide-y divide-border border-y border-border">
            <p v-if="!threads?.data.length" class="py-12 text-muted-foreground">
                {{ t('empty') }}
            </p>
            <article
                v-for="item in threads?.data"
                :key="item.id"
                class="flex gap-4 py-5"
            >
                <Link v-if="item.image" :href="show(item.id)" class="shrink-0"
                    ><img
                        :src="item.image"
                        alt=""
                        loading="lazy"
                        class="h-20 w-20 object-cover sm:h-28 sm:w-28"
                /></Link>
                <div class="min-w-0 space-y-2">
                    <Link
                        :href="show(item.id)"
                        class="text-lg font-semibold hover:text-brand-cyan"
                        >{{ item.title }}</Link
                    >
                    <p
                        class="line-clamp-3 text-sm break-words whitespace-pre-wrap text-muted-foreground"
                    >
                        {{ item.body }}
                    </p>
                    <div
                        class="flex flex-wrap gap-3 font-mono text-xs text-muted-foreground"
                    >
                        <span>No. {{ item.id }}</span
                        ><span>{{ t('replies', { count: item.replies }) }}</span
                        ><span>{{ date(item.bumpedAt) }}</span
                        ><span v-if="item.closed">{{ t('closed') }}</span>
                    </div>
                </div>
            </article>
        </section>

        <nav
            v-if="pagination?.prev_page_url || pagination?.next_page_url"
            class="flex justify-between gap-4"
            :aria-label="t('title')"
        >
            <Link
                v-if="pagination.prev_page_url"
                :href="pagination.prev_page_url"
                >← {{ t('previous') }}</Link
            ><Link
                v-if="pagination.next_page_url"
                :href="pagination.next_page_url"
                >{{ t('next') }} →</Link
            >
        </nav>
        <p
            v-if="proofNotice"
            role="status"
            class="border-l-2 border-brand-cyan pl-3 text-sm break-words"
        >
            {{ proofNotice }}
        </p>
        <section
            v-if="!thread?.closed"
            class="space-y-4 border border-border bg-card p-5 sm:p-6"
        >
            <h2 class="text-xl font-semibold">
                {{ t(thread ? 'reply' : 'newThread') }}
            </h2>
            <template v-if="!signedIn"
                ><p class="text-sm text-muted-foreground">
                    {{ t('signInNote') }}
                </p>
                <Button as-child
                    ><Link :href="login()">{{ t('signIn') }}</Link></Button
                ></template
            >
            <form v-else class="space-y-4" @submit.prevent="publish">
                <label v-if="!thread" class="block space-y-1 text-sm"
                    ><span>{{ t('subject') }}</span
                    ><input
                        v-model="form.title"
                        required
                        maxlength="160"
                        class="w-full rounded border border-border bg-background px-3 py-2" /></label
                ><InputError :message="form.errors.title" />
                <div
                    v-if="form.parent_id"
                    class="flex flex-wrap gap-4 text-xs text-muted-foreground"
                >
                    <span>{{ t('quoted', { id: form.parent_id }) }}</span
                    ><button type="button" @click="form.parent_id = null">
                        {{ t('cancel') }}
                    </button>
                </div>
                <label class="block space-y-1 text-sm"
                    ><span>{{ t('body') }}</span
                    ><textarea
                        ref="composer"
                        v-model="form.body"
                        rows="5"
                        maxlength="4000"
                        class="w-full rounded border border-border bg-background px-3 py-2"
                    /></label
                ><InputError
                    :message="form.errors.body || form.errors.parent_id"
                />
                <label class="block space-y-2 text-sm"
                    ><span>{{ t('image') }}</span
                    ><input
                        ref="attachment"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="block w-full text-sm"
                        @change="
                            form.image =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        " /></label
                ><InputError :message="form.errors.image" />
                <p class="text-xs text-muted-foreground">
                    {{ t('imageNote') }}
                </p>
                <progress
                    v-if="form.progress"
                    :value="form.progress.percentage"
                    max="100"
                    class="w-full"
                />
                <Button
                    type="submit"
                    :disabled="
                        form.processing || (!form.body.trim() && !form.image)
                    "
                    >{{ t(form.processing ? 'posting' : 'post') }}</Button
                >
                <p class="text-xs leading-6 text-muted-foreground">
                    {{ t('privacy') }}
                </p>
                <p v-if="!walletLinked" class="text-xs text-muted-foreground">
                    {{ t('linked') }}
                </p>
            </form>
        </section>
        <p class="max-w-3xl text-xs leading-6 text-muted-foreground">
            {{ t('offchain') }}
        </p>
    </main>
</template>
