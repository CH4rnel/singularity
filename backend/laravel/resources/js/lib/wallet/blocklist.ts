/**
 * Addresses this device has chosen not to hear from.
 *
 * Both stores require that whatever people write to each other can be blocked
 * from inside the app, and in this wallet there are two such places: the feed
 * and the chat. A block is the reader's decision about their own screen, so it
 * lives on the device rather than on the server — the relay already sees who
 * talks to whom and does not need to learn who is ignoring whom as well — and
 * it applies to every account in this vault, because the person blocking is
 * the same person whichever key is on screen.
 *
 * Blocking hides; it does not report. Reporting a post is
 * `reportPost` in `lib/wallet/social.ts`, and reaches the operators.
 *
 * The pure half is pinned in `tests/Frontend/WalletBlocklistTest.mjs`.
 */

export const BLOCKLIST_KEY = 'cyberia.wallet.blocked.v1';

/** At most this many: a list nobody can scroll is a list nobody can undo. */
export const BLOCKLIST_LIMIT = 500;

const ADDRESS = /^0x[0-9a-fA-F]{40}$/;

/** The one spelling an address is compared in, or null for anything else. */
export const blockKey = (address: string | null | undefined): string | null =>
    typeof address === 'string' && ADDRESS.test(address.trim())
        ? address.trim().toLowerCase()
        : null;

/** What was stored, read back defensively: junk entries are dropped, not trusted. */
export const parseBlocklist = (raw: string | null): string[] => {
    if (raw === null) {
        return [];
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        if (!Array.isArray(parsed)) {
            return [];
        }

        const keys = parsed
            .map((entry) => blockKey(typeof entry === 'string' ? entry : null))
            .filter((entry): entry is string => entry !== null);

        return [...new Set(keys)].slice(-BLOCKLIST_LIMIT);
    } catch {
        return [];
    }
};

export const withBlocked = (
    list: readonly string[],
    address: string,
): string[] => {
    const key = blockKey(address);

    if (key === null || list.includes(key)) {
        return [...list];
    }

    return [...list, key].slice(-BLOCKLIST_LIMIT);
};

export const withoutBlocked = (
    list: readonly string[],
    address: string,
): string[] => {
    const key = blockKey(address);

    return list.filter((entry) => entry !== key);
};

export const isBlocked = (
    list: readonly string[],
    address: string | null | undefined,
): boolean => {
    const key = blockKey(address);

    return key !== null && list.includes(key);
};
