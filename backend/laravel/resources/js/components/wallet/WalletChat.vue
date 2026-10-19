<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import {
    ArrowLeft,
    ArrowUp,
    Check,
    Clock,
    Loader2,
    Lock,
    LockOpen,
    MessageCircle,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    SquarePen,
    Trash2,
} from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import WalletChatAvatar from '@/components/wallet/WalletChatAvatar.vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { useWalletChatInbox } from '@/composables/useWalletChatInbox';
import {
    chatFingerprint,
    chatMessageId,
    clearChat,
    dropChatPending,
    fetchChatPeople,
    lookupChatKey,
    markChatKeyVerified,
    pinChatKey,
    queueChatMessage,
    readChatPending,
    readChatState,
    sendChatEnvelope,
} from '@/lib/wallet';
import type {
    ChatKeyRecord,
    ChatMeta,
    ChatPerson,
    ChatRelayRow,
    ChatRow,
    PendingMessage,
} from '@/lib/wallet';
import { growComposer } from '@/lib/wallet/composer';
import { shortAddress } from '@/lib/wallet/format';
import { playWalletSound } from '@/lib/wallet/notifications';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Messages between wallets, addressed by EVM address.
 *
 * A message travels one of two ways, and the lock beside the composer is the
 * choice. **Encrypted**: sealed here under a key only the two wallets can
 * derive (an ECDH between their messaging keys — lib/wallet/chatCrypto.ts),
 * so Cyberia relays an envelope it cannot open. **Not encrypted**: the relay
 * holds the text, and the thread says so. The second exists because the first
 * needs a published key at both ends, and most people here have never opened
 * this screen — a chat that could only reach people who had already found it
 * was a chat with nobody in it.
 *
 * So the default follows what is possible: encrypted when both sides have
 * published a key, plain otherwise, and switchable either way. Switching
 * encryption on for the first time publishes this account's key, which is the
 * one thing here that is done only because the person pressed something.
 *
 * The mailbox itself — fetching, the proof that opens it, the unread count —
 * lives in `useWalletChatInbox`, which the page keeps running so the wallet
 * can say "new message" while this screen is closed. What lives here is what
 * needs the keys: decrypting, pinning, the safety number, and the queue of
 * encrypted messages written to somebody who cannot receive them yet.
 *
 * The limit is stated as plainly as the guarantee: the relay sees who is
 * talking to whom and when, whichever way a message travelled.
 */

const props = defineProps<{
    wallet: MultiWallet;
    /**
     * Somebody to open a thread with on arrival — an address handed over by
     * another screen (the feed, a toast). Watched rather than read once,
     * because the chat may already be on screen when the request arrives.
     */
    openWith?: string | null;
}>();

const emit = defineEmits<{
    /** The handed-over address has been dealt with. */
    opened: [];
}>();

const { t, tag } = useLocale(walletMessages);

const inbox = useWalletChatInbox();
inbox.attach(props.wallet);

/** Two panes side by side on a desktop; one at a time on a phone. */
const wide = useMediaQuery('(min-width: 1024px)');

const identity = inbox.identity;
const address = computed(() => identity.value?.address ?? null);

const fingerprint = computed(() =>
    identity.value ? chatFingerprint(identity.value.publicKey) : '',
);

type Mode = 'sealed' | 'plain';

type Decrypted = {
    row: ChatRow;
    peer: string;
    mine: boolean;
    /** Null when the envelope would not open — never a guess at its contents. */
    text: string | null;
};

const opened = ref<Record<string, Decrypted>>({});
const peerKeys = ref<Record<string, ChatKeyRecord>>({});
/** Looked up and found to have no key, so a plain message is the default. */
const keyless = ref<Record<string, true>>({});
const suspectPeers = ref<string[]>([]);

const view = ref<'thread' | 'verify' | null>(null);
const peer = ref<string | null>(null);
const draft = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const transcript = ref<HTMLElement | null>(null);
const composer = ref<HTMLTextAreaElement | null>(null);
const search = ref<HTMLInputElement | null>(null);

/* ------------------------------------------------------------- people --- */

const nameOf = (who: string): string =>
    inbox.directory.value[who.toLowerCase()]?.name || shortAddress(who);

const avatarOf = (who: string): string | null =>
    inbox.directory.value[who.toLowerCase()]?.avatar ?? null;

/** Whether the directory knows a name for this address at all. */
const named = (who: string): boolean =>
    Boolean(inbox.directory.value[who.toLowerCase()]?.name);

/* --------------------------------------------------------- decryption --- */

const other = (row: ChatRow, self: string): string =>
    row.from.toLowerCase() === self
        ? row.to.toLowerCase()
        : row.from.toLowerCase();

/**
 * The peer's published key, verified and pinned.
 *
 * `lookupChatKey` refuses anything the address did not sign, so the relay
 * cannot answer with a key of its own. Pinning catches the case a signature
 * cannot: a key that is validly signed but *different* from the one this
 * device has been talking to, which is what an address changing hands — or an
 * attempt at interception — looks like from here.
 */
const keyFor = async (who: string): Promise<ChatKeyRecord | null> => {
    const known = peerKeys.value[who];

    if (known) {
        return known;
    }

    const record = await lookupChatKey(who);

    if (!record) {
        keyless.value = { ...keyless.value, [who]: true };

        return null;
    }

    if (address.value && pinChatKey(address.value, who, record) === 'changed') {
        suspectPeers.value = [...new Set([...suspectPeers.value, who])];
    }

    if (keyless.value[who]) {
        const rest = { ...keyless.value };
        delete rest[who];
        keyless.value = rest;
    }

    peerKeys.value = { ...peerKeys.value, [who]: record };

    return record;
};

