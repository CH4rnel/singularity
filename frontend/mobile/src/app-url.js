'use strict';

/**
 * Single source of truth for the site the mobile shell renders.
 *
 * The shell has no bundle of its own: it points a native WebView at the live
 * Cyberia site. Override with CYBERIA_APP_URL to build a staging or LAN app
 * (`CYBERIA_APP_URL=http://192.168.1.10:8000 npm run sync:android`).
 */

const DEFAULT_APP_URL = 'https://cyberia.church';

/** Hosts that may take over the WebView; anything else opens in the browser. */
const EXTRA_NAVIGABLE_HOSTS = [
    'accounts.google.com',
    'api.twitter.com',
    'api.x.com',
    'twitter.com',
    'x.com',
];

function resolveAppUrl(env = process.env) {
    const candidate = env.CYBERIA_APP_URL || DEFAULT_APP_URL;

    try {
        const url = new URL(candidate);

        if (url.protocol !== 'https:' && url.protocol !== 'http:') {
            return DEFAULT_APP_URL;
        }

        return url.toString().replace(/\/$/, '');
    } catch {
        return DEFAULT_APP_URL;
    }
}

/**
 * `allowNavigation` entries for the resolved app URL: the host itself, its
 * subdomains, and the OAuth providers the site redirects through.
 */
function allowNavigation(appUrl) {
    const { hostname } = new URL(appUrl);

    return [hostname, `*.${hostname}`, ...EXTRA_NAVIGABLE_HOSTS];
}

module.exports = { DEFAULT_APP_URL, EXTRA_NAVIGABLE_HOSTS, allowNavigation, resolveAppUrl };
