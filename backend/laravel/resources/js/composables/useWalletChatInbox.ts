import { computed, effectScope, ref, shallowRef, watch } from 'vue';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { useWalletBlocklist } from '@/composables/useWalletBlocklist';
import {
    chatChallenge,
    chatChallengeNonce,
    chatKeyStatement,
    chatMessageId,
    fetchChatEnvelopes,
    fetchChatPeople,
    lookupChatKey,
    markChatRead,
    proveChatAddress,
    publishChatKey,
    readChatState,
    requestChatNonce,
    sendChatNote,
    storeChatRows,
} from '@/lib/wallet';
import type { ChatMeta, ChatPerson, ChatRelayRow, ChatRow } from '@/lib/wallet';

/**
 * The wallet's mailbox, shared by every screen that needs to know about it.
 *
 * Until this existed the mailbox lived inside the chat screen, which meant
 * mail was only ever fetched while somebody was looking at the chat — and the
 * one thing a person not looking at it needs is to be told that something
 * arrived. So the mailbox moved out: the page keeps it polling while the
 * wallet is unlocked, the tab bar reads its unread count, and the chat screen
 * draws from the same rows instead of keeping a copy of its own.
 *
 * **Checking mail signs a challenge without asking.** Reading a mailbox means
 * proving the address to the relay, and a badge that waited for somebody to
 * hold a button first would be a badge that is never current. What makes that
 * safe is what gets signed: the wallet composes the challenge itself
 * (`chatChallenge`) and signs it only when the relay's text is exactly that —
 * a statement scoped to this mailbox that moves nothing and opens nothing
 * else. A relay that asked for any other text would get no signature.
 *
 * **Plain messages are sealed on arrival.** The relay hands over a message its
 * sender did not encrypt as text; it is sealed to this account's own key
 * before it is stored, so the cache stays ciphertext whichever way a message
 * travelled (see lib/wallet/chat.ts).
 *
 * Publishing a messaging key is still something the person does: it is what
 * makes an address encryptable to, and it tells the world the address uses
 * this chat. The mailbox only checks whether it has been done.
 */

/** Something that arrived, with the text when it travelled readable. */
export type ChatArrival = {
    row: ChatRow;
    /** The message itself for a plain one; null for an encrypted envelope. */
    preview: string | null;
};

/** Polling while the chat is on screen, and while it is not. */
const FOREGROUND_MS = 5_000;
const BACKGROUND_MS = 25_000;

/** A failed proof is not retried on every beat; a minute is plenty. */
const PROOF_BACKOFF_MS = 60_000;

/** Pages fetched in one sync: enough to catch up a restored wallet. */
const MAX_PAGES = 5;

const wallet = shallowRef<MultiWallet | null>(null);

const rows = ref<ChatRow[]>([]);
const readMarks = ref<Record<string, number>>({});
const proven = ref(false);
const syncing = ref(false);
const failure = ref<string | null>(null);

/** Whether this account's own key is in the directory; null until checked. */
const published = ref<boolean | null>(null);

/** Names and faces for addresses, from the site's own people. */
const directory = ref<Record<string, ChatPerson>>({});

/** The thread on screen, if any — arriving there is arriving read. */
const viewing = ref<string | null>(null);

const focused = ref(false);

let scope: ReturnType<typeof effectScope> | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;
let running = false;
let proving: Promise<boolean> | null = null;
let proofFailedAt = 0;
let pulling: Promise<ChatArrival[]> | null = null;

const arrivalListeners = new Set<(arrivals: ChatArrival[]) => void>();
const syncListeners = new Set<() => void>();

const identity = computed(() => wallet.value?.chatIdentity() ?? null);

/**
 * Whose mail this is. The identity while the vault is open; while it is
 * locked, the same account picked the same way, so the badge can still count
 * what is cached without any key at all.
 */
const owner = computed<string | null>(() => {
    if (identity.value) {
        return identity.value.address;
    }

    const accounts = wallet.value?.accounts.value ?? [];
    const account =
        accounts.find(
            (entry) => entry.chain === 'cyberia' && entry.family === 'evm',
        ) ?? accounts.find((entry) => entry.family === 'evm');

    return account ? account.address.toLowerCase() : null;
});

/** Mail from an address blocked on this device neither counts nor rings. */
const blocklist = useWalletBlocklist();

