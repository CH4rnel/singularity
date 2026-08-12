/**
 * Everything the extension keeps, and where.
 *
 * `local` survives a browser restart and holds only what is safe there: the
 * sealed vault, which origins were granted what, and the relay profile.
 * `session` is memory that outlives an evicted service worker and nothing
 * else; the unlocked key lives there and dies with the browser.
 */

export const DEFAULTS = {
    /** The sealed vault, or null when the extension has never been set up. */
    vault: null,
    /** origin -> { accounts, grantedAt, lastSeen } */
    grants: {},
    /** Which chain the popup and connected sites are on. */
    chainId: null,
    /** Minutes of no use before the key is dropped. */
    autoLockMinutes: 15,
    relay: {
        mode: 'direct',
        host: '127.0.0.1',
        port: '1080',
        routeBrowser: false,
        blockWebrtc: false,
        circuit: 0,
    },
};

export const readLocal = async (keys) => {
    const wanted = keys ?? Object.keys(DEFAULTS);
    const stored = await chrome.storage.local.get(wanted);

    return Object.fromEntries(
        wanted.map((key) => [key, stored[key] ?? structuredClone(DEFAULTS[key])]),
    );
};

export const writeLocal = (values) => chrome.storage.local.set(values);

export const readSession = async (key) => (await chrome.storage.session.get(key))[key] ?? null;

export const writeSession = (key, value) => chrome.storage.session.set({ [key]: value });

export const clearSession = (key) => chrome.storage.session.remove(key);
