/**
 * The holders' conversation with Lain, kept where the rest of this wallet is
 * kept: on this device.
 *
 * There is no account behind the wallet, so there is nowhere on the server to
 * hang a transcript — and inventing one would mean Cyberia storing what a
 * wallet said, which is exactly what the wallet promises it does not do. The
 * browser holds it, replays the tail of it as context for the next turn, and
 * loses it with the vault.
 *
 * Stored per address: one device can hold one seed but talk from whichever
 * account it later restores, and those are different people's rooms.
 */

const STORAGE_KEY = 'cyberia.wallet.lain.v1';

export type LainTurn = { role: 'user' | 'lain'; text: string };

/** Turns kept on the device. Old ones fall off rather than growing forever. */
export const LAIN_CHAT_MEMORY = 60;

/**
 * Turns replayed to the model. Kept in step with the server's own cap
 * (WalletLainController::CONTEXT_MESSAGES), which rejects anything longer.
 */
export const LAIN_CHAT_CONTEXT = 20;

type Transcripts = Record<string, LainTurn[]>;

const isTurn = (value: unknown): value is LainTurn => {
    const turn = value as LainTurn | null;

    return (
        typeof turn?.text === 'string' &&
        (turn.role === 'user' || turn.role === 'lain')
    );
};

const readAll = (): Transcripts => {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed: unknown = raw ? JSON.parse(raw) : {};

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
            ? (parsed as Transcripts)
            : {};
    } catch {
        return {};
    }
};

export const readLainChat = (address: string): LainTurn[] => {
    const stored = readAll()[address.toLowerCase()];

    return Array.isArray(stored) ? stored.filter(isTurn) : [];
};

/**
 * Store a conversation, keeping less of it rather than failing.
 *
 * A turn can be twelve thousand characters, so sixty of them across a few
 * addresses can reach the origin's storage quota. That must not become an
 * exception in the middle of sending a message — and it especially must not be
 * retried until it takes the vault's own key down with it. So a full disk
 * degrades to a shorter transcript, halving what is kept until it fits, and
 * gives up quietly if even the last exchange will not go.
 */
export const writeLainChat = (
    address: string,
    turns: readonly LainTurn[],
): void => {
    if (typeof window === 'undefined') {
        return;
    }

    const key = address.toLowerCase();
    const others = readAll();

    for (let keep = LAIN_CHAT_MEMORY; keep >= 2; keep = Math.floor(keep / 2)) {
        try {
            window.localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify({ ...others, [key]: turns.slice(-keep) }),
            );

            return;
        } catch {
            // Quota. Try again with a shorter tail.
        }
    }
};

/** Forget one address's conversation, leaving every other one alone. */
export const clearLainChat = (address: string): void => {
    if (typeof window === 'undefined') {
        return;
    }

    const transcripts = readAll();
    delete transcripts[address.toLowerCase()];

    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(transcripts));
};

/** Forget every conversation. Goes with the vault, like the token list does. */
export const forgetLainChats = (): void => {
    if (typeof window !== 'undefined') {
        window.localStorage.removeItem(STORAGE_KEY);
    }
};