const peerOf = (row: ChatRow, self: string): string =>
    row.from.toLowerCase() === self
        ? row.to.toLowerCase()
        : row.from.toLowerCase();

/** Incoming rows past each thread's read mark. Metadata only — no key. */
const unreadByPeer = computed<Record<string, number>>(() => {
    const self = owner.value;
    const counts: Record<string, number> = {};

    if (!self) {
        return counts;
    }

    for (const row of rows.value) {
        const from = row.from.toLowerCase();

        if (
            from !== self &&
            !blocklist.isBlocked(from) &&
            row.seq > (readMarks.value[from] ?? 0)
        ) {
            counts[from] = (counts[from] ?? 0) + 1;
        }
    }

    return counts;
});

const unread = computed(() =>
    Object.values(unreadByPeer.value).reduce((sum, count) => sum + count, 0),
);

const load = (): void => {
    const self = owner.value;
    const state = self ? readChatState(self) : null;

    rows.value = state?.rows ?? [];
    readMarks.value = state?.read ?? {};
};

/* ---------------------------------------------------------------- proof --- */

/**
 * Prove this address to the relay, once, with the challenge this wallet wrote.
 *
 * Single-flight, because the page's beat and the chat screen can both ask at
 * the same moment and one signature answers both.
 */
const prove = async (): Promise<boolean> => {
    const me = identity.value;
    const signer = wallet.value;

    if (!me || !signer) {
        return false;
    }

    if (proven.value) {
        return true;
    }

    if (Date.now() - proofFailedAt < PROOF_BACKOFF_MS) {
        return false;
    }

    proving ??= (async () => {
        try {
            const { message } = await requestChatNonce(me.address);
            const nonce = chatChallengeNonce(message, me.address);

            if (nonce === null) {
                throw new Error(
                    'The relay asked this wallet to sign something other than its chat challenge, so nothing was signed.',
                );
            }

            await proveChatAddress(
                me.address,
                await signer.signMessage(
                    me.chain,
                    chatChallenge(me.address, nonce),
                ),
            );

            // Still the same account? A switch mid-proof proved the old one.
            proven.value = identity.value?.address === me.address;
            failure.value = null;

            return proven.value;
        } catch (error) {
            proofFailedAt = Date.now();
            failure.value =
                error instanceof Error ? error.message : String(error);

            return false;
        } finally {
            proving = null;
        }
    })();

    return proving;
};

/* ----------------------------------------------------------------- sync --- */

/**
 * A relay row as it is kept: a plain message sealed to this account's own
 * key, an envelope as it came.
 */
const toStored = async (
    row: ChatRelayRow,
): Promise<{ row: ChatRow; preview: string | null }> => {
    const meta: ChatMeta = {
        id: row.id,
        from: row.from,
        to: row.to,
        sentAt: row.sentAt,
    };

    if (!row.plain) {
        return {
            row: { ...meta, seq: row.seq, iv: row.iv, body: row.body },
            preview: null,
        };
    }

    const me = identity.value;
    const sealer = wallet.value;

    if (!me || !sealer) {
        throw new Error('The wallet is locked');
    }

    const envelope = await sealer.chatSeal(me.publicKey, meta, row.text);

    return {
        row: { ...meta, seq: row.seq, ...envelope, plain: true },
        preview: row.text,
    };
};

/**
 * Fold relay rows into one account's cache, and into the screen's copy of it
 * while that account is still the one on screen.
 *
 * Only rows this account sent or received are kept: a relay answer that
 * carried anybody else's mail — a proof from another tab, an account switched
 * mid-request — must not be filed here, or its ids would move this mailbox's
 * cursor past mail it has not read. What is new is judged by message id, not by
 * the relay's number, which has been renumbered once already.
 */
const keep = async (
    self: string,
    incoming: ChatRelayRow[],
): Promise<ChatArrival[]> => {
    const mine = incoming.filter(
        (row) =>
            row.from.toLowerCase() === self || row.to.toLowerCase() === self,
    );

    if (mine.length === 0 || owner.value !== self) {
        return [];
    }

    const known = new Set(rows.value.map((row) => row.id));
    const converted = await Promise.all(mine.map(toStored));

    if (owner.value !== self) {
        return [];
    }

    rows.value = storeChatRows(
        self,
        converted.map((entry) => entry.row),
    ).rows;

    return converted.filter(
        (entry) =>
            !known.has(entry.row.id) &&
            entry.row.from.toLowerCase() !== self &&
            !blocklist.isBlocked(entry.row.from),
    );
};