const metaOf = (row: ChatRow): ChatMeta => ({
    id: row.id,
    from: row.from,
    to: row.to,
    sentAt: row.sentAt,
});

let decrypting = false;
let decryptAgain = false;

/**
 * Open whatever has arrived but not been opened yet.
 *
 * A plain message was sealed to this account's own key when it was stored, so
 * it opens without asking anybody for anything. An encrypted one needs the
 * peer's key, and not being able to *reach* it is a network problem — the row
 * is left alone and tried again — while an envelope that will not open under a
 * key we do have is a claim about the envelope, and is shown as such.
 */
const decrypt = async (): Promise<void> => {
    const me = identity.value;

    if (!me) {
        return;
    }

    if (decrypting) {
        decryptAgain = true;

        return;
    }

    decrypting = true;

    try {
        for (const row of inbox.rows.value) {
            if (opened.value[row.id]) {
                continue;
            }

            const who = other(row, me.address);
            let publicKey = me.publicKey;

            if (!row.plain) {
                let record: ChatKeyRecord | null = null;

                try {
                    record = await keyFor(who);
                } catch {
                    continue;
                }

                if (!record) {
                    continue;
                }

                publicKey = record.publicKey;
            }

            let text: string | null;

            try {
                text = await props.wallet.chatOpen(publicKey, metaOf(row), {
                    iv: row.iv,
                    body: row.body,
                });
            } catch {
                text = null;
            }

            opened.value = {
                ...opened.value,
                [row.id]: {
                    row,
                    peer: who,
                    mine: row.from.toLowerCase() === me.address,
                    text,
                },
            };
        }
    } finally {
        decrypting = false;

        if (decryptAgain) {
            decryptAgain = false;
            void decrypt();
        }
    }
};

const messages = computed(() =>
    inbox.rows.value
        .map((row) => opened.value[row.id])
        .filter((entry): entry is Decrypted => entry !== undefined),
);

/* ------------------------------------------------------------ pending --- */

/**
 * Encrypted messages written to addresses that cannot receive them yet.
 *
 * Only reached when somebody turns encryption on for a person who has no key —
 * plain is the default there — and kept for the same reason as before: what
 * somebody wrote is not lost because the recipient has not done something.
 */
const pending = ref<PendingMessage[]>([]);
const pendingTexts = ref<Record<string, string>>({});

const readPending = (): void => {
    pending.value = identity.value
        ? readChatPending(identity.value.address)
        : [];
};

const openPending = async (): Promise<void> => {
    const me = identity.value;

    if (!me) {
        return;
    }

    for (const entry of pending.value) {
        if (pendingTexts.value[entry.id] !== undefined) {
            continue;
        }

        let text: string;

        try {
            text = await props.wallet.chatOpen(
                me.publicKey,
                {
                    id: entry.id,
                    from: me.address,
                    to: entry.to,
                    sentAt: entry.sentAt,
                },
                entry.envelope,
            );
        } catch {
            text = t('chatUnreadable');
        }

        pendingTexts.value = { ...pendingTexts.value, [entry.id]: text };
    }
};

watch(pending, () => void openPending(), { deep: true });

/** Keep an encrypted message nobody can be sent yet, and show it. */
const queue = async (to: string, text: string): Promise<void> => {
    const me = identity.value;

    if (!me) {
        return;
    }

    const meta: ChatMeta = {
        id: chatMessageId(),
        from: me.address,
        to,
        sentAt: new Date().toISOString(),
    };

    // Sealed to this wallet's own key: unsent mail is still mail.
    const envelope = await props.wallet.chatSeal(me.publicKey, meta, text);

    pending.value = queueChatMessage(me.address, {
        id: meta.id,
        to,
        sentAt: meta.sentAt,
        envelope,
    });
};

let flushing = false;

/**
 * Try every held message again — on every beat of the mailbox and whenever a
 * thread opens, so a recipient who published a key a minute ago gets it
 * without anybody having to notice. A failure stays queued.
 */
const flushPending = async (): Promise<void> => {
    const me = identity.value;

    if (!me || pending.value.length === 0 || flushing || !inbox.proven.value) {
        return;
    }

    flushing = true;

    try {
        for (const entry of [...pending.value]) {
            const record = await keyFor(entry.to);

            if (!record) {
                continue;
            }

            const meta: ChatMeta = {
                id: entry.id,
                from: me.address,
                to: entry.to,
                sentAt: entry.sentAt,
            };

            let text: string;

            try {
                text = await props.wallet.chatOpen(
                    me.publicKey,
                    meta,
                    entry.envelope,
                );
            } catch {
                // Sealed under a key this account no longer has.
                pending.value = dropChatPending(me.address, entry.id);

                continue;
            }

            const envelope = await props.wallet.chatSeal(
                record.publicKey,
                meta,
                text,
            );
            const stored = (await sendChatEnvelope({
                ...meta,
                ...envelope,
            })) as {
                message: ChatRelayRow;
            };

            await inbox.keepSent(stored.message);
            pending.value = dropChatPending(me.address, entry.id);
        }
    } catch {
        // Left queued on purpose; the next beat tries again.
    } finally {
        flushing = false;
    }
};

/* ------------------------------------------------------------- threads --- */

/**
 * One row per correspondent, newest first. A thread that holds only queued
 * messages is listed first: it is the one with something still to happen.
 */
