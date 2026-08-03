'use strict';

/**
 * Shared configuration for the desktop shell.
 *
 * The shell renders the live Cyberia site (a server-driven Inertia app), so
 * everything it needs is derived from one URL. Override it with CYBERIA_APP_URL
 * or `--url=https://staging.example` when running a non-production build.
 */

const DEFAULT_APP_URL = 'https://cyberia.church';

/** Custom scheme used for deep links: cyberia://feed -> <appUrl>/feed. */
const PROTOCOL = 'cyberia';

/** Cookies and localStorage survive restarts only inside a named partition. */
const PARTITION = 'persist:cyberia';

/**
 * Hosts allowed to take over the main window. Everything else is handed to the
 * system browser so a stray link can never repaint the app frame with a page
 * that looks like Cyberia but isn't.
 */
const NAVIGABLE_HOSTS = [
    'accounts.google.com',
    'api.twitter.com',
    'api.x.com',
    'github.com',
    'mobile.twitter.com',
    'twitter.com',
    'x.com',
];

/**
 * Schemes the shell refuses to hand to the OS. Wallet and messenger schemes
 * (`wc:`, `metamask:`, `tg:`) must keep working, so this is a blocklist of the
 * ones that execute or read something instead of opening an app.
 */
const BLOCKED_EXTERNAL_SCHEMES = ['blob:', 'data:', 'file:', 'javascript:', 'vbscript:'];

function readUrlArgument(argv) {
    const flag = argv.find((argument) => argument.startsWith('--url='));

    return flag ? flag.slice('--url='.length) : null;
}

function resolveAppUrl(env, argv) {
    const candidate = readUrlArgument(argv) || env.CYBERIA_APP_URL || DEFAULT_APP_URL;

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

/** The app host plus its subdomains (explorer, swap, bridge, launchpad sites). */
function isAppHost(host, appHost) {
    return host === appHost || host.endsWith(`.${appHost}`);
}

function isNavigable(target, appHost) {
    let url;

    try {
        url = new URL(target);
    } catch {
        return false;
    }

    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return false;
    }

    return isAppHost(url.hostname, appHost) || NAVIGABLE_HOSTS.includes(url.hostname);
}

/** Whether a URL from remote content may be handed to the system browser. */
function isExternallyOpenable(target) {
    if (typeof target !== 'string' || target.length > 2048) {
        return false;
    }

    let url;

    try {
        url = new URL(target);
    } catch {
        return false;
    }

    return !BLOCKED_EXTERNAL_SCHEMES.includes(url.protocol);
}

module.exports = {
    BLOCKED_EXTERNAL_SCHEMES,
    DEFAULT_APP_URL,
    NAVIGABLE_HOSTS,
    PARTITION,
    PROTOCOL,
    isAppHost,
    isExternallyOpenable,
    isNavigable,
    resolveAppUrl,
};