/**
 * Ask the relay for whatever arrived since the last time.
 *
 * Returns what is new and from somebody else, and tells every listener. A
 * proof that aged out (403) closes the mailbox until the next beat proves it
 * again, rather than failing every request in between.
 */
const pull = async (): Promise<ChatArrival[]> => {
    if (!identity.value || !owner.value) {
        return [];
    }

    pulling ??= (async () => {
        syncing.value = true;

        try {
            if (!(await prove())) {
                return [];
            }

            const self = owner.value as string;
            const arrivals: ChatArrival[] = [];

            for (let page = 0; page < MAX_PAGES; page++) {
                const cursor = readChatState(self).cursor;
                const batch = await fetchChatEnvelopes(cursor, self);

                // A relay that answered for another mailbox (one that does not
                // know the `address` parameter yet) is answered by proving
                // this one again, not by filing its mail here.
                if (batch.address && batch.address.toLowerCase() !== self) {
                    proven.value = false;

                    break;
                }

                arrivals.push(...(await keep(self, batch.messages)));

                if (
                    owner.value !== self ||
                    batch.messages.length === 0 ||
                    batch.cursor <= cursor
                ) {
                    break;
                }
            }

            if (viewing.value) {
                markRead(viewing.value);
            }

            if (arrivals.length > 0) {
                arrivalListeners.forEach((listener) => listener(arrivals));
            }

            return arrivals;
        } catch (error) {
            if ((error as { status?: number }).status === 403) {
                proven.value = false;
            } else {
                failure.value =
                    error instanceof Error ? error.message : String(error);
            }

            return [];
        } finally {
            syncing.value = false;
            pulling = null;
            syncListeners.forEach((listener) => listener());
        }
    })();

    return pulling;
};

/* -------------------------------------------------------------- polling --- */

const schedule = (): void => {
    if (!running) {
        return;
    }

    timer = setTimeout(
        async () => {
            timer = null;
            await pull();
            schedule();
        },
        focused.value ? FOREGROUND_MS : BACKGROUND_MS,
    );
};

/** Keep checking for mail while the page is open. */
const start = (): void => {
    if (running) {
        return;
    }

    running = true;
    void pull().finally(schedule);
};

const stop = (): void => {
    running = false;

    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
};

/** The chat is on screen: check often. Off it: now and then. */
const setFocused = (value: boolean): void => {
    if (focused.value === value) {
        return;
    }

    focused.value = value;

    if (running && timer !== null) {
        clearTimeout(timer);
        timer = null;
        void pull().finally(schedule);
    }
};

/* ------------------------------------------------------------ reading --- */

/** Everything loaded from this correspondent counts as read. */
const markRead = (peer: string): void => {
    const self = owner.value;
    const who = peer.toLowerCase();

    if (!self) {
        return;
    }

    const last = rows.value
        .filter((row) => peerOf(row, self) === who)
        .reduce((max, row) => Math.max(max, row.seq), 0);

    if (last > (readMarks.value[who] ?? 0)) {
        markChatRead(self, who, last);
        readMarks.value = { ...readMarks.value, [who]: last };
    }
};

/** A row the screen sent itself, kept the same way as one that arrived. */
const keepSent = async (row: ChatRelayRow): Promise<ChatRow> => {
    await keep(row.from.toLowerCase(), [row]);

    return rows.value.find((entry) => entry.id === row.id) as ChatRow;
};

/** Send without encryption, and keep what was sent. */
const sendPlain = async (to: string, text: string): Promise<ChatRow> => {
    const me = identity.value;

    if (!me) {
        throw new Error('The wallet is locked');
    }

    if (!(await prove())) {
        throw new Error(failure.value ?? 'The chat relay is unreachable.');
    }

    const meta: ChatMeta = {
        id: chatMessageId(),
        from: me.address,
        to: to.toLowerCase(),
        sentAt: new Date().toISOString(),
    };

    try {
        const { message } = await sendChatNote({ ...meta, text });

        return await keepSent(message);
    } catch (error) {
        if ((error as { status?: number }).status === 403) {
            proven.value = false;
        }

        throw error;
    }
};

/* ------------------------------------------------------------ directory --- */