const threads = computed(() => {
    const byPeer = new Map<
        string,
        { peer: string; last: Decrypted | null; waiting: number }
    >();

    for (const entry of messages.value) {
        byPeer.set(entry.peer, {
            peer: entry.peer,
            last: entry,
            waiting: byPeer.get(entry.peer)?.waiting ?? 0,
        });
    }

    for (const entry of pending.value) {
        const existing = byPeer.get(entry.to);

        byPeer.set(entry.to, {
            peer: entry.to,
            last: existing?.last ?? null,
            waiting: (existing?.waiting ?? 0) + 1,
        });
    }

    return [...byPeer.values()]
        .map((entry) => ({
            ...entry,
            unread: inbox.unreadByPeer.value[entry.peer] ?? 0,
        }))
        .sort((a, b) => {
            if (a.waiting > 0 !== b.waiting > 0) {
                return a.waiting > 0 ? -1 : 1;
            }

            return (b.last?.row.seq ?? 0) - (a.last?.row.seq ?? 0);
        });
});

watch(
    () => threads.value.map((entry) => entry.peer),
    (peers) => void inbox.resolve(peers),
    { immediate: true },
);

/* --------------------------------------------------------------- list --- */

const query = ref('');
const people = ref<ChatPerson[]>([]);
const peopleLoading = ref(false);
const peopleFailed = ref(false);

const loadPeople = async (): Promise<void> => {
    peopleLoading.value = true;
    peopleFailed.value = false;

    try {
        people.value = await fetchChatPeople(query.value.trim());
        inbox.remember(people.value);
    } catch {
        peopleFailed.value = true;
        people.value = [];
    } finally {
        peopleLoading.value = false;
    }
};

let searchTimer: ReturnType<typeof setTimeout> | null = null;

watch(query, () => {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => void loadPeople(), 250);
});

const needle = computed(() => query.value.trim().toLowerCase());

const visibleThreads = computed(() =>
    needle.value === ''
        ? threads.value
        : threads.value.filter(
              (entry) =>
                  entry.peer.includes(needle.value) ||
                  nameOf(entry.peer).toLowerCase().includes(needle.value),
          ),
);

/**
 * People to write to who are not already a thread. A few while nothing is
 * typed — an invitation, not a directory — and every match once something is.
 */
const peopleShown = computed(() => {
    const inThreads = new Set(threads.value.map((entry) => entry.peer));
    const fresh = people.value.filter((person) => {
        const who = person.address.toLowerCase();

        return who !== address.value && !inThreads.has(who);
    });

    return needle.value === '' ? fresh.slice(0, 6) : fresh;
});

/** A full address typed into the search, which is enough to write to. */
const addressCandidate = computed(() => {
    const value = needle.value;

    if (!/^0x[0-9a-f]{40}$/.test(value) || value === address.value) {
        return null;
    }

    return threads.value.some((entry) => entry.peer === value) ||
        people.value.some((person) => person.address.toLowerCase() === value)
        ? null
        : value;
});

const focusSearch = (): void => {
    view.value = null;
    peer.value = null;
    void nextTick(() => search.value?.focus());
};

const lastPreview = (entry: (typeof threads.value)[number]): string => {
    if (!entry.last) {
        return t('chatWaitingCount', { count: entry.waiting });
    }

    if (entry.last.text === null) {
        return t('chatUnreadableShort');
    }

    return entry.last.mine
        ? `${t('chatYou')}: ${entry.last.text}`
        : entry.last.text;
};

/* -------------------------------------------------------------- times --- */

const dayKey = (at: Date): string =>
    `${at.getFullYear()}-${at.getMonth()}-${at.getDate()}`;

const daysAgo = (at: Date): number => {
    const start = (value: Date): number =>
        new Date(
            value.getFullYear(),
            value.getMonth(),
            value.getDate(),
        ).getTime();

    return Math.round((start(new Date()) - start(at)) / 86_400_000);
};

const clock = (value: string): string => {
    const at = new Date(value);

    return Number.isNaN(at.getTime())
        ? ''
        : at.toLocaleTimeString(tag.value, {
              hour: '2-digit',
              minute: '2-digit',
          });
};

/** Today's time, "yesterday", a weekday this week, a date before that. */
const listTime = (value: string): string => {
    const at = new Date(value);

    if (Number.isNaN(at.getTime())) {
        return '';
    }

    const ago = daysAgo(at);

    if (ago <= 0) {
        return clock(value);
    }

    if (ago === 1) {
        return t('chatYesterday');
    }

    return ago < 7
        ? at.toLocaleDateString(tag.value, { weekday: 'short' })
        : at.toLocaleDateString(tag.value, {
              day: '2-digit',
              month: '2-digit',
          });
};

const dayLabel = (at: Date): string => {
    const ago = daysAgo(at);

    if (ago <= 0) {
        return t('chatToday');
    }

    if (ago === 1) {
        return t('chatYesterday');
    }

    return at.toLocaleDateString(tag.value, {
        day: 'numeric',
        month: 'long',
        ...(at.getFullYear() === new Date().getFullYear()
            ? {}
            : { year: 'numeric' }),
    });
};

/* ------------------------------------------------------------- thread --- */

type Item =
    | { kind: 'day'; key: string; label: string }
    | {
          kind: 'message';
          key: string;
          mine: boolean;
          text: string | null;
          sentAt: string;
          sealed: boolean;
          waiting: boolean;
          /** The last of a run from one side — it gets the tail. */
          tail: boolean;
      };

/** Two messages this close together from one side read as one run. */
const RUN_MS = 5 * 60_000;

