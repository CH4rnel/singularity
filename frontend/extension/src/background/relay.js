/**
 * Routing the traffic this wallet causes.
 *
 * Be plain about what a browser extension can and cannot do here, because the
 * difference is the whole security story: MV3 gives an extension one proxy
 * setting for the **entire browser**, not a private route for its own
 * requests. So turning the relay on routes every tab as well, and the popup
 * says exactly that instead of implying a wallet-only tunnel.
 *
 * `fixed_servers` fails closed by design: when the daemon is not listening,
 * requests fail rather than falling back to the direct line — which is the
 * behaviour worth having, and the reason the relay is never applied silently.
 */

/**
 * The four routes, and where each one expects to find a daemon. Tor and I2P
 * are their projects' default ports; nothing is bundled and nothing is
 * started — the extension only points at what is already running.
 */
export const RELAY_MODES = {
    direct: { label: 'DIRECT', sub: 'device network', scheme: null },
    socks5: { label: 'SOCKS5', sub: 'local daemon', scheme: 'socks5', host: null, port: null },
    tor: { label: 'TOR', sub: '3 hops · .onion', scheme: 'socks5', host: '127.0.0.1', port: 9050 },
    i2p: { label: 'I2P', sub: 'garlic · in-net', scheme: 'http', host: '127.0.0.1', port: 4444 },
};

/**
 * The `chrome.proxy` value for a relay profile, or null for a direct line.
 * Pure, so the one setting that can take a browser offline is pinned by tests.
 */
export const proxyConfigFor = (relay) => {
    const mode = RELAY_MODES[relay?.mode ?? 'direct'];

    if (!mode || !mode.scheme) {
        return null;
    }

    const host = mode.host ?? String(relay?.host ?? '').trim();
    const port = Number(mode.port ?? relay?.port);

    if (!host || !Number.isInteger(port) || port < 1 || port > 65_535) {
        return null;
    }

    return {
        mode: 'fixed_servers',
        rules: {
            singleProxy: { scheme: mode.scheme, host, port },
            // Localhost stays direct: a relay is not a reason to lose the node
            // running on the same machine as the browser.
            bypassList: ['<local>'],
        },
    };
};

/** The optional permissions a profile needs before it can be applied. */
export const permissionsFor = (relay) => {
    const wanted = [];

    if (proxyConfigFor(relay)) {
        wanted.push('proxy');
    }

    if (relay?.blockWebrtc) {
        wanted.push('privacy');
    }

    return wanted;
};

const hasPermissions = async (permissions) =>
    permissions.length === 0 || chrome.permissions.contains({ permissions });

/**
 * Put a relay profile into effect.
 *
 * Returns what actually happened rather than throwing: a missing permission is
 * an answer the popup shows ("grant it to route"), not a failure — and it is
 * the popup, in a user gesture, that can ask for it.
 */
export const applyRelay = async (relay) => {
    const config = relay?.routeBrowser ? proxyConfigFor(relay) : null;
    const needed = relay?.routeBrowser ? permissionsFor(relay) : [];

    if (!(await hasPermissions(needed))) {
        return { applied: false, reason: 'permission', needed };
    }

    try {
        if (config) {
            await chrome.proxy.settings.set({ value: config, scope: 'regular' });
        } else if (await chrome.permissions.contains({ permissions: ['proxy'] })) {
            await chrome.proxy.settings.clear({ scope: 'regular' });
        }

        if (await chrome.permissions.contains({ permissions: ['privacy'] })) {
            await chrome.privacy.network.webRTCIPHandlingPolicy.set({
                value: relay?.blockWebrtc ? 'disable_non_proxied_udp' : 'default',
            });
        }
    } catch (error) {
        return { applied: false, reason: 'error', message: String(error?.message ?? error) };
    }

    return { applied: true, routed: Boolean(config) };
};
