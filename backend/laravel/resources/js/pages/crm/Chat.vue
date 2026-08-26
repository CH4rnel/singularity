<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import Linked from '@/components/console/Linked.vue';
import Rule from '@/components/console/Rule.vue';
import { useConsoleBeat } from '@/composables/useConsolePulse';
import { useLocale } from '@/composables/useLocale';
import { age, num, plural, secondsSince, shortTime } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';
import chat from '@/routes/crm/chat';

/**
 * "Чат" — one room, and the file dump is the same room.
 *
 * Three operators, so there are no channels: what separates one conversation
 * from another is the object a line is attached to, not a room somebody had
 * to create first. A file cannot exist here without the message that brought
 * it, which is how it keeps an author and a reason; "Файлы" is this stream
 * read a second way.
 *
 * The one action on a line is to turn it into a task. A room is where work is
 * decided and the board is where it is remembered — a pinned message is one
 * nobody does.
 *
 * LainOS answers only when it is called by name, and every answer is stamped
 * with which of the two backends gave it and what it was allowed to see. When
 * neither can be reached the room says so with a hatched stripe instead of
 * producing a sentence nobody stands behind.
 */
type FileRow = {
    id: number;
    messageId: number;
    name: string;
    ext: string;
    kind: string;
    size: number;
    by: string;
    at: string | null;
};

type Message = {
    id: number;
    author: 'operator' | 'lainos';
    name: string;
    mine: boolean;
    body: string | null;
    at: string | null;
    files: FileRow[];
    contact: { id: number; name: string } | null;
    task: { id: number; title: string } | null;
    call: {
        state: string | null;
        note: string | null;
        attempts: Attempt[];
    } | null;
    answer: {
        backend?: string;
        model?: string | null;
        model_source?: string | null;
        provider?: string | null;
        ensemble?: string | null;
        overridden?: boolean | null;
        ms?: number;
        context?: {
            messages?: number;
            files?: string[];
            quoted?: string | null;
        };
    } | null;
};

/** One try at answering: who was asked, what came back, how long it took. */
type Attempt = {
    backend: string;
    outcome: string;
    ms: number;
    model: string | null;
};

type Provider = {
    kind: string;
    name: string;
    model: string;
    envKind: string;
    overridden: boolean;
};

type Person = {
    id: number | null;
    name: string;
    kind: 'operator' | 'lainos';
    you: boolean;
    seenAt?: string | null;
    backend?: string | null;
};

const props = defineProps<{
    messages: Message[];
    older: number | null;
    before: number | null;
    unreadFrom: number;
    /* The server's clock when this window was read; handed back on polling. */
    at: string;
    people: Person[];
    recentFiles: FileRow[];
    fileCount: number;
    lainos: {
        daemon: boolean;
        persona: boolean;
        backend: string | null;
        // Which model actually answers — a reading of the daemon, not a
        // setting of ours, so it has a third state: unreadable.
        provider: Provider | null;
        choices: { name: string; kind: string; desc: string }[];
        probe: string;
    };
    limits: {
        maxMb: number;
        maxFiles: number;
        maxChars: number;
        retentionDays: number;
        contextMessages: number;
    };
}>();

const page = usePage();
const { locale, t, tag } = useLocale(consoleMessages);

const list = ref<Message[]>([...props.messages]);
const people = ref<Person[]>([...props.people]);
const files = ref<FileRow[]>([...props.recentFiles]);
const fileCount = ref(props.fileCount);

/**
 * Where the unread line is drawn. Frozen at mount: it marks where this
 * person stopped reading, and a marker that moves while they read is a
 * marker that was never there.
 */
const unreadFrom = ref(props.unreadFrom);

const draft = ref('');
const attachments = ref<File[]>([]);
const sending = ref(false);
const dragging = ref(false);
const asking = ref<number | null>(null);
const side = ref<'room' | 'lainos'>('room');
const switching = ref<string | null>(null);
const switchNote = ref<string | null>(null);

const scroller = ref<HTMLElement | null>(null);
const input = ref<HTMLTextAreaElement | null>(null);

/*
 * The server's clock as of this browser's last read of the room. Handed back
 * on every poll to ask what changed since — our own clock rather than this
 * machine's, which may be minutes out and would silently drop edits.
 */
const seenAt = ref(props.at);

/* One poll at a time: a slow answer must not race the next one into the list. */
let refreshing = false;

const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const latestId = computed(() =>
    list.value.length === 0 ? 0 : list.value[list.value.length - 1].id,
);
const operators = computed(() =>
    people.value.filter((p) => p.kind !== 'lainos'),
);
const lastAt = computed(() =>
    list.value.length === 0 ? null : list.value[list.value.length - 1].at,
);

/** The room's own list is the record; props are the newest server view. */
watch(
    () => props.messages,
    (incoming) => {
        list.value = [...incoming];
        // A re-read of the page is a fresh look at the room: the poll asks
        // what changed since *this* moment, not since the tab was opened.
        seenAt.value = props.at;
    },
);
watch(
    () => props.people,
    (incoming) => {
        people.value = [...incoming];
    },
);
watch(
    () => props.recentFiles,
    (incoming) => {
        files.value = [...incoming];
        fileCount.value = props.fileCount;
    },
);

