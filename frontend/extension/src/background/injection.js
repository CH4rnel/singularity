/**
 * Where the provider appears — and, mostly, where it does not.
 *
 * There is no `<all_urls>` content script here. The provider is registered at
 * runtime for the origins you granted and nowhere else, so a random page
 * cannot even ask whether a wallet exists, which is one of the cheapest
 * fingerprints on the web.
 *
 * Two scripts per origin: `inpage.js` runs in the page's own world because an
 * EIP-1193 provider has to be reachable as `window.ethereum`, and `content.js`
 * runs isolated because only it may hold a port to the service worker. They
 * pass messages; the page's world never touches an extension API.
 */
import { grantedOrigins, matchPattern } from '../shared/origins.js';

const INPAGE_ID = 'cyberia-inpage';
const BRIDGE_ID = 'cyberia-bridge';

const scriptsFor = (matches) => [
    {
        id: INPAGE_ID,
        js: ['inpage.js'],
        matches,
        runAt: 'document_start',
        world: 'MAIN',
        allFrames: false,
    },
    {
        id: BRIDGE_ID,
        js: ['content.js'],
        matches,
        runAt: 'document_start',
        world: 'ISOLATED',
        allFrames: false,
    },
];

/**
 * Make the registered scripts match the grants exactly.
 *
 * Unregister-then-register rather than update: a revoked origin has to stop
 * being injected on the next page load, and the simplest way to be sure of
 * that is to never carry the old list forward.
 */
export const syncInjection = async (grants) => {
    const matches = grantedOrigins(grants).map(matchPattern).filter(Boolean);

    const registered = await chrome.scripting.getRegisteredContentScripts();
    const ours = registered.filter((script) => script.id === INPAGE_ID || script.id === BRIDGE_ID);

    if (ours.length > 0) {
        await chrome.scripting.unregisterContentScripts({ ids: ours.map((script) => script.id) });
    }

    if (matches.length === 0) {
        return { injected: 0 };
    }

    await chrome.scripting.registerContentScripts(scriptsFor(matches));

    return { injected: matches.length };
};
