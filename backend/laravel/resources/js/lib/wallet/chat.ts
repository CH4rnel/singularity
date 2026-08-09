import { verifyChatKey } from '@/lib/wallet/chatCrypto';
import type {
    ChatEnvelope,
    ChatKeyRecord,
    ChatMeta,
} from '@/lib/wallet/chatCrypto';

/**
 * The relay, and what this device keeps of what passes through it.
 *
 * Cyberia's part in a conversation is a queue: it takes an envelope addressed
 * to an EVM address, holds it until the other wallet asks, and forgets it after
 * a while. It cannot read one, and the encryption in `chatCrypto.ts` is what
 * makes that a fact rather than a promise.
 *
 * Two things follow, and both are the reason this file is separate from the
 * screens that use it.
 *
 * **A key from the relay is checked before it is used.** `lookupChatKey`
 * verifies the signature over every record it receives and refuses anything
 * that does not recover to the address it asked about, so a compromised server
 * cannot substitute its own key. On top of that, a key is pinned the first
 * time it is seen: a *changed* key is reported rather than silently accepted,
 * because that is what an attempted interception looks like from here.
 *
 * **What is cached on the device is still ciphertext.** The transcript is kept
 * exactly as the relay held it and is decrypted into memory when the wallet is
 * unlocked. A locked wallet, or a stolen laptop, holds no readable messages —
 * the same standard the vault already sets for keys, applied to what those keys
 * are for.
 */

const STORAGE_KEY = 'cyberia.wallet.chat.v1';

/** Envelopes kept per account. Older ones fall off rather than growing forever. */
const MAX_STORED = 400;

/** One row of the relay's queue: the sealed message plus what carries it. */
export type ChatRow = ChatMeta &
    ChatEnvelope & {
        /** The relay's own ordering. Transport only — never in the tag. */
        seq: number;
    };

const csrfToken = (): string => {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

/**
 * A chat request, with the server's own words on failure.
 *
 * Everything here is either a proof of address or an opaque envelope, so a
 * failure is something the user has to be told about honestly — "could not
 * reach the relay" and "that signature expired" are different problems with
 * different answers.
 */
const call = async <T>(url: string, init: RequestInit = {}): Promise<T> => {
    const method = (init.method ?? 'GET').toUpperCase();

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        method,
        headers: {
            Accept: 'application/json',
            ...(method === 'GET'
                ? {}
                : {
                      'Content-Type': 'application/json',
                      'X-XSRF-TOKEN': csrfToken(),
                  }),
            ...(init.headers as Record<string, string> | undefined),
        },
    });

    const data = (await response.json().catch(() => ({}))) as {
        message?: string;
    } & Record<string, unknown>;

    if (!response.ok) {
        const failure = new Error(
            data.message ?? 'The chat relay is unreachable right now.',
        ) as Error & { status: number };
        failure.status = response.status;

        throw failure;
    }

    return data as T;
};

/* ---------------------------------------------------------------- relay --- */

export const requestChatNonce = (
    address: string,
): Promise<{ message: string; expiresIn: number }> =>
    call('/api/wallet/chat/nonce', {
        method: 'POST',
        body: JSON.stringify({ address }),
    });

/** Prove this address to the relay, so it will accept and hand over its mail. */
export const proveChatAddress = (
    address: string,
    signature: string,
): Promise<unknown> =>
    call('/api/wallet/chat/verify', {
        method: 'POST',
        body: JSON.stringify({ address, signature }),
    });

/**
 * Publish a messaging key.
 *
 * Needs no session: the record carries a signature over a statement naming the
 * address, which proves authorship better than a cookie does. The relay is
 * expected to check it too — but the browser never relies on that, since the
 * relay is exactly the party this signature is defending against.
 */
export const publishChatKey = (record: ChatKeyRecord): Promise<unknown> =>
    call('/api/wallet/chat/keys', {
        method: 'POST',
        body: JSON.stringify(record),
    });

/**
 * The messaging key an address published, or null if it has never opened chat.
 *
 * A record that fails verification is treated as no key at all: refusing to
 * encrypt is the safe failure, and encrypting to a key the address never
 * signed for is the unsafe one.
 */
export const lookupChatKey = async (
    address: string,
): Promise<ChatKeyRecord | null> => {
    let record: ChatKeyRecord;

    try {
        record = await call<ChatKeyRecord>(
            `/api/wallet/chat/keys/${address.toLowerCase()}`,
        );
    } catch (failure) {
        if ((failure as { status?: number }).status === 404) {
            return null;
        }

        throw failure;
    }

    if (
        record.address.toLowerCase() !== address.toLowerCase() ||
        !verifyChatKey(record)
    ) {
        throw new Error(
            'The relay returned a chat key this address never signed for.',
        );
    }

    return record;
};

export const sendChatEnvelope = (
    row: ChatMeta & ChatEnvelope,
): Promise<unknown> =>
    call('/api/wallet/chat/messages', {
        method: 'POST',
        body: JSON.stringify(row),
    });