function ago(iso: string | null | undefined): string | null {
    const value = age(secondsSince(iso));

    return value
        ? `${value.value} ${plural(locale.value, value.count, t(value.unit))}`
        : null;
}

function size(bytes: number): string {
    if (bytes >= 1_048_576) {
        return `${num(bytes / 1_048_576, 1)} ${t('unit.mb')}`;
    }

    return `${num(Math.max(1, Math.round(bytes / 1024)))} ${t('unit.kb')}`;
}

function day(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const at = new Date(iso);
    const today = new Date();
    const yesterday = new Date(today.getTime() - 86_400_000);

    if (at.toDateString() === today.toDateString()) {
        return t('chat.today');
    }

    if (at.toDateString() === yesterday.toDateString()) {
        return t('chat.yesterday');
    }

    return at.toLocaleDateString(tag.value, {
        day: 'numeric',
        month: 'long',
    });
}

/**
 * The stream as it is drawn: day rules, the unread line, and messages that
 * drop their author when the same person keeps talking.
 */
const rows = computed(() => {
    const out: Array<
        | { kind: 'day'; key: string; label: string }
        | { kind: 'unread'; key: string; label: string }
        | { kind: 'msg'; key: string; message: Message; named: boolean }
    > = [];
    let previous: Message | null = null;
    let unreadDrawn = false;

    for (const message of list.value) {
        if (!previous || day(previous.at) !== day(message.at)) {
            out.push({
                kind: 'day',
                key: `d${message.id}`,
                label: day(message.at),
            });
            previous = null;
        }

        if (
            !unreadDrawn &&
            unreadFrom.value > 0 &&
            message.id > unreadFrom.value &&
            !message.mine
        ) {
            unreadDrawn = true;
            out.push({
                kind: 'unread',
                key: `u${message.id}`,
                label: t('chat.unread', {
                    time: shortTime(message.at, tag.value),
                }),
            });
            previous = null;
        }

        const gap =
            previous && previous.at && message.at
                ? Date.parse(message.at) - Date.parse(previous.at)
                : Infinity;

        out.push({
            kind: 'msg',
            key: `m${message.id}`,
            message,
            named:
                !previous ||
                previous.name !== message.name ||
                previous.author !== message.author ||
                gap > 600_000,
        });

        previous = message;
    }

    return out;
});

function csrf(): string | null {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match
        ? decodeURIComponent(match.split('=').slice(1).join('='))
        : null;
}

/** True when the reader is at the bottom, i.e. following the conversation. */
function atBottom(): boolean {
    const box = scroller.value;

    return box
        ? box.scrollHeight - box.scrollTop - box.clientHeight < 120
        : true;
}

async function toBottom(): Promise<void> {
    await nextTick();

    if (scroller.value) {
        scroller.value.scrollTop = scroller.value.scrollHeight;
    }
}

/** Start a visit at the first unread line; a fully read room starts at now. */
async function toFirstUnreadOrBottom(): Promise<void> {
    await nextTick();

    const marker =
        scroller.value?.querySelector<HTMLElement>('[data-chat-unread]');

    if (marker && scroller.value) {
        scroller.value.scrollTop = Math.max(0, marker.offsetTop - 12);

        return;
    }

    await toBottom();
}

/**
 * Catch the room up.
 *
 * Three things happen to a conversation and all three arrive here: lines are
 * said, lines change under the reader (an answer lands on the call that asked
 * for it, a line becomes a task) and lines are taken back. A poll that only
 * appended the first left the other two on screen until somebody reloaded.
 *
 * The scroll is the reason the room does this instead of re-rendering the
 * whole window: somebody reading yesterday's argument does not get dragged to
 * the bottom because a file landed, and somebody who *is* at the bottom
 * follows the conversation.
 */
async function refresh(): Promise<void> {
    if (document.hidden || props.before !== null || refreshing) {
        return;
    }

    refreshing = true;

    try {
        const first = list.value.length > 0 ? list.value[0].id : latestId.value;

        const response = await fetch(
            chat.since.url({
                query: {
                    after: latestId.value,
                    from: first,
                    held: list.value.length,
                    at: seenAt.value,
                },
            }),
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );

        if (!response.ok) {
            return;
        }

        const data = (await response.json()) as {
            messages: Message[];
            changed: Message[];
            present: number[] | null;
            at: string;
            people: Person[];
            fileCount: number;
        };

        seenAt.value = data.at;
        people.value = data.people;
        fileCount.value = data.fileCount;

        const follow = atBottom();
        let next = list.value;

        // Taken back: the server sends the ids that still exist in the window
        // this browser holds, and only when the two counts disagree.
        if (data.present !== null) {
            const alive = new Set(data.present);
            next = next.filter((message) => alive.has(message.id));
        }

        // Changed in place: same row, new state. Replaced rather than
        // re-ordered, because its position in the day is where it was said.
        if (data.changed.length > 0) {
            const edits = new Map(data.changed.map((m) => [m.id, m]));
            next = next.map((message) => edits.get(message.id) ?? message);
        }

        if (data.messages.length > 0) {
            next = [...next, ...data.messages];
        }

        if (next !== list.value) {
            list.value = next;
        }

        if (data.messages.length > 0 && follow) {
            void toBottom();
        }
    } catch {
        // A poll that failed is a poll; the console's heartbeat brings the
        // next one, and the top bar counts the ones that fail.
    } finally {
        refreshing = false;
    }
}

