import { walletChain } from '@/lib/wallet/chains';
import type { WalletChainId } from '@/lib/wallet/chains';

/**
 * What a "wallet account" is once there is more than one of them.
 *
 * Four kinds, and the difference between them is exactly what the wallet can
 * do with each — and what the user's one backup actually covers:
 *
 *  - `seed`   — a numbered account off the vault's phrase. It exists on *every*
 *    network at once, the way account 0 always has, and it can spend. This is
 *    the only kind the backup screen's phrase restores.
 *  - `phrase` — a second BIP-39 phrase imported whole. Also every network at
 *    once, also spendable, but it is its own root: backing up the vault's
 *    phrase does nothing for it.
 *  - `key`    — one private key pasted in from elsewhere. It exists on one chain
 *    only, because a key is a key of one curve and one address format, and it
 *    can spend.
 *  - `watch`  — an address and nothing else. One chain, no key, no spending, and
 *    the UI has to keep saying so rather than offering a send button that
 *    fails at the end.
 *
 * The records are the only thing stored. Addresses for a `seed` or `phrase`
 * account are re-derived on every unlock, so this list never becomes the
 * authority on where the money is — the phrase is.
 */

export type WalletAccountKind = 'seed' | 'phrase' | 'key' | 'watch';

type BaseRecord = {
    id: string;
    /** What the user renamed it to, or null to keep the generated name. */
    label: string | null;
};

export type SeedAccountRecord = BaseRecord & {
    kind: 'seed';
    /** BIP-44 account number. 0 is the wallet as it existed before accounts. */
    index: number;
};

export type PhraseAccountRecord = BaseRecord & {
    kind: 'phrase';
    /** A whole second root. Never leaves the sealed vault. */
    phrase: string;
    /** BIP-44 account number within *that* phrase's tree. */
    index: number;
};

export type KeyAccountRecord = BaseRecord & {
    kind: 'key';
    chain: WalletChainId;
    /** The key in its own chain's encoding. Never leaves the sealed vault. */
    secret: string;
    /** Cached so listing accounts never has to touch the secret. */
    address: string;
};

export type WatchAccountRecord = BaseRecord & {
    kind: 'watch';
    chain: WalletChainId;
    address: string;
};

export type WalletAccountRecord =
    | SeedAccountRecord
    | PhraseAccountRecord
    | KeyAccountRecord
    | WatchAccountRecord;

/** Whether the vault's own backup phrase restores this account. */
export const accountInSeedBackup = (record: WalletAccountRecord): boolean =>
    record.kind === 'seed';

/** The id of the account every vault starts with, and never loses. */
export const PRIMARY_ACCOUNT_ID = 'seed-0';

export const seedAccountId = (index: number): string => `seed-${index}`;

/**
 * A fresh vault holds one account: the one this wallet has always shown. Its
 * id and index are fixed so that upgrading an old vault is a rename of nothing
 * — the same phrase keeps producing the same addresses under the same id.
 */
export const defaultAccountRecords = (): WalletAccountRecord[] => [
    { id: PRIMARY_ACCOUNT_ID, kind: 'seed', index: 0, label: null },
];

/**
 * The next free BIP-44 account number. Gaps left by deleting an account are
 * reused: the account at index 1 is the account at index 1 whatever else the
 * list holds, and skipping to 5 would only make the phrase harder to restore
 * elsewhere.
 */
export const nextSeedIndex = (
    records: readonly WalletAccountRecord[],
): number => {
    const taken = new Set(
        records
            .filter(
                (record): record is SeedAccountRecord => record.kind === 'seed',
            )
            .map((record) => record.index),
    );

    let index = 0;

    while (taken.has(index)) {
        index++;
    }

    return index;
};

/** A stable id for an imported account, unique against what is already there. */
export const importedAccountId = (
    kind: 'key' | 'watch',
    chain: WalletChainId,
    address: string,
): string => `${kind}-${chain}-${address.toLowerCase()}`;

/**
 * A stable id for an imported phrase.
 *
 * Keyed by the address it derives rather than by anything in the phrase
 * itself, so the same phrase pasted twice is recognised as the same account
 * without the id ever being a function of the secret.
 */
export const phraseAccountId = (address: string): string =>
    `phrase-${address.toLowerCase()}`;

/**
 * The chain an account is confined to, or null when it spans all of them.
 * A phrase is every network at once; an imported key or address is exactly one.
 */
export const accountChain = (
    record: WalletAccountRecord,
): WalletChainId | null =>
    record.kind === 'seed' || record.kind === 'phrase' ? null : record.chain;

/** Whether this account can sign anything at all. */
export const accountCanSpend = (record: WalletAccountRecord): boolean =>
    record.kind !== 'watch';

/**
 * The name to show. A renamed account keeps its name; the rest are numbered by
 * their derivation index, which is the number that would restore them anywhere
 * else, rather than by their position in a list the user can reorder.
 */
export const accountName = (
    record: WalletAccountRecord,
    fallback: (record: WalletAccountRecord) => string,
): string => record.label?.trim() || fallback(record);

/** How a translator is handed to the helpers below. */
type Translate = (
    key: string,
    vars?: Record<string, string | number>,
) => string;

/**
 * The same name in every list that shows accounts — the switcher in the header,
 * the portfolio's chip and the accounts screen. Three copies of this drifted
 * apart once already, one of them naming a chain by its id rather than its
 * label.
 */
export const accountDisplayName = (
    record: WalletAccountRecord,
    t: Translate,
): string =>
    accountName(record, (entry) => {
        if (entry.kind === 'seed') {
            return entry.index === 0
                ? t('accountPrimaryName')
                : t('accountSeedName', { index: entry.index + 1 });
        }

        if (entry.kind === 'phrase') {
            return t('accountPhraseName');
        }

        const chain = walletChain(entry.chain).label;

        return entry.kind === 'key'
            ? t('accountKeyName', { chain })
            : t('accountWatchName', { chain });
    });

/** Where an account came from, in three words — never where it can spend. */
export const accountKindLabel = (
    record: WalletAccountRecord,
    t: Translate,
): string =>
    t(
        {
            seed: 'accountKindSeed',
            phrase: 'accountKindPhrase',
            key: 'accountKindKey',
            watch: 'accountKindWatch',
        }[record.kind],
    );
