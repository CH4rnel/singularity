/**
 * Routing the traffic this wallet causes.
 *
 * The two engines offer genuinely different powers here, and pretending
 * otherwise would mean lying to one set of users:
 *
 *  - **Chromium** gives an extension `proxy.settings`, which is one setting for
 *    the *entire browser*. Turning the relay on routes every tab. There is no
 *    per-extension route in MV3, so the popup says that instead of implying a
 *    private tunnel.
 *  - **Gecko** gives `proxy.onRequest`, which is asked about each request
 *    individually. So on Firefox the default is what the design actually wanted:
 *    the wallet's own RPC, token and price traffic goes through the relay and
 *    nothing else does — the rest of the browser is untouched unless you ask for
 *    it.
 *
 * Either way the relay fails closed: a request that should go through a daemon
 * that is not listening fails, rather than quietly leaving by your own line.
 */
import { CHAINS, PRICES_URL } from '../shared/chains.js';

/**
 * The four routes, and where each one expects to find a daemon. Tor and I2P are
 * their projects' default ports; nothing is bundled and nothing is started — the
 * extension only points at what is already running.
 */
export const RELAY_MODES = {
    direct: { label: 'DIRECT', sub: 'device network', scheme: null },
    socks5: { label: 'SOCKS5', sub: 'local daemon', scheme: 'socks5', host: null, port: null },
    tor: { label: 'TOR', sub: '3 hops · .onion', scheme: 'socks5', host: '127.0.0.1', port: 9050 },
    i2p: { label: 'I2P', sub: 'garlic · in-net', scheme: 'http', host: '127.0.0.1', port: 4444 },
};

/** Where a profile points, or null for a direct line. Pure, and tested. */
export const endpointFor = (relay) => {
    const mode = RELAY_MODES[relay?.mode ?? 'direct'];

    if (!mode || !mode.scheme) {
        return null;
    }

    const host = mode.host ?? String(relay?.host ?? '').trim();
    const port = Number(mode.port ?? relay?.port);

    if (!host || !Number.isInteger(port) || port < 1 || port > 65_535) {
        return null;
    }

    return { scheme: mode.scheme, host, port };
};

/**
 * The `chrome.proxy` value for a profile — Chromium's browser-wide form.
 * Pure, so the one setting that can take a browser offline is pinned by tests.
 */
export const proxyConfigFor = (relay) => {
    const endpoint = endpointFor(relay);

    if (!endpoint) {
        return null;
    }

    return {
        mode: 'fixed_servers',
        rules: {
            singleProxy: endpoint,
            // Localhost stays direct: a relay is not a reason to lose the node
            // running on the same machine as the browser.
            bypassList: ['<local>'],
        },
    };
};

/** Gecko's per-request form of the same endpoint. */
export const proxyInfoFor = (relay) => {
    const endpoint = endpointFor(relay);

    if (!endpoint) {
        return { type: 'direct' };
    }

    return endpoint.scheme === 'socks5'
        ? {
              type: 'socks',
              host: endpoint.host,
              port: endpoint.port,
              // Resolving names through the relay is the point: a DNS lookup
              // that leaves directly announces every host the wallet talks to.
              proxyDNS: true,
          }
        : { type: 'http', host: endpoint.host, port: endpoint.port };
};

/** Everything the wallet itself talks to, and therefore always routes. */
export const walletEndpoints = () => [
    ...CHAINS.map((chain) => chain.rpc),
    ...CHAINS.map((chain) => chain.tokens).filter(Boolean),
    PRICES_URL,
];

const sameOrigin = (url, endpoints) => {
    let origin;

    try {
        origin = new URL(url).origin;
    } catch {
        return false;
    }

    return endpoints.some((endpoint) => {
        try {
            return new URL(endpoint).origin === origin;
        } catch {
            return false;
        }
    });
};

/**
 * Does this request go through the relay?
 *
 * Localhost never does — a relay must not hide a node running beside the
 * browser — and on the wallet-only scope nothing but the wallet's own endpoints
 * does either. Pure, because this is the rule that decides what leaks.
 */