function send(): void {
    const body = draft.value.trim();

    if (sending.value || (body === '' && attachments.value.length === 0)) {
        return;
    }

    sending.value = true;

    router.post(
        chat.store.url(),
        { body, files: attachments.value },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            only: [
                'messages',
                'older',
                'at',
                'recentFiles',
                'fileCount',
                'people',
                'errors',
            ],
            onSuccess: () => {
                draft.value = '';
                attachments.value = [];
                resize();
                void toBottom();
                askIfCalled();
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}

/** The line just sent called LainOS, so run the call from here. */
function askIfCalled(): void {
    const call = [...list.value]
        .reverse()
        .find((message) => message.mine && message.call?.state === 'awaiting');

    if (call) {
        void ask(call.id);
    }
}

/**
 * Run one call. Deliberately a request of its own: an answer that silently
 * never arrives is worse than one an operator can press again.
 */
async function ask(id: number): Promise<void> {
    if (asking.value !== null) {
        return;
    }

    asking.value = id;

    try {
        await fetch(chat.answer.url(id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(csrf() ? { 'X-XSRF-TOKEN': csrf() as string } : {}),
            },
        });
    } catch {
        // The room reads the state off the message, not off this request.
    } finally {
        asking.value = null;
        // The answer and the call's new state are both rows on the server;
        // re-reading them is the only way this page learns what happened.
        router.reload({ only: ['messages', 'older', 'at', 'console'] });
    }
}

function toTask(message: Message): void {
    router.post(
        chat.task.url(message.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['messages', 'at'],
        },
    );
}

function remove(message: Message): void {
    if (!window.confirm(t('chat.deleteAsk'))) {
        return;
    }

    router.delete(chat.destroy.url(message.id), {
        preserveScroll: true,
        preserveState: true,
        only: ['messages', 'at', 'recentFiles', 'fileCount'],
    });
}

function pick(event: Event): void {
    const chosen = (event.target as HTMLInputElement).files;

    if (chosen) {
        add([...chosen]);
    }

    (event.target as HTMLInputElement).value = '';
}

function add(chosen: File[]): void {
    attachments.value = [...attachments.value, ...chosen].slice(
        0,
        props.limits.maxFiles,
    );
}

function drop(event: DragEvent): void {
    dragging.value = false;

    if (event.dataTransfer?.files?.length) {
        add([...event.dataTransfer.files]);
        input.value?.focus();
    }
}

function resize(): void {
    const box = input.value;

    if (box) {
        box.style.height = 'auto';
        box.style.height = `${Math.min(box.scrollHeight, 160)}px`;
    }
}

function keydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send();
    }
}

/** Which backend answered, in words rather than in a config value. */
function backendName(backend: string | null | undefined): string {
    return backend === 'daemon'
        ? t('chat.lainos.daemon')
        : backend === 'persona'
          ? t('chat.lainos.persona')
          : t('chat.lainos.off');
}

function stamp(message: Message): string {
    const answer = message.answer ?? {};
    const context = answer.context ?? {};
    let line = t('chat.lainos.context', {
        messages: context.messages ?? 0,
    });

    if ((context.files ?? []).length > 0) {
        line += t('chat.lainos.contextFiles', {
            files: (context.files ?? []).join(', '),
        });
    }

    if (context.quoted) {
        line += t('chat.lainos.contextRead', {
            name: String(context.quoted).split(':')[0],
        });
    }

    // Provenance or a reading, and the difference is marked: `turn` is the
    // model the daemon says produced this reply, `probe` is what it said it
    // was on a moment earlier. Unread is its own answer, better than a
    // plausible name nobody checked.
    const model = answer.model
        ? answer.model +
          (answer.model_source === 'probe'
              ? ` ${t('chat.lainos.byProbe')}`
              : '')
        : t('chat.lainos.modelUnknown');
    const took =
        answer.ms === undefined
            ? ''
            : ` · ${t('chat.lainos.took', { s: (answer.ms / 1000).toFixed(1) })}`;

    return `${t('chat.lainos.stamp', {
        backend: backendName(answer.backend),
        model,
    })}${took} · ${line}`;
}