/** Whether this account's own key is published, checked once per account. */
const checkPublished = async (): Promise<boolean> => {
    const me = identity.value;

    if (!me) {
        return false;
    }

    if (published.value !== null) {
        return published.value;
    }

    try {
        const record = await lookupChatKey(me.address);

        published.value =
            record !== null &&
            record.publicKey.toLowerCase() === me.publicKey.toLowerCase();
    } catch {
        return false;
    }

    return published.value;
};

/**
 * Publish this account's messaging key — encryption, switched on.
 *
 * Called from a control the person pressed, which is the consent: the
 * statement it signs says what it does, and nothing about it moves funds.
 */
const publish = async (): Promise<void> => {
    const me = identity.value;
    const signer = wallet.value;

    if (!me || !signer) {
        throw new Error('The wallet is locked');
    }

    const issuedAt = new Date().toISOString();

    await publishChatKey({
        address: me.address,
        publicKey: me.publicKey,
        issuedAt,
        signature: await signer.signMessage(
            me.chain,
            chatKeyStatement(me.address, me.publicKey, issuedAt),
        ),
    });

    published.value = true;
};

let resolving: Promise<void> | null = null;
const wanted = new Set<string>();

/**
 * Learn the names behind addresses, a batch at a time.
 *
 * An address that is nobody's profile is remembered as such (a person row
 * without a name is never invented), so it is not asked about again.
 */
const resolve = async (addresses: string[]): Promise<void> => {
    for (const address of addresses) {
        const key = address.toLowerCase();

        if (!(key in directory.value)) {
            wanted.add(key);
        }
    }

    if (wanted.size === 0) {
        return;
    }

    await resolving;

    if (wanted.size === 0) {
        return;
    }

    const batch = [...wanted].slice(0, 60);
    batch.forEach((address) => wanted.delete(address));

    resolving = (async () => {
        try {
            const people = await fetchChatPeople('', batch);
            const next = { ...directory.value };

            for (const address of batch) {
                next[address] = people.find(
                    (person) => person.address.toLowerCase() === address,
                ) ?? {
                    name: '',
                    address,
                    url: null,
                    avatar: null,
                    hasKey: false,
                };
            }

            directory.value = next;
        } catch {
            // Names are a nicety. The thread still works with the address.
        } finally {
            resolving = null;
        }
    })();

    await resolving;
};

/** Remember people the directory has already handed over. */
const remember = (people: ChatPerson[]): void => {
    directory.value = {
        ...directory.value,
        ...Object.fromEntries(
            people.map((person) => [person.address.toLowerCase(), person]),
        ),
    };
};

/* --------------------------------------------------------------- wiring --- */

/**
 * Point the mailbox at this page's wallet. Idempotent: the page attaches it,
 * and the chat screen may again with the same wallet.
 */
const attach = (next: MultiWallet): void => {
    if (wallet.value === next) {
        return;
    }

    scope?.stop();
    wallet.value = next;
    scope = effectScope(true);

    scope.run(() => {
        watch(
            owner,
            () => {
                // A different account is a different mailbox: its proof and
                // its key state belonged to the account before it.
                proven.value = false;
                published.value = null;
                proofFailedAt = 0;
                failure.value = null;
                load();

                if (running) {
                    void pull();
                }
            },
            { immediate: true },
        );
    });
};

const onArrival = (
    listener: (arrivals: ChatArrival[]) => void,
): (() => void) => {
    arrivalListeners.add(listener);

    return () => arrivalListeners.delete(listener);
};

const onSync = (listener: () => void): (() => void) => {
    syncListeners.add(listener);

    return () => syncListeners.delete(listener);
};

/** Forget this account's conversations on this device. */
const forgetLocal = (): void => {
    rows.value = [];
    readMarks.value = {};
};

export const useWalletChatInbox = () => ({
    attach,
    identity,
    owner,
    rows,
    readMarks,
    proven,
    syncing,
    failure,
    published,
    directory,
    viewing,
    unread,
    unreadByPeer,
    start,
    stop,
    setFocused,
    pull,
    prove,
    markRead,
    keepSent,
    sendPlain,
    checkPublished,
    publish,
    resolve,
    remember,
    onArrival,
    onSync,
    forgetLocal,
    reload: load,
});

export type WalletChatInbox = ReturnType<typeof useWalletChatInbox>;
