/**
 * The encrypted vault.
 *
 * Same shape and same parameters as the wallet on the site
 * (`resources/js/lib/wallet/vault.ts`): AES-256-GCM under a PBKDF2-SHA-256 key,
 * a fresh salt per vault and a fresh IV per write. Sharing the format is not a
 * convenience — it means one audit covers both surfaces, and a phrase moved
 * from one to the other is protected the same way in each.
 *
 * What is sealed is a document, not a bare phrase: the phrase, the accounts
 * derived from it and which one is active. An account added in the popup must
 * be no more readable at rest than the phrase it came from.
 */

const PBKDF2_ITERATIONS = 310_000;

const encoder = new TextEncoder();
const decoder = new TextDecoder();

const toBase64 = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)));

const fromBase64 = (text) =>
    Uint8Array.from(atob(text), (character) => character.charCodeAt(0));

const deriveKey = async (password, salt, iterations) => {
    const material = await crypto.subtle.importKey(
        'raw',
        encoder.encode(password),
        'PBKDF2',
        false,
        ['deriveKey'],
    );

    return crypto.subtle.deriveKey(
        { name: 'PBKDF2', salt, iterations, hash: 'SHA-256' },
        material,
        { name: 'AES-GCM', length: 256 },
        // Extractable, because an unlocked session hands this key to
        // `chrome.storage.session` (see `exportKey`) rather than re-deriving it
        // — 310k PBKDF2 rounds on every service-worker restart would be felt.
        true,
        ['encrypt', 'decrypt'],
    );
};

const seal = async (document, key, salt, iterations) => {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const data = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv },
        key,
        encoder.encode(JSON.stringify(document)),
    );

    return {
        version: 2,
        kdf: 'PBKDF2-SHA-256',
        iterations,
        salt: toBase64(salt),
        iv: toBase64(iv),
        data: toBase64(data),
    };
};

/** A brand new vault around `document`, sealed under `password`. */
export const createVault = async (document, password) => {
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const key = await deriveKey(password, salt, PBKDF2_ITERATIONS);

    return { record: await seal(document, key, salt, PBKDF2_ITERATIONS), key, salt };
};

/**
 * Open a vault.
 *
 * Returns the document together with a `reseal` closure over the key, so that
 * adding an account or renaming one never asks for the password a second time
 * — and never has to keep the password around to avoid asking.
 *
 * A wrong password fails on the GCM tag, which is the whole point of it: there
 * is no separate check to get wrong, and nothing decrypts halfway.
 */
export const openVault = async (record, password) => {
    if (!record || typeof record !== 'object') {
        throw new Error('no vault');
    }

    const iterations = Number(record.iterations) || PBKDF2_ITERATIONS;
    const salt = fromBase64(record.salt);
    const key = await deriveKey(password, salt, iterations);

    let plain;

    try {
        plain = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: fromBase64(record.iv) },
            key,
            fromBase64(record.data),
        );
    } catch {
        throw new Error('wrong password');
    }

    return {
        document: JSON.parse(decoder.decode(plain)),
        key,
        reseal: (next) => seal(next, key, salt, iterations),
    };
};

/**
 * The unlocked key, in a form `chrome.storage.session` can hold.
 *
 * Session storage lives in memory and is cleared when the browser closes, and
 * unlike a variable in the service worker it survives the worker being evicted
 * — which Chrome does after 30 seconds of quiet. Without this the vault would
 * appear to lock itself at random, and people would learn to retype their
 * password without reading why they were asked.
 */
export const exportKey = async (key) => {
    const raw = await crypto.subtle.exportKey('raw', key);

    return toBase64(raw);
};

export const importKey = async (encoded) =>
    crypto.subtle.importKey('raw', fromBase64(encoded), { name: 'AES-GCM' }, true, [
        'encrypt',
        'decrypt',
    ]);

/**
 * Open a vault with a key that is already in hand — the unlocked session path,
 * where the password was checked minutes ago and re-deriving it would only
 * make the wallet slower, not safer.
 */
export const openWithKey = async (record, key) => {
    const plain = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: fromBase64(record.iv) },
        key,
        fromBase64(record.data),
    );

    return JSON.parse(decoder.decode(plain));
};

/** Re-seal a document with a key that came back from session storage. */
export const sealWith = async (document, key, record) =>
    seal(document, key, fromBase64(record.salt), Number(record.iterations) || PBKDF2_ITERATIONS);

export const VAULT_ITERATIONS = PBKDF2_ITERATIONS;