/** Ask the daemon to answer with another provider from now on. */
async function switchProvider(kind: string, name: string): Promise<void> {
    if (switching.value !== null) {
        return;
    }

    switching.value = kind;
    switchNote.value = null;

    try {
        const response = await fetch(chat.provider.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(csrf() ? { 'X-XSRF-TOKEN': csrf() as string } : {}),
            },
            body: JSON.stringify({ provider: kind }),
        });
        const data = (await response.json().catch(() => ({}))) as {
            ok?: boolean;
            error?: string;
        };

        switchNote.value = data.ok
            ? t('chat.lainos.switched', { name })
            : t('chat.lainos.switchFailed', { error: data.error ?? '—' });
    } catch {
        switchNote.value = t('chat.lainos.switchFailed', { error: '—' });
    } finally {
        switching.value = null;
        // The panel reads the live provider from the server, never from what
        // this browser hoped the switch did.
        router.reload({ only: ['lainos'] });
    }
}

/*
 * The room rides the console's heartbeat rather than a timer of its own, and
 * asks its own question on every beat instead of waiting to be told that
 * something moved: a version is a count and a whole-second timestamp, and the
 * room is the one lens where two writes inside one second would be visible.
 * `refresh` already declines when this window is history.
 */
useConsoleBeat(() => void refresh());

onMounted(() => {
    void toFirstUnreadOrBottom();
});
</script>