const threadItems = computed<Item[]>(() => {
    if (peer.value === null) {
        return [];
    }

    const entries = [
        ...messages.value
            .filter((entry) => entry.peer === peer.value)
            .map((entry) => ({
                key: entry.row.id,
                mine: entry.mine,
                text: entry.text,
                sentAt: entry.row.sentAt,
                sealed: !entry.row.plain,
                waiting: false,
            })),
        ...pending.value
            .filter((entry) => entry.to === peer.value)
            .map((entry) => ({
                key: entry.id,
                mine: true,
                text: pendingTexts.value[entry.id] ?? '…',
                sentAt: entry.sentAt,
                sealed: true,
                waiting: true,
            })),
    ];

    const items: Item[] = [];
    let day = '';

    entries.forEach((entry, index) => {
        const at = new Date(entry.sentAt);

        if (!Number.isNaN(at.getTime()) && dayKey(at) !== day) {
            day = dayKey(at);
            items.push({ kind: 'day', key: `day-${day}`, label: dayLabel(at) });
        }

        const next = entries[index + 1];
        const tail =
            !next ||
            next.mine !== entry.mine ||
            Math.abs(Date.parse(next.sentAt) - at.getTime()) > RUN_MS ||
            dayKey(new Date(next.sentAt)) !== dayKey(at);

        items.push({ kind: 'message', ...entry, tail });
    });

    return items;
});

const peerSuspect = computed(
    () => peer.value !== null && suspectPeers.value.includes(peer.value),
);

const peerHasKey = computed(
    () => peer.value !== null && Boolean(peerKeys.value[peer.value]),
);

/** Choices made in this session, per correspondent. */
const modes = ref<Record<string, Mode>>({});

/**
 * Encrypted when both ends can be — this account has published a key and so
 * has the peer — and plain otherwise. Somebody who switched it for this thread
 * keeps what they chose.
 */
const mode = computed<Mode>(() => {
    if (peer.value === null) {
        return 'plain';
    }

    return (
        modes.value[peer.value] ??
        (inbox.published.value && peerHasKey.value ? 'sealed' : 'plain')
    );
});

/** The offer to switch encryption on, shown when the lock is pressed first. */
const offering = ref(false);
const publishing = ref(false);

const toggleMode = (): void => {
    const who = peer.value;

    if (!who) {
        return;
    }

    if (mode.value === 'sealed') {
        modes.value = { ...modes.value, [who]: 'plain' };

        return;
    }

    if (!inbox.published.value) {
        offering.value = true;

        return;
    }

    modes.value = { ...modes.value, [who]: 'sealed' };
};

const turnOnEncryption = async (): Promise<void> => {
    const who = peer.value;

    publishing.value = true;
    error.value = null;

    try {
        await inbox.publish();
        offering.value = false;

        if (who) {
            modes.value = { ...modes.value, [who]: 'sealed' };
        }
    } catch (failure) {
        error.value =
            failure instanceof Error ? failure.message : String(failure);
    } finally {
        publishing.value = false;
    }
};

/* --------------------------------------------------------- scrolling --- */

const nearBottom = ref(true);

const onScroll = (): void => {
    const log = transcript.value;

    if (log) {
        nearBottom.value =
            log.scrollHeight - log.scrollTop - log.clientHeight < 120;
    }
};

const scrollDown = async (force = false): Promise<void> => {
    await nextTick();

    const log = transcript.value;

    if (log && (force || nearBottom.value)) {
        log.scrollTop = log.scrollHeight;
        nearBottom.value = true;
    }
};

watch(
    () => threadItems.value.length,
    () => void scrollDown(),
);

/* --------------------------------------------------------------- send --- */

const send = async (): Promise<void> => {
    const me = identity.value;
    const to = peer.value;
    const text = draft.value.trim();

    if (!me || !to || text === '' || sending.value) {
        return;
    }

    sending.value = true;
    error.value = null;

    try {
        if (mode.value === 'plain') {
            await inbox.sendPlain(to, text);
        } else {
            const record = await keyFor(to);

            if (!record) {
                // Nothing to encrypt to yet: kept, sealed to this wallet's
                // own key, and sent by itself once that address has one.
                await queue(to, text);
            } else {
                if (!(await inbox.prove())) {
                    throw new Error(inbox.failure.value ?? t('chatRelayDown'));
                }

                const meta: ChatMeta = {
                    id: chatMessageId(),
                    from: me.address,
                    to,
                    sentAt: new Date().toISOString(),
                };
                const envelope = await props.wallet.chatSeal(
                    record.publicKey,
                    meta,
                    text,
                );
                const stored = (await sendChatEnvelope({
                    ...meta,
                    ...envelope,
                })) as { message: ChatRelayRow };

                await inbox.keepSent(stored.message);
            }
        }

        draft.value = '';
        inbox.markRead(to);
        await decrypt();
        await scrollDown(true);
        playWalletSound('message');
    } catch (failure) {
        if ((failure as { status?: number }).status === 403) {
            inbox.proven.value = false;
        }

        error.value =
            failure instanceof Error ? failure.message : String(failure);
    } finally {
        sending.value = false;
        void nextTick(() => composer.value?.focus());
    }
};

/** Enter sends; Shift+Enter is a newline, as in every chat ever written. */
const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void send();
    }
};

watch(draft, () => void nextTick(() => growComposer(composer.value)));

/* --------------------------------------------------------- navigation --- */

const openThread = (who: string): void => {
    const target = who.toLowerCase();

    peer.value = target;
    view.value = 'thread';
    error.value = null;
    offering.value = false;
    query.value = '';
    inbox.viewing.value = target;
    inbox.markRead(target);
    readVerifications();

    void inbox.resolve([target]);
    void keyFor(target).catch(() => undefined);
    void flushPending();
    void scrollDown(true);

    if (wide.value) {
        void nextTick(() => composer.value?.focus());
    }
};

const back = (): void => {
    if (view.value === 'verify') {
        view.value = 'thread';

        return;
    }

    view.value = null;
    peer.value = null;
    inbox.viewing.value = null;
};

/* ---------------------------------------------------------- verifying --- */

/**
 * Peer address → when its safety number was last compared out of band. A ref
 * because the list draws from it too, and localStorage tells nobody when it
 * changes.
 */