/** Everything addressed to or sent by this account since a cursor. */
export const fetchChatEnvelopes = (
    since: number,
): Promise<{ messages: ChatRow[]; cursor: number }> =>
    call(`/api/wallet/chat/messages?since=${since}`);

/* ---------------------------------------------------------------- store --- */

type Pinned = { publicKey: string; issuedAt: string };

type OwnerState = {
    /** Peer address → the key first seen for it. */
    peers: Record<string, Pinned>;
    rows: ChatRow[];
    cursor: number;
    /** Peer address → the highest `seq` this device has shown the user. */
    read: Record<string, number>;
};

type ChatStore = Record<string, OwnerState>;

const emptyOwner = (): OwnerState => ({
    peers: {},
    rows: [],
    cursor: 0,
    read: {},
});

const readStore = (): ChatStore => {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed: unknown = raw ? JSON.parse(raw) : {};

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
            ? (parsed as ChatStore)
            : {};
    } catch {
        return {};
    }
};

/**
 * Persist one account's state, keeping less of it rather than failing.
 *
 * A full origin quota must not become an exception in the middle of sending a
 * message, so the transcript is halved until it fits and gives up quietly if
 * even the last exchange will not go — the same trade the Lain room makes.
 */
const writeOwner = (owner: string, state: OwnerState): void => {
    if (typeof window === 'undefined') {
        return;
    }

    const key = owner.toLowerCase();
    const store = readStore();

    for (let keep = MAX_STORED; keep >= 2; keep = Math.floor(keep / 2)) {
        try {
            window.localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify({
                    ...store,
                    [key]: { ...state, rows: state.rows.slice(-keep) },
                }),
            );

            return;
        } catch {
            // Quota. Try again with a shorter transcript.
        }
    }
};

export const readChatState = (owner: string): OwnerState => ({
    ...emptyOwner(),
    ...readStore()[owner.toLowerCase()],
});

/**
 * Merge in what the relay just handed over.
 *
 * Deduplicated by the relay's own sequence, so a poll that overlaps a previous
 * one cannot double a message, and sorted by it so the thread reads in the
 * order the relay accepted things.
 */
export const storeChatRows = (
    owner: string,
    incoming: ChatRow[],
): OwnerState => {
    const state = readChatState(owner);
    const bySeq = new Map(state.rows.map((row) => [row.seq, row]));

    for (const row of incoming) {
        bySeq.set(row.seq, row);
    }

    const rows = [...bySeq.values()]
        .sort((a, b) => a.seq - b.seq)
        .slice(-MAX_STORED);

    const next: OwnerState = {
        ...state,
        rows,
        cursor: Math.max(state.cursor, ...incoming.map((row) => row.seq), 0),
    };

    writeOwner(owner, next);

    return next;
};

/**
 * Remember the key an address was first seen with.
 *
 * Returns what happened, because "this address's key changed" is not a detail
 * to swallow: it is either a wallet restored somewhere new or someone trying
 * to sit in the middle, and only the two people talking can tell which.
 */
export const pinChatKey = (
    owner: string,
    peer: string,
    record: ChatKeyRecord,
): 'new' | 'same' | 'changed' => {
    const state = readChatState(owner);
    const key = peer.toLowerCase();
    const known = state.peers[key];

    if (
        known &&
        known.publicKey.toLowerCase() === record.publicKey.toLowerCase()
    ) {
        return 'same';
    }

    writeOwner(owner, {
        ...state,
        peers: {
            ...state.peers,
            [key]: {
                publicKey: record.publicKey,
                issuedAt: record.issuedAt,
            },
        },
    });

    return known ? 'changed' : 'new';
};

/**
 * How many messages are waiting, without opening any of them.
 *
 * The badge on the portfolio needs a number, not a transcript, and the numbers
 * it needs — who sent it and how far this device has read — are the metadata
 * around the envelope rather than what is inside. So the count works from the
 * cache alone: no key, no decryption, and nothing that has to be recomputed
 * when the wallet is locked.
 */
export const unreadChatCount = (owner: string): number => {
    const state = readChatState(owner);
    const self = owner.toLowerCase();

    return state.rows.filter((row) => {
        if (row.from.toLowerCase() === self) {
            return false;
        }

        return row.seq > (state.read[row.from.toLowerCase()] ?? 0);
    }).length;
};

export const markChatRead = (
    owner: string,
    peer: string,
    seq: number,
): void => {
    const state = readChatState(owner);

    writeOwner(owner, {
        ...state,
        read: { ...state.read, [peer.toLowerCase()]: seq },
    });
};

/** Forget one account's messages, leaving every other account's alone. */
export const clearChat = (owner: string): void => {
    if (typeof window === 'undefined') {
        return;
    }

    const store = readStore();
    delete store[owner.toLowerCase()];

    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(store));
};

/**
 * Forget every conversation on this device.
 *
 * Goes with the vault: who this wallet talked to is as much a record of the
 * person holding it as the network list is, and deleting the keys has to mean
 * the device keeps nothing.
 */
export const forgetWalletChats = (): void => {
    if (typeof window !== 'undefined') {
        window.localStorage.removeItem(STORAGE_KEY);
    }
};