<template>
    <Head title="Пульт · Чат" />

    <div style="display: flex; align-items: baseline; gap: 12px">
        <h1 class="mk-h1">{{ t('chat.title') }}</h1>
        <span class="mk-m mk-t3" style="font-size: 12px">
            {{
                t('chat.room', { who: operators.map((p) => p.name).join(', ') })
            }}
            ·
            <template v-if="lastAt">{{
                t('chat.last', { ago: ago(lastAt) ?? '—' })
            }}</template>
            <template v-else>{{ t('chat.never') }}</template>
        </span>
        <div style="margin-left: auto; display: flex; gap: 8px">
            <Link
                v-if="before"
                :href="chat.index.url()"
                class="mk-btn mk-ghost"
                >{{ t('chat.f.back') }}</Link
            >
            <Link :href="chat.files.url()" class="mk-btn mk-ghost">{{
                t('chat.files', { count: fileCount })
            }}</Link>
        </div>
    </div>

    <div class="mk-chat">
        <section
            class="mk-chat-stream"
            @dragover.prevent="dragging = true"
            @dragleave.self="dragging = false"
            @drop.prevent="drop"
        >
            <div v-if="dragging" class="mk-drop">
                <span style="font-size: 15px; font-weight: 600">{{
                    t('chat.drop')
                }}</span>
                <span class="mk-t3" style="font-size: 12px">{{
                    t('chat.dropNote', {
                        mb: limits.maxMb,
                        count: limits.maxFiles,
                    })
                }}</span>
            </div>

            <div ref="scroller" class="mk-chat-scroll">
                <div
                    v-if="older"
                    style="
                        display: flex;
                        justify-content: center;
                        padding: 6px 0;
                    "
                >
                    <Link
                        :href="chat.index.url({ query: { before: older } })"
                        class="mk-btn mk-ghost"
                        >{{ t('chat.older') }}</Link
                    >
                </div>

                <!-- Silence is a state with its own look: an empty room says
                     how long it has been quiet and who spoke last, or it is
                     indistinguishable from a room that failed to load. -->
                <div
                    v-if="list.length === 0"
                    class="mk-panel"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 26px;
                        padding: 30px;
                    "
                >
                    <svg
                        width="54"
                        height="54"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="var(--mk-calm)"
                        stroke-width="1.2"
                        stroke-linecap="round"
                    >
                        <path d="M2.5 12h19" />
                    </svg>
                    <div>
                        <p style="margin: 0; font-size: 24px; font-weight: 700">
                            {{ t('chat.quiet') }}
                        </p>
                        <p
                            class="mk-t2"
                            style="
                                margin: 7px 0 0;
                                font-size: 13.5px;
                                line-height: 1.6;
                                max-width: 620px;
                            "
                        >
                            {{ t('chat.quietEmpty') }}
                        </p>
                    </div>
                </div>

                <template v-for="row in rows" :key="row.key">
                    <Rule
                        v-if="row.kind === 'day'"
                        :label="row.label"
                        style="margin: 6px 0 2px"
                    />

                    <div
                        v-else-if="row.kind === 'unread'"
                        data-chat-unread
                        style="
                            display: flex;
                            align-items: center;
                            gap: 12px;
                            padding: 10px 0 6px;
                        "
                    >
                        <span class="mk-k" style="color: var(--mk-accent)">{{
                            row.label
                        }}</span>
                        <span
                            style="
                                flex: 1;
                                height: 1px;
                                background: rgba(0, 229, 209, 0.3);
                            "
                        />
                    </div>

                    <div v-else class="mk-msg">
                        <div class="mk-msg-time">
                            {{ shortTime(row.message.at, tag) }}
                        </div>

                        <div
                            class="mk-msg-main"
                            :class="{
                                'mk-lainos': row.message.author === 'lainos',
                            }"
                        >
                            <div
                                v-if="row.named"
                                style="
                                    display: flex;
                                    align-items: center;
                                    gap: 9px;
                                    margin-bottom: 5px;
                                "
                            >
                                <span
                                    class="mk-dot"
                                    :style="{
                                        background:
                                            row.message.author === 'lainos'
                                                ? 'var(--mk-accent)'
                                                : 'var(--mk-flat)',
                                    }"
                                />
                                <span
                                    class="mk-who"
                                    :style="
                                        row.message.author === 'lainos'
                                            ? { color: 'var(--mk-accent)' }
                                            : {}
                                    "
                                    >{{ row.message.name }}</span
                                >
                                <span
                                    v-if="row.message.author === 'lainos'"
                                    class="mk-k"
                                    >{{
                                        backendName(row.message.answer?.backend)
                                    }}</span
                                >
                            </div>

                            <p v-if="row.message.body">
                                <Linked :text="row.message.body" />
                            </p>
                            <p v-else class="mk-t3" style="font-style: italic">
                                {{ t('chat.noCaption') }}
                            </p>

                            <!-- Files: a hairline row each, an image also as
                                 itself, because a screenshot nobody can see
                                 without downloading is a screenshot nobody
                                 looks at. -->
                            <div
                                v-for="file in row.message.files"
                                :key="file.id"
                                class="mk-att"
                            >
                                <span class="mk-ext">{{ file.ext }}</span>
                                <a
                                    v-if="file.kind === 'image'"
                                    :href="chat.download.url(file.id)"
                                    target="_blank"
                                >
                                    <img
                                        class="mk-thumb"
                                        :src="chat.download.url(file.id)"
                                        :alt="file.name"
                                    />
                                </a>
                                <span style="font-size: 13px">{{
                                    file.name
                                }}</span>
                                <span
                                    class="mk-m mk-t3"
                                    style="font-size: 11.5px"
                                    >{{ size(file.size) }}</span
                                >
                                <a
                                    :href="chat.download.url(file.id)"
                                    class="mk-btn"
                                    style="
                                        margin-left: auto;
                                        height: 26px;
                                        padding: 0 10px;
                                    "
                                    >{{ t('chat.download') }}</a
                                >
                            </div>

                            <div
                                v-if="row.message.contact || row.message.task"
                                style="
                                    margin-top: 8px;
                                    display: flex;
                                    gap: 7px;
                                    flex-wrap: wrap;
                                "
                            >
                                <Link
                                    v-if="row.message.contact"
                                    :href="`/crm/${row.message.contact.id}`"
                                    class="mk-tag"
                                    style="
                                        border-color: rgba(255, 43, 214, 0.4);
                                        color: var(--mk-money);
                                    "
                                >
                                    <span
                                        class="mk-dot"
                                        style="
                                            width: 5px;
                                            height: 5px;
                                            flex: 0 0 5px;
                                            background: var(--mk-money);
                                        "
                                    />
                                    {{ row.message.contact.name }}
                                </Link>
                                <Link
                                    v-if="row.message.task"
                                    href="/crm/tasks"
                                    class="mk-tag"
                                    style="
                                        border-color: rgba(0, 229, 209, 0.4);
                                        color: var(--mk-accent);
                                    "
                                >
                                    <span
                                        class="mk-dot"
                                        style="
                                            width: 5px;
                                            height: 5px;
                                            flex: 0 0 5px;
                                            background: var(--mk-accent);
                                        "
                                    />
                                    {{
                                        t('chat.task', {
                                            id: row.message.task.id,
                                        })
                                    }}
                                </Link>
                            </div>

                            <!-- Under an answer: which of the two answered and
                                 what it was given. "LainOS" is two different
                                 correspondents and the difference matters. -->
                            <p
                                v-if="row.message.author === 'lainos'"
                                style="
                                    margin: 9px 0 0;
                                    font-size: 11px;
                                    line-height: 1.5;
                                    color: var(--mk-fainter);
                                "
                            >
                                {{ stamp(row.message) }}
                            </p>

                            <div
                                v-if="asking === row.message.id"
                                class="mk-stripe"
                            >
                                <span class="mk-hatch" />
                                <div style="padding: 12px 15px">
                                    <p
                                        class="mk-m mk-t2"
                                        style="margin: 0; font-size: 12px"
                                    >
                                        {{ t('chat.lainos.thinking') }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-else-if="
                                    row.message.call &&
                                    row.message.call.state !== 'answered'
                                "
                                class="mk-stripe"
                            >
                                <span class="mk-hatch" />
                                <div
                                    style="
                                        flex: 1;
                                        display: flex;
                                        align-items: center;
                                        gap: 14px;
                                        padding: 12px 15px;
                                    "
                                >
                                    <div style="flex: 1">
                                        <p
                                            class="mk-t2"
                                            style="
                                                margin: 0;
                                                font-size: 13px;
                                                font-weight: 600;
                                            "
                                        >
                                            {{
                                                row.message.call.state ===
                                                'awaiting'
                                                    ? t('chat.lainos.pending')
                                                    : t('chat.lainos.noAnswer')
                                            }}
                                        </p>
                                        <p
                                            class="mk-m mk-t3"
                                            style="
                                                margin: 4px 0 0;
                                                font-size: 11.5px;
                                            "
                                        >
                                            {{
                                                row.message.call.state ===
                                                'awaiting'
                                                    ? t(
                                                          'chat.lainos.note.pending',
                                                      )
                                                    : row.message.call.note ===
                                                        'disabled'
                                                      ? t(
                                                            'chat.lainos.note.disabled',
                                                        )
                                                      : t(
                                                            'chat.lainos.note.unreachable',
                                                            {
                                                                ago:
                                                                    ago(
                                                                        row
                                                                            .message
                                                                            .at,
                                                                    ) ?? '—',
                                                            },
                                                        )
                                            }}
                                        </p>
                                        <!-- What was actually tried. A
                                             failure is debuggable from the
                                             screen it happened on, or it is
                                             debugged in laravel.log by
                                             whoever still has ssh. -->
                                        <p
                                            v-for="(attempt, i) in row.message
                                                .call.attempts"
                                            :key="i"
                                            class="mk-m"
                                            style="
                                                margin: 3px 0 0;
                                                font-size: 11px;
                                                color: var(--mk-fainter);
                                            "
                                        >
                                            {{
                                                t('chat.lainos.attempt', {
                                                    backend: backendName(
                                                        attempt.backend,
                                                    ),
                                                    outcome: attempt.outcome,
                                                    ms: attempt.ms,
                                                })
                                            }}<template v-if="attempt.model">
                                                · {{ attempt.model }}</template
                                            >
                                        </p>
                                    </div>
                                    <button
                                        v-if="
                                            row.message.call.note !== 'disabled'
                                        "
                                        type="button"
                                        class="mk-btn mk-act"
                                        style="height: 26px"
                                        @click="ask(row.message.id)"
                                    >
                                        {{ t('chat.lainos.retry') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mk-msg-act">
                            <button
                                v-if="!row.message.task"
                                type="button"
                                class="mk-btn mk-ghost"
                                style="height: 26px"
                                @click="toTask(row.message)"
                            >
                                {{ t('chat.toTask') }}
                            </button>
                            <button
                                v-if="row.message.mine"
                                type="button"
                                class="mk-btn mk-ghost"
                                style="height: 26px"
                                @click="remove(row.message)"
                            >
                                {{ t('chat.delete') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <div>
                <div
                    v-if="attachments.length > 0"
                    style="
                        margin-top: 12px;
                        display: flex;
                        gap: 8px;
                        flex-wrap: wrap;
                    "
                >
                    <span
                        v-for="(file, index) in attachments"
                        :key="index"
                        class="mk-tag"
                        style="height: 24px"
                    >
                        {{ file.name }}
                        <button
                            type="button"
                            style="
                                background: none;
                                border: 0;
                                color: var(--mk-faint);
                                cursor: pointer;
                            "
                            @click="
                                attachments = attachments.filter(
                                    (_, i) => i !== index,
                                )
                            "
                        >
                            ×
                        </button>
                    </span>
                </div>

                <p
                    v-if="errors.body || errors['files.0']"
                    style="
                        margin: 10px 0 0;
                        font-size: 12px;
                        color: var(--mk-critical);
                    "
                >
                    {{ errors.body ?? errors['files.0'] }}
                </p>

                <div class="mk-composer">
                    <label style="display: flex; cursor: pointer">
                        <input
                            type="file"
                            multiple
                            hidden
                            :title="t('chat.attach')"
                            @change="pick"
                        />
                        <svg
                            width="17"
                            height="17"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="var(--mk-faint)"
                            stroke-width="1.6"
                            stroke-linecap="round"
                        >
                            <path
                                d="M20 11.5l-8 8a4.5 4.5 0 0 1-6.4-6.4l8.4-8.4a3 3 0 0 1 4.2 4.2l-8.4 8.4a1.6 1.6 0 0 1-2.2-2.2l7.7-7.7"
                            />
                        </svg>
                    </label>

                    <textarea
                        ref="input"
                        v-model="draft"
                        rows="1"
                        :maxlength="limits.maxChars"
                        :placeholder="t('chat.composer')"
                        @input="resize"
                        @keydown="keydown"
                    />

                    <span
                        class="mk-t3 mk-wide"
                        style="font-size: 11.5px; padding-bottom: 2px"
                        >{{ t('chat.hint') }}</span
                    >

                    <button
                        type="button"
                        class="mk-btn mk-act"
                        :disabled="sending"
                        @click="send"
                    >
                        {{ sending ? t('chat.sending') : t('chat.send') }}
                    </button>
                </div>
            </div>
        </section>

        <aside class="mk-chat-side">
            <template v-if="side === 'room'">
                <div>
                    <Rule :label="t('chat.people')" />
                    <div style="margin-top: 6px">
                        <div
                            v-for="person in people"
                            :key="person.kind + String(person.id)"
                            class="mk-hair"
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                padding: 9px 0;
                            "
                            :style="
                                person.kind === 'lainos'
                                    ? { cursor: 'pointer' }
                                    : {}
                            "
                            @click="
                                person.kind === 'lainos' && (side = 'lainos')
                            "
                        >
                            <span
                                class="mk-dot"
                                :style="{
                                    background:
                                        person.kind === 'lainos'
                                            ? 'var(--mk-accent)'
                                            : person.you
                                              ? 'var(--mk-accent)'
                                              : 'var(--mk-flat)',
                                }"
                            />
                            <span style="font-size: 12.5px">{{
                                person.name
                            }}</span>
                            <span
                                class="mk-m mk-t3"
                                style="margin-left: auto; font-size: 11px"
                            >
                                <template v-if="person.kind === 'lainos'">{{
                                    backendName(person.backend)
                                }}</template>
                                <template v-else-if="person.you">{{
                                    t('chat.here')
                                }}</template>
                                <template v-else-if="person.seenAt">{{
                                    t('chat.seen', {
                                        ago: ago(person.seenAt) ?? '—',
                                    })
                                }}</template>
                                <template v-else>{{
                                    t('chat.unseen')
                                }}</template>
                            </span>
                        </div>
                    </div>
                </div>

                <div>
                    <Rule
                        :label="t('chat.recentFiles')"
                        :note="String(fileCount)"
                    />
                    <div style="margin-top: 8px">
                        <div
                            v-for="file in files"
                            :key="file.id"
                            style="padding: 8px 0"
                            class="mk-hair"
                        >
                            <div
                                style="
                                    display: flex;
                                    align-items: center;
                                    gap: 10px;
                                "
                            >
                                <span class="mk-ext">{{ file.ext }}</span>
                                <a
                                    :href="chat.download.url(file.id)"
                                    class="mk-clip"
                                    style="
                                        font-size: 12.5px;
                                        color: var(--mk-body);
                                    "
                                    >{{ file.name }}</a
                                >
                                <span
                                    class="mk-m mk-t3"
                                    style="margin-left: auto; font-size: 11px"
                                    >{{ size(file.size) }}</span
                                >
                            </div>
                            <div
                                class="mk-t3"
                                style="margin-top: 3px; font-size: 11px"
                            >
                                {{ file.by }} · {{ shortTime(file.at, tag) }}
                            </div>
                        </div>
                    </div>
                    <Link
                        :href="chat.files.url()"
                        class="mk-btn mk-ghost"
                        style="margin-top: 10px; padding-left: 0"
                        >{{ t('chat.allFiles') }}</Link
                    >
                </div>

                <p
                    class="mk-t3"
                    style="
                        margin: auto 0 0;
                        font-size: 11px;
                        line-height: 1.55;
                        color: var(--mk-fainter);
                    "
                >
                    {{ t('chat.sideNote') }}
                </p>
            </template>

            <template v-else>
                <div>
                    <Rule :label="t('chat.lainos.who')">
                        <button
                            type="button"
                            class="mk-btn mk-ghost"
                            style="height: 22px; padding: 0 6px"
                            @click="side = 'room'"
                        >
                            ←
                        </button>
                    </Rule>
                    <div
                        style="
                            margin-top: 10px;
                            display: flex;
                            flex-direction: column;
                            gap: 1px;
                        "
                    >
                        <div
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                height: 38px;
                                padding: 0 11px;
                                font-size: 13px;
                            "
                            :style="
                                lainos.backend === 'daemon'
                                    ? {
                                          background: 'var(--mk-accent-soft)',
                                          boxShadow:
                                              'inset 2px 0 0 var(--mk-accent)',
                                      }
                                    : { color: 'var(--mk-dim)' }
                            "
                        >
                            <span
                                class="mk-dot"
                                :style="{
                                    background: lainos.daemon
                                        ? 'var(--mk-accent)'
                                        : 'var(--mk-flat)',
                                }"
                            />
                            {{ t('chat.lainos.daemon') }}
                        </div>
                        <div
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                height: 38px;
                                padding: 0 11px;
                                font-size: 13px;
                            "
                            :style="
                                lainos.backend === 'persona'
                                    ? {
                                          background: 'var(--mk-accent-soft)',
                                          boxShadow:
                                              'inset 2px 0 0 var(--mk-accent)',
                                      }
                                    : { color: 'var(--mk-dim)' }
                            "
                        >
                            <span
                                class="mk-dot"
                                :style="{
                                    background: lainos.persona
                                        ? 'var(--mk-accent)'
                                        : 'var(--mk-flat)',
                                }"
                            />
                            {{ t('chat.lainos.persona') }}
                        </div>
                    </div>
                    <p
                        class="mk-t3"
                        style="
                            margin: 10px 11px 0;
                            font-size: 11px;
                            line-height: 1.55;
                            color: var(--mk-fainter);
                        "
                    >
                        {{ t('chat.lainos.whoNote') }}
                    </p>
                </div>

                <!-- Which model is on the other end right now. Read from the
                     daemon, because it is the only thing that knows: the
                     provider is switched at runtime from three surfaces. -->
                <div v-if="lainos.daemon">
                    <Rule :label="t('chat.lainos.live')" />

                    <div v-if="lainos.provider" style="margin-top: 8px">
                        <div
                            class="mk-hair"
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                padding: 9px 0;
                            "
                        >
                            <span
                                class="mk-dot"
                                style="background: var(--mk-accent)"
                            />
                            <span class="mk-m" style="font-size: 12.5px">{{
                                lainos.provider.model
                            }}</span>
                        </div>
                        <div
                            class="mk-hair"
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                padding: 9px 0;
                            "
                        >
                            <span
                                class="mk-dot"
                                style="background: var(--mk-flat)"
                            />
                            <span class="mk-t2" style="font-size: 12.5px">{{
                                lainos.provider.name
                            }}</span>
                            <span
                                v-if="lainos.provider.overridden"
                                class="mk-m mk-t3"
                                style="margin-left: auto; font-size: 11px"
                                >{{ t('chat.lainos.override') }}</span
                            >
                        </div>
                    </div>

                    <!-- Unreadable is not "off": the daemon may be answering
                         perfectly well and simply not have told us. -->
                    <div v-else class="mk-stripe" style="margin-top: 8px">
                        <span class="mk-hatch" />
                        <p
                            class="mk-m mk-t3"
                            style="
                                margin: 0;
                                padding: 10px 12px;
                                font-size: 11.5px;
                            "
                        >
                            {{ t('chat.lainos.unreadable') }}
                        </p>
                    </div>

                    <div
                        v-if="lainos.choices.length > 0"
                        style="
                            margin-top: 10px;
                            display: flex;
                            flex-wrap: wrap;
                            gap: 6px;
                        "
                    >
                        <button
                            v-for="choice in lainos.choices"
                            :key="choice.kind"
                            type="button"
                            class="mk-btn"
                            :class="{
                                'mk-act': lainos.provider?.kind === choice.kind,
                            }"
                            style="
                                height: 24px;
                                padding: 0 8px;
                                font-size: 11px;
                            "
                            :title="choice.desc"
                            :disabled="
                                switching !== null ||
                                lainos.provider?.kind === choice.kind
                            "
                            @click="switchProvider(choice.kind, choice.name)"
                        >
                            {{ choice.name }}
                        </button>
                    </div>

                    <p
                        v-if="switchNote"
                        class="mk-m"
                        style="
                            margin: 8px 0 0;
                            font-size: 11px;
                            color: var(--mk-dim);
                        "
                    >
                        {{ switchNote }}
                    </p>
                </div>

                <div>
                    <Rule :label="t('chat.lainos.sees')" />
                    <div style="margin-top: 6px">
                        <div
                            v-for="item in [
                                {
                                    label: t('chat.lainos.seesMessages', {
                                        count: limits.contextMessages,
                                    }),
                                    note: t('chat.lainos.always'),
                                    tone: 'accent',
                                },
                                {
                                    label: t('chat.lainos.seesFiles'),
                                    note: t('chat.lainos.always'),
                                    tone: 'accent',
                                },
                                {
                                    label: t('chat.lainos.seesText'),
                                    note: t('chat.lainos.onAsk'),
                                    tone: 'warning',
                                },
                            ]"
                            :key="item.label"
                            class="mk-hair"
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                padding: 9px 0;
                            "
                        >
                            <span
                                class="mk-dot"
                                :style="{
                                    background: `var(--mk-${item.tone})`,
                                }"
                            />
                            <span style="font-size: 12.5px">{{
                                item.label
                            }}</span>
                            <span
                                class="mk-m mk-t3"
                                style="margin-left: auto; font-size: 11px"
                                >{{ item.note }}</span
                            >
                        </div>
                    </div>
                </div>

                <div>
                    <Rule :label="t('chat.lainos.blind')" />
                    <div style="margin-top: 6px">
                        <div
                            v-for="label in [
                                t('chat.lainos.blindKeys'),
                                t('chat.lainos.blindWallets'),
                                t('chat.lainos.blindOlder'),
                            ]"
                            :key="label"
                            class="mk-hair"
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                padding: 9px 0;
                            "
                        >
                            <span
                                class="mk-dot"
                                style="background: var(--mk-flat)"
                            />
                            <span class="mk-t2" style="font-size: 12.5px">{{
                                label
                            }}</span>
                            <span
                                class="mk-m mk-t3"
                                style="margin-left: auto; font-size: 11px"
                                >{{ t('chat.lainos.never') }}</span
                            >
                        </div>
                    </div>
                </div>
            </template>
        </aside>
    </div>
</template>