export const routesThrough = (relay, url, endpoints = walletEndpoints()) => {
    if (!endpointFor(relay)) {
        return false;
    }

    let parsed;

    try {
        parsed = new URL(url);
    } catch {
        return false;
    }

    if (parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1' || parsed.hostname === '::1') {
        return false;
    }

    return relay?.routeBrowser === true || sameOrigin(url, endpoints);
};

/**
 * 'browser' where a relay takes the whole browser with it, 'wallet' where the
 * engine can be asked per request.
 *
 * Read off the extension's own origin rather than off `chrome.proxy`: that
 * namespace does not exist until the optional permission is granted, and the
 * popup has to describe the relay honestly *before* asking for anything.
 */
export const relayScope = () =>
    chrome.runtime.getURL('').startsWith('moz-extension://') ? 'wallet' : 'browser';

/** The optional permissions a profile needs before it can be applied. */
export const permissionsFor = (relay) => {
    const wanted = [];

    if (endpointFor(relay)) {
        wanted.push('proxy');
    }

    if (relay?.blockWebrtc) {
        wanted.push('privacy');
    }

    return wanted;
};

const has = async (permissions) =>
    permissions.length === 0 || chrome.permissions.contains({ permissions });

/* ------------------------------------------------------------------ gecko --- */

/**
 * The live profile, kept in a module variable because `proxy.onRequest` is
 * asked about every request and reading storage each time would put the wallet
 * in the path of every page load.
 */
let current = null;
let listening = false;

const handleRequest = (request) => (routesThrough(current, request.url) ? proxyInfoFor(current) : { type: 'direct' });

const listen = async () => {
    if (listening || relayScope() !== 'wallet') {
        return;
    }

    // Without the permission the API object is not even there; the popup asks
    // for it in the click that turns the relay on.
    if (!(await has(['proxy']))) {
        return;
    }

    chrome.proxy.onRequest.addListener(handleRequest, { urls: ['<all_urls>'] });
    listening = true;
};

/* ----------------------------------------------------------------- shared --- */

const setWebrtc = async (block) => {
    if (!(await has(['privacy']))) {
        return;
    }

    const network = chrome.privacy?.network;

    // Chromium hands out a policy string; Gecko has no such setting and answers
    // the same question by switching peer connections off.
    if (network?.webRTCIPHandlingPolicy?.set) {
        await network.webRTCIPHandlingPolicy.set({
            value: block ? 'disable_non_proxied_udp' : 'default',
        });
    } else if (network?.peerConnectionEnabled?.set) {
        await network.peerConnectionEnabled.set({ value: !block });
    }
};

/**
 * Put a relay profile into effect.
 *
 * Returns what happened rather than throwing: a missing permission is an answer
 * the popup shows ("grant it to route"), not a failure — and it is the popup, in
 * a user gesture, that can ask for it.
 */
export const applyRelay = async (relay) => {
    current = relay ?? null;

    const scope = relayScope();
    // On Chromium a relay nobody asked to apply browser-wide needs no proxy
    // permission, because nothing will be routed; the WebRTC switch still needs
    // its own either way.
    const wanted = permissionsFor(relay).filter(
        (permission) => !(permission === 'proxy' && scope === 'browser' && !relay?.routeBrowser),
    );

    if (!(await has(wanted))) {
        return { applied: false, reason: 'permission', needed: wanted, scope };
    }

    try {
        if (scope === 'wallet') {
            await listen();
        } else if (relay?.routeBrowser && proxyConfigFor(relay)) {
            await chrome.proxy.settings.set({ value: proxyConfigFor(relay), scope: 'regular' });
        } else if (await has(['proxy'])) {
            await chrome.proxy.settings.clear({ scope: 'regular' });
        }

        await setWebrtc(Boolean(relay?.blockWebrtc));
    } catch (error) {
        return { applied: false, reason: 'error', message: String(error?.message ?? error), scope };
    }

    return {
        applied: true,
        scope,
        // What is actually being routed right now, which is what the popup
        // reports back — "on" and "routing something" are not the same claim.
        routed: Boolean(endpointFor(relay)) && (scope === 'wallet' || Boolean(relay?.routeBrowser)),
    };
};