const verifiedPeers = ref<Record<string, string>>({});

const readVerifications = (): void => {
    const state = address.value ? readChatState(address.value) : null;

    verifiedPeers.value = Object.fromEntries(
        Object.entries(state?.peers ?? {})
            .filter(([, pin]) => typeof pin.verifiedAt === 'string')
            .map(([who, pin]) => [who, pin.verifiedAt as string]),
    );
};

const peerVerifiedAt = computed(() =>
    peer.value ? (verifiedPeers.value[peer.value] ?? null) : null,
);

const peerFingerprint = computed(() => {
    const record = peer.value ? peerKeys.value[peer.value] : null;

    return record ? chatFingerprint(record.publicKey) : '';
});

const fingerprintGroups = computed(() =>
    peerFingerprint.value.split(' ').filter(Boolean),
);

const openVerify = (): void => {
    readVerifications();
    view.value = 'verify';
};

const confirmVerified = (): void => {
    if (!address.value || !peer.value) {
        return;
    }

    if (markChatKeyVerified(address.value, peer.value)) {
        readVerifications();
        suspectPeers.value = suspectPeers.value.filter(
            (entry) => entry !== peer.value,
        );
    }
};

const when = (value: string): string => {
    const at = new Date(value);

    return Number.isNaN(at.getTime())
        ? '—'
        : at.toLocaleString(tag.value, {
              day: '2-digit',
              month: '2-digit',
              hour: '2-digit',
              minute: '2-digit',
          });
};

/** Forget every conversation this account holds on this device. */
const forget = (): void => {
    if (!address.value) {
        return;
    }

    clearChat(address.value);
    inbox.forgetLocal();
    pending.value = [];
    opened.value = {};
    back();
};

/* ------------------------------------------------------------- wiring --- */

watch(
    () => inbox.rows.value,
    () => void decrypt(),
);

watch(
    address,
    () => {
        // A different account: its keys and conversations were the last one's.
        opened.value = {};
        peerKeys.value = {};
        keyless.value = {};
        suspectPeers.value = [];
        modes.value = {};
        pendingTexts.value = {};
        view.value = null;
        peer.value = null;
        inbox.viewing.value = null;
        readPending();
        readVerifications();
        void decrypt();
        void inbox.checkPublished();
    },
    { immediate: true },
);

watch(
    () => props.openWith,
    (wanted) => {
        if (!wanted) {
            return;
        }

        emit('opened');

        if (/^0x[0-9a-fA-F]{40}$/.test(wanted)) {
            openThread(wanted);
        }
    },
    { immediate: true },
);

let unsubscribe: (() => void) | null = null;

onMounted(() => {
    inbox.setFocused(true);
    void loadPeople();
    unsubscribe = inbox.onSync(() => void flushPending());
});

onBeforeUnmount(() => {
    inbox.setFocused(false);
    inbox.viewing.value = null;
    unsubscribe?.();

    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }
});
</script>

