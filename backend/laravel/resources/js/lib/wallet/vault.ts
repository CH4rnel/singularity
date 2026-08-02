import { Mnemonic, getBytes, randomBytes } from 'ethers';

/**
 * Encrypted-at-rest storage for the one seed phrase behind every chain.
 *
 * The wallet is non-custodial in the strict sense: the phrase is generated in
 * the browser, sealed with a key derived from the user's password, and kept in
 * this device's localStorage. It is never sent to Laravel, never put in an
 * Inertia prop, never written to a log, and never rendered except in the
 * deliberate backup flow the user has to confirm. Losing the phrase and the
 * password means losing the funds — that is the trade for custody.
 *
 * AES-256-GCM over a PBKDF2-SHA-256 key; the tag makes a wrong password fail
 * loudly instead of yielding garbage that would derive plausible addresses.
 */

const STORAGE_KEY = 'cyberia.wallet.vault.v1';

const PBKDF2_ITERATIONS = 310_000;

export type VaultRecord = {
    version: 1;
    kdf: 'PBKDF2-SHA-256';
    iterations: number;
    salt: string;
    iv: string;
    ciphertext: string;
    createdAt: string;
};

export type VaultStorage = {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
};

const memoryStorage = (): VaultStorage => {
    const store = new Map<string, string>();

    return {
        getItem: (key) => store.get(key) ?? null,
        setItem: (key, value) => void store.set(key, value),
        removeItem: (key) => void store.delete(key),
    };
};

const fallbackStorage = memoryStorage();

/** localStorage in the browser; an in-memory stand-in during SSR and tests. */
export const defaultStorage = (): VaultStorage =>
    typeof localStorage !== 'undefined' ? localStorage : fallbackStorage;

const toBase64 = (bytes: Uint8Array): string =>
    btoa(String.fromCharCode(...bytes));

const fromBase64 = (value: string): Uint8Array<ArrayBuffer> =>
    Uint8Array.from(atob(value), (char) => char.charCodeAt(0));

const deriveKey = async (
    password: string,
    salt: Uint8Array<ArrayBuffer>,
    iterations: number,
): Promise<CryptoKey> => {
    const material = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(password),
        'PBKDF2',
        false,
        ['deriveKey'],
    );

    return crypto.subtle.deriveKey(
        { name: 'PBKDF2', salt, iterations, hash: 'SHA-256' },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
};

/** A fresh 12- or 24-word BIP-39 phrase from the platform CSPRNG. */
export const createMnemonic = (words: 12 | 24 = 12): string =>
    Mnemonic.fromEntropy(randomBytes(words === 24 ? 32 : 16)).phrase;

export const normalizeMnemonic = (phrase: string): string =>
    phrase.trim().toLowerCase().split(/\s+/).join(' ');

export const isValidMnemonic = (phrase: string): boolean =>
    Mnemonic.isValidMnemonic(normalizeMnemonic(phrase));

/**
 * BIP-39 seed for a phrase — the single root every chain adapter derives
 * from. No BIP-39 passphrase (25th word): one secret to back up, and the
 * vault password already protects the phrase on this device.
 */
export const seedFromMnemonic = (phrase: string): Uint8Array =>
    getBytes(Mnemonic.fromPhrase(normalizeMnemonic(phrase)).computeSeed());

export const hasVault = (storage: VaultStorage = defaultStorage()): boolean =>
    storage.getItem(STORAGE_KEY) !== null;

export const readVault = (
    storage: VaultStorage = defaultStorage(),
): VaultRecord | null => {
    const raw = storage.getItem(STORAGE_KEY);

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw) as VaultRecord;
    } catch {
        return null;
    }
};

export const saveVault = async (
    phrase: string,
    password: string,
    storage: VaultStorage = defaultStorage(),
): Promise<VaultRecord> => {
    const normalized = normalizeMnemonic(phrase);

    if (!Mnemonic.isValidMnemonic(normalized)) {
        throw new Error('Not a valid BIP-39 seed phrase');
    }

    if (password.length < 8) {
        throw new Error('Wallet password must be at least 8 characters');
    }

    const salt = crypto.getRandomValues(new Uint8Array(16));
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const key = await deriveKey(password, salt, PBKDF2_ITERATIONS);
    const ciphertext = new Uint8Array(
        await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv },
            key,
            new TextEncoder().encode(normalized),
        ),
    );

    const record: VaultRecord = {
        version: 1,
        kdf: 'PBKDF2-SHA-256',
        iterations: PBKDF2_ITERATIONS,
        salt: toBase64(salt),
        iv: toBase64(iv),
        ciphertext: toBase64(ciphertext),
        createdAt: new Date().toISOString(),
    };

    storage.setItem(STORAGE_KEY, JSON.stringify(record));

    return record;
};

/** Decrypt the stored phrase. Throws on a wrong password (GCM tag mismatch). */
export const openVault = async (
    password: string,
    storage: VaultStorage = defaultStorage(),
): Promise<string> => {
    const record = readVault(storage);

    if (!record) {
        throw new Error('No wallet on this device');
    }

    const key = await deriveKey(
        password,
        fromBase64(record.salt),
        record.iterations,
    );

    let plaintext: ArrayBuffer;

    try {
        plaintext = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: fromBase64(record.iv) },
            key,
            fromBase64(record.ciphertext),
        );
    } catch {
        throw new Error('Wrong wallet password');
    }

    return new TextDecoder().decode(plaintext);
};

/**
 * Delete the encrypted phrase from this device. The wallet itself survives
 * only in whatever backup the user made — that is the point of the warning
 * the UI puts in front of this.
 */
export const forgetVault = (storage: VaultStorage = defaultStorage()): void =>
    storage.removeItem(STORAGE_KEY);