<template>
    <div class="cw-dm" :class="{ 'cw-dm-wide': wide }">
        <!-- A watched address holds no key, so it can neither read nor write. -->
        <div v-if="!identity" class="cw-dm-hero">
            <span class="cw-dm-hero-mark">
                <MessageCircle :size="30" aria-hidden="true" />
            </span>
            <h2 class="cw-dm-hero-title">{{ t('chatTitle') }}</h2>
            <p class="cw-dm-hero-text">{{ t('chatNoAccount') }}</p>
        </div>

        <template v-else>
            <!-- ---------------------------------------------- the list --- -->
            <section v-show="wide || view === null" class="cw-dm-side">
                <header class="cw-dm-head">
                    <h2 class="cw-dm-title">{{ t('chatTitle') }}</h2>
                    <Loader2
                        v-if="inbox.syncing.value"
                        :size="15"
                        class="cw-spin cw-dm-head-sync"
                        :aria-label="t('chatSyncing')"
                    />
                    <button
                        type="button"
                        class="cw-dm-icon cw-dm-icon-accent"
                        :title="t('chatNew')"
                        :aria-label="t('chatNew')"
                        @click="focusSearch()"
                    >
                        <SquarePen :size="18" aria-hidden="true" />
                    </button>
                </header>

                <label class="cw-dm-search">
                    <Search :size="16" aria-hidden="true" />
                    <input
                        ref="search"
                        v-model="query"
                        type="search"
                        spellcheck="false"
                        autocomplete="off"
                        :placeholder="t('chatSearch')"
                        :aria-label="t('chatSearch')"
                    />
                </label>

                <div
                    v-if="inbox.failure.value && !inbox.proven.value"
                    class="cw-dm-flag cw-dm-flag-bad"
                >
                    <span>{{ inbox.failure.value }}</span>
                    <button
                        type="button"
                        class="cw-dm-flag-action"
                        @click="inbox.pull()"
                    >
                        {{ t('retry') }}
                    </button>
                </div>

                <div v-if="visibleThreads.length > 0" class="cw-dm-threads">
                    <button
                        v-for="entry in visibleThreads"
                        :key="entry.peer"
                        type="button"
                        class="cw-dm-thread"
                        :class="{ 'cw-dm-thread-unread': entry.unread > 0 }"
                        :aria-current="
                            entry.peer === peer && view !== null
                                ? 'true'
                                : undefined
                        "
                        @click="openThread(entry.peer)"
                    >
                        <WalletChatAvatar
                            :address="entry.peer"
                            :name="nameOf(entry.peer)"
                            :src="avatarOf(entry.peer)"
                            :size="48"
                        />
                        <span class="cw-dm-thread-main">
                            <span class="cw-dm-thread-top">
                                <span class="cw-dm-name">{{
                                    nameOf(entry.peer)
                                }}</span>
                                <ShieldCheck
                                    v-if="verifiedPeers[entry.peer]"
                                    :size="14"
                                    class="cw-dm-verified"
                                    :aria-label="t('chatVerifiedShort')"
                                />
                                <span class="cw-dm-time">{{
                                    entry.last
                                        ? listTime(entry.last.row.sentAt)
                                        : t('chatWaiting')
                                }}</span>
                            </span>
                            <span class="cw-dm-thread-bottom">
                                <span class="cw-dm-preview">{{
                                    lastPreview(entry)
                                }}</span>
                                <span
                                    v-if="entry.unread > 0"
                                    class="cw-dm-count"
                                    >{{
                                        entry.unread > 99 ? '99+' : entry.unread
                                    }}</span
                                >
                            </span>
                        </span>
                    </button>
                </div>

                <!-- Nothing yet: say what this is and who can be reached. -->
                <div
                    v-else-if="threads.length === 0 && needle === ''"
                    class="cw-dm-hero cw-dm-hero-compact"
                >
                    <span class="cw-dm-hero-mark">
                        <MessageCircle :size="28" aria-hidden="true" />
                    </span>
                    <h3 class="cw-dm-hero-title">{{ t('chatEmptyTitle') }}</h3>
                    <p class="cw-dm-hero-text">{{ t('chatEmptyBody') }}</p>
                </div>

                <p
                    v-else-if="
                        needle !== '' &&
                        peopleShown.length === 0 &&
                        !addressCandidate &&
                        !peopleLoading
                    "
                    class="cw-dm-quiet"
                >
                    {{ t('chatNothingFound') }}
                </p>

                <div
                    v-if="peopleShown.length > 0 || addressCandidate"
                    class="cw-dm-section"
                >
                    <div class="cw-dm-section-label">
                        {{
                            needle === ''
                                ? t('chatPeopleNew')
                                : t('chatPeopleFound')
                        }}
                    </div>

                    <button
                        v-if="addressCandidate"
                        type="button"
                        class="cw-dm-person"
                        @click="openThread(addressCandidate)"
                    >
                        <WalletChatAvatar
                            :address="addressCandidate"
                            :size="40"
                        />
                        <span class="cw-dm-person-main">
                            <span class="cw-dm-name">{{
                                t('chatWriteTo', {
                                    address: shortAddress(addressCandidate),
                                })
                            }}</span>
                            <span class="cw-dm-sub">{{
                                t('chatWriteToHint')
                            }}</span>
                        </span>
                    </button>

                    <button
                        v-for="person in peopleShown"
                        :key="person.address"
                        type="button"
                        class="cw-dm-person"
                        @click="openThread(person.address)"
                    >
                        <WalletChatAvatar
                            :address="person.address"
                            :name="person.name"
                            :src="person.avatar"
                            :size="40"
                        />
                        <span class="cw-dm-person-main">
                            <span class="cw-dm-name">{{ person.name }}</span>
                            <span class="cw-dm-sub">{{
                                shortAddress(person.address)
                            }}</span>
                        </span>
                        <Lock
                            v-if="person.hasKey"
                            :size="14"
                            class="cw-dm-person-lock"
                            :aria-label="t('chatPersonEncrypts')"
                        />
                    </button>
                </div>

                <p v-if="peopleFailed" class="cw-dm-quiet">
                    {{ t('chatPeopleFailed') }}
                </p>

                <!--
                  The one thing on this screen that is only done because the
                  person asked: publishing a key makes the address encryptable
                  to, and tells the world it uses this chat.
                -->
                <div v-if="inbox.published.value === false" class="cw-dm-offer">
                    <span class="cw-dm-offer-mark">
                        <Lock :size="16" aria-hidden="true" />
                    </span>
                    <span class="cw-dm-offer-main">
                        <span class="cw-dm-offer-title">{{
                            t('chatEncryptOfferTitle')
                        }}</span>
                        <span class="cw-dm-offer-text">{{
                            t('chatEncryptOfferBody')
                        }}</span>
                    </span>
                    <button
                        type="button"
                        class="cw-dm-pill"
                        :disabled="publishing"
                        @click="turnOnEncryption()"
                    >
                        <Loader2
                            v-if="publishing"
                            :size="14"
                            class="cw-spin"
                            aria-hidden="true"
                        />
                        {{ t('chatEncryptOn') }}
                    </button>
                </div>

                <button
                    v-if="threads.length > 0"
                    type="button"
                    class="cw-dm-forget"
                    @click="forget()"
                >
                    <Trash2 :size="13" aria-hidden="true" />
                    {{ t('chatForget') }}
                </button>
            </section>

            <!-- -------------------------------------------- one thread --- -->
            <section
                v-if="peer && view === 'thread'"
                class="cw-dm-main"
                :aria-label="nameOf(peer)"
            >
                <header class="cw-dm-bar">
                    <button
                        v-if="!wide"
                        type="button"
                        class="cw-dm-icon cw-dm-icon-bare"
                        :aria-label="t('back')"
                        @click="back()"
                    >
                        <ArrowLeft :size="20" aria-hidden="true" />
                    </button>
                    <WalletChatAvatar
                        :address="peer"
                        :name="nameOf(peer)"
                        :src="avatarOf(peer)"
                        :size="40"
                    />
                    <div class="cw-dm-bar-who">
                        <div class="cw-dm-bar-name">{{ nameOf(peer) }}</div>
                        <div
                            class="cw-dm-bar-state"
                            :class="
                                mode === 'sealed'
                                    ? 'cw-dm-state-sealed'
                                    : 'cw-dm-state-plain'
                            "
                        >
                            <Lock
                                v-if="mode === 'sealed'"
                                :size="12"
                                aria-hidden="true"
                            />
                            <LockOpen v-else :size="12" aria-hidden="true" />
                            {{
                                mode === 'sealed'
                                    ? t('chatModeSealed')
                                    : t('chatModePlain')
                            }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="cw-dm-icon cw-dm-icon-bare"
                        :title="t('chatVerifyTitle')"
                        :aria-label="t('chatVerifyTitle')"
                        @click="openVerify()"
                    >
                        <ShieldAlert
                            v-if="peerSuspect"
                            :size="19"
                            class="cw-dm-warn"
                            aria-hidden="true"
                        />
                        <ShieldCheck
                            v-else-if="peerVerifiedAt"
                            :size="19"
                            class="cw-dm-verified"
                            aria-hidden="true"
                        />
                        <Shield v-else :size="19" aria-hidden="true" />
                    </button>
                </header>

                <div ref="transcript" class="cw-dm-log" @scroll="onScroll">
                    <!-- A new thread: who this is, and how it travels. -->
                    <div v-if="threadItems.length === 0" class="cw-dm-hello">
                        <WalletChatAvatar
                            :address="peer"
                            :name="nameOf(peer)"
                            :src="avatarOf(peer)"
                            :size="76"
                        />
                        <div class="cw-dm-hello-name">{{ nameOf(peer) }}</div>
                        <div v-if="named(peer)" class="cw-dm-hello-address">
                            {{ shortAddress(peer, 8, 6) }}
                        </div>
                        <p class="cw-dm-hello-text">
                            {{
                                mode === 'sealed'
                                    ? t('chatHelloSealed')
                                    : t('chatHelloPlain')
                            }}
                        </p>
                    </div>

                    <template v-for="item in threadItems" :key="item.key">
                        <div v-if="item.kind === 'day'" class="cw-dm-day">
                            <span>{{ item.label }}</span>
                        </div>
                        <div
                            v-else
                            class="cw-dm-msg"
                            :class="[
                                item.mine ? 'cw-dm-mine' : 'cw-dm-theirs',
                                {
                                    'cw-dm-tail': item.tail,
                                    'cw-dm-waiting': item.waiting,
                                    'cw-dm-broken': item.text === null,
                                },
                            ]"
                        >
                            <div class="cw-dm-bubble">
                                <span class="cw-dm-text">{{
                                    item.text ?? t('chatUnreadable')
                                }}</span>
                                <span class="cw-dm-meta">
                                    <Lock
                                        v-if="item.sealed"
                                        :size="11"
                                        class="cw-dm-meta-sealed"
                                        :aria-label="t('chatSealedMark')"
                                    />
                                    <LockOpen
                                        v-else
                                        :size="11"
                                        :aria-label="t('chatPlainMark')"
                                    />
                                    {{ clock(item.sentAt) }}
                                    <template v-if="item.mine">
                                        <Clock
                                            v-if="item.waiting"
                                            :size="12"
                                            :aria-label="t('chatWaiting')"
                                        />
                                        <Check
                                            v-else
                                            :size="13"
                                            :aria-label="t('chatSentMark')"
                                        />
                                    </template>
                                </span>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="cw-dm-foot">
                    <p v-if="peerSuspect" class="cw-dm-flag cw-dm-flag-bad">
                        <ShieldAlert :size="14" aria-hidden="true" />
                        <span>{{ t('chatKeyChanged') }}</span>
                    </p>
                    <p
                        v-else-if="mode === 'sealed' && keyless[peer]"
                        class="cw-dm-flag"
                    >
                        <Clock :size="14" aria-hidden="true" />
                        <span>{{ t('chatWaitingNote') }}</span>
                    </p>

                    <div v-if="offering" class="cw-dm-offer cw-dm-offer-inline">
                        <span class="cw-dm-offer-mark">
                            <Lock :size="16" aria-hidden="true" />
                        </span>
                        <span class="cw-dm-offer-main">
                            <span class="cw-dm-offer-title">{{
                                t('chatEncryptOfferTitle')
                            }}</span>
                            <span class="cw-dm-offer-text">{{
                                t('chatEncryptOfferBody')
                            }}</span>
                            <span class="cw-dm-offer-actions">
                                <button
                                    type="button"
                                    class="cw-dm-pill"
                                    :disabled="publishing"
                                    @click="turnOnEncryption()"
                                >
                                    <Loader2
                                        v-if="publishing"
                                        :size="14"
                                        class="cw-spin"
                                        aria-hidden="true"
                                    />
                                    {{ t('chatEncryptOn') }}
                                </button>
                                <button
                                    type="button"
                                    class="cw-dm-pill cw-dm-pill-quiet"
                                    @click="offering = false"
                                >
                                    {{ t('chatNotNow') }}
                                </button>
                            </span>
                        </span>
                    </div>

                    <p v-if="error" class="cw-dm-flag cw-dm-flag-bad">
                        <span>{{ error }}</span>
                    </p>

                    <form class="cw-dm-composer" @submit.prevent="send()">
                        <!--
                          The lock is the choice of how this message travels,
                          so it sits where the message is written rather than
                          in a settings screen nobody opens mid-sentence.
                        -->
                        <button
                            type="button"
                            class="cw-dm-lock"
                            :class="{ 'cw-dm-lock-on': mode === 'sealed' }"
                            :aria-pressed="mode === 'sealed'"
                            :title="
                                mode === 'sealed'
                                    ? t('chatLockOff')
                                    : t('chatLockOn')
                            "
                            :aria-label="
                                mode === 'sealed'
                                    ? t('chatLockOff')
                                    : t('chatLockOn')
                            "
                            @click="toggleMode()"
                        >
                            <Lock
                                v-if="mode === 'sealed'"
                                :size="18"
                                aria-hidden="true"
                            />
                            <LockOpen v-else :size="18" aria-hidden="true" />
                        </button>
                        <textarea
                            ref="composer"
                            v-model="draft"
                            class="cw-dm-field"
                            rows="1"
                            maxlength="2000"
                            :placeholder="
                                mode === 'sealed'
                                    ? t('chatPlaceholderSealed')
                                    : t('chatPlaceholderPlain')
                            "
                            :aria-label="t('chatPlaceholderPlain')"
                            @keydown="onKeydown"
                        />
                        <button
                            type="submit"
                            class="cw-dm-send"
                            :disabled="sending || draft.trim() === ''"
                            :title="t('chatSend')"
                            :aria-label="t('chatSend')"
                        >
                            <Loader2
                                v-if="sending"
                                :size="18"
                                class="cw-spin"
                                aria-hidden="true"
                            />
                            <ArrowUp v-else :size="19" aria-hidden="true" />
                        </button>
                    </form>
                </div>
            </section>

            <!-- ---------------------------------------- safety number --- -->
            <!--
              Pinning says "this is the key I have always talked to"; only two
              people reading the same twelve groups to each other can say "and
              it belongs to you".
            -->
            <section
                v-else-if="peer && view === 'verify'"
                class="cw-dm-main cw-dm-verify"
            >
                <header class="cw-dm-bar">
                    <button
                        type="button"
                        class="cw-dm-icon cw-dm-icon-bare"
                        :aria-label="t('back')"
                        @click="back()"
                    >
                        <ArrowLeft :size="20" aria-hidden="true" />
                    </button>
                    <div class="cw-dm-bar-who">
                        <div class="cw-dm-bar-name">
                            {{ t('chatVerifyTitle') }}
                        </div>
                        <div class="cw-dm-bar-state">{{ nameOf(peer) }}</div>
                    </div>
                </header>

                <div class="cw-dm-verify-body">
                    <p class="cw-prose">
                        {{ t('chatVerifyBody', { peer: nameOf(peer) }) }}
                    </p>

                    <div
                        v-if="fingerprintGroups.length > 0"
                        class="cw-dm-verify-card"
                    >
                        <div class="cw-dm-section-label">
                            {{ t('chatVerifyTheirs') }}
                        </div>
                        <div class="cw-dm-verify-grid">
                            <span
                                v-for="(group, index) in fingerprintGroups"
                                :key="index"
                                >{{ group }}</span
                            >
                        </div>
                    </div>
                    <p v-else class="cw-dm-flag">
                        <span>{{ t('chatVerifyNoKey') }}</span>
                    </p>

                    <div class="cw-dm-verify-card">
                        <div class="cw-dm-section-label">
                            {{ t('chatVerifyYours') }}
                        </div>
                        <span class="cw-dm-verify-mine">{{
                            fingerprint || '—'
                        }}</span>
                    </div>

                    <p v-if="peerSuspect" class="cw-dm-flag cw-dm-flag-bad">
                        <ShieldAlert :size="14" aria-hidden="true" />
                        <span>{{ t('chatVerifyChanged') }}</span>
                    </p>

                    <!--
                      What this conversation actually is, stated where somebody
                      is checking it. A static ECDH is not a ratchet and must
                      never be drawn as one.
                    -->
                    <div class="cw-dm-verify-card cw-dm-facts">
                        <div class="cw-kv">
                            <span class="cw-kv-key">{{
                                t('chatVerifyScheme')
                            }}</span>
                            <span class="cw-kv-val">{{
                                t('chatVerifySchemeVal')
                            }}</span>
                        </div>
                        <div class="cw-kv">
                            <span class="cw-kv-key">{{
                                t('chatVerifyKey')
                            }}</span>
                            <span class="cw-kv-val">{{
                                t('chatVerifyKeyVal')
                            }}</span>
                        </div>
                        <div class="cw-kv">
                            <span class="cw-kv-key">{{
                                t('chatVerifySecrecy')
                            }}</span>
                            <span
                                class="cw-kv-val"
                                style="color: var(--cw-pending)"
                                >{{ t('chatVerifySecrecyVal') }}</span
                            >
                        </div>
                        <div class="cw-kv">
                            <span class="cw-kv-key">{{
                                t('chatVerifyPlain')
                            }}</span>
                            <span class="cw-kv-val">{{
                                t('chatVerifyPlainVal')
                            }}</span>
                        </div>
                        <div class="cw-kv">
                            <span class="cw-kv-key">{{
                                t('chatVerifyState')
                            }}</span>
                            <span
                                class="cw-kv-val"
                                :style="{
                                    color: peerVerifiedAt
                                        ? 'var(--cw-ok)'
                                        : 'var(--cw-muted)',
                                }"
                                >{{
                                    peerVerifiedAt
                                        ? t('chatVerifiedOn', {
                                              when: when(peerVerifiedAt),
                                          })
                                        : t('chatVerifyUnchecked')
                                }}</span
                            >
                        </div>
                    </div>

                    <button
                        v-if="fingerprintGroups.length > 0 && !peerVerifiedAt"
                        type="button"
                        class="cw-btn cw-btn-primary"
                        @click="confirmVerified()"
                    >
                        {{ t('chatVerifyMark') }}
                    </button>

                    <p class="cw-prose">{{ t('chatVerifyLocal') }}</p>
                </div>
            </section>

            <!-- Desktop, nothing picked yet. -->
            <section v-else-if="wide" class="cw-dm-main cw-dm-placeholder">
                <span class="cw-dm-hero-mark">
                    <MessageCircle :size="30" aria-hidden="true" />
                </span>
                <p class="cw-dm-hero-text">{{ t('chatPickThread') }}</p>
            </section>
        </template>
    </div>
</template>
