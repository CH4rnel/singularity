'use strict';

/**
 * Shared configuration for the desktop shell.
 *
 * The shell renders the live Cyberia site (a server-driven Inertia app), so
 * everything it needs is derived from one URL. Override it with CYBERIA_APP_URL
 * or `--url=https://staging.example` when running a non-production build.
 *
 * The app is the Cyberia wallet first: it opens on the wallet rather than on
 * the site home, and the rest of the site stays one link away inside it.
 * Override the landing route with CYBERIA_APP_PATH or `--path=/swap`.
 */

const DEFAULT_APP_URL = 'https://cyberia.church';

const DEFAULT_APP_PATH = '/wallet';

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

/** Proxy schemes Chromium understands, keyed by what a proxy URL may spell. */
const PROXY_SCHEMES = {
    http: 'http',
    https: 'https',
    socks: 'socks5',
    socks4: 'socks4',
    socks5: 'socks5',
};

/** Values that mean "ignore every proxy and connect straight out". */
const DIRECT_VALUES = new Set(['direct', 'none', 'off', '0', 'false']);

/** Hosts that must never go through a proxy, even when NO_PROXY is unset. */
const DEFAULT_PROXY_BYPASS = ['localhost', '127.0.0.1', '::1', '<local>'];

/** What a stored proxy setting can say. `system` is "nobody has chosen". */
const PROXY_MODES = ['system', 'direct', 'manual'];

function readFlag(argv, prefix) {
    const flag = argv.find((argument) => argument.startsWith(prefix));

    return flag ? flag.slice(prefix.length) : null;
}

function readUrlArgument(argv) {
    return readFlag(argv, '--url=');
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

/**
 * The route the shell lands on, as a same-origin absolute path.
 *
 * A full URL or a protocol-relative `//host` would quietly move the whole app
 * to another origin, so anything that is not a plain path falls back to the
 * wallet rather than being followed.
 */
function resolveAppPath(env, argv) {
    const candidate = readFlag(argv, '--path=') ?? env.CYBERIA_APP_PATH ?? DEFAULT_APP_PATH;

    if (typeof candidate !== 'string') {
        return DEFAULT_APP_PATH;
    }

    const raw = candidate.trim();

    if (!raw.startsWith('/') || raw.startsWith('//')) {
        return DEFAULT_APP_PATH;
    }

    try {
        // Resolved against a throwaway origin so `/a/../b` normalises and
        // anything outside the path grammar throws instead of being passed on.
        const { pathname, search, hash } = new URL(raw, 'https://placeholder.invalid');
        // Trim a trailing slash, but never the one that *is* the path: "/"
        // with a query has to stay "/?x", not "?x".
        const route = pathname === '/' ? '/' : pathname.replace(/\/$/, '');

        return `${route}${search}${hash}`;
    } catch {
        return DEFAULT_APP_PATH;
    }
}

/** The exact URL the window opens on: the app origin plus its landing route. */
function resolveStartUrl(env, argv) {
    return `${resolveAppUrl(env, argv)}${resolveAppPath(env, argv)}`;
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

/**
 * Proxy resolution.
 *
 * Chromium does not read `http_proxy`/`https_proxy` on a desktop that publishes
 * its own proxy settings (GNOME, Cinnamon, KDE): it asks the desktop instead, so
 * a shell that tunnels fine with curl can still die with
 * ERR_PROXY_CONNECTION_FAILED against a stale system entry. The shell therefore
 * resolves the proxy itself and pins it onto the session.
 */

function pickEnv(env, keys) {
    for (const key of keys) {
        const value = env[key];

        if (typeof value === 'string' && value.trim() !== '') {
            return value.trim();
        }
    }

    return null;
}

/** `127.0.0.1:10808` / `socks://host:1080` -> a Chromium proxy server string. */
function parseProxyServer(value) {
    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    const raw = value.trim();
    const hasScheme = /^[a-z][a-z0-9+.-]*:\/\//i.test(raw);

    let url;

    try {
        url = new URL(hasScheme ? raw : `http://${raw}`);
    } catch {
        return null;
    }

    const scheme = PROXY_SCHEMES[url.protocol.slice(0, -1).toLowerCase()];

    if (!scheme || !url.hostname) {
        return null;
    }

    // `url.host` keeps the port and drops any user:password@ — Chromium proxy
    // rules cannot carry credentials anyway and would read them as a hostname.
    return `${scheme}://${url.host}`;
}

/** NO_PROXY (`.foo.com, 10.0.0.0/8`) -> Chromium bypass rules. */
function parseProxyBypass(value) {
    if (typeof value !== 'string' || value.trim() === '') {
        return DEFAULT_PROXY_BYPASS.join(',');
    }

    const entries = value
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean)
        .map((entry) => (entry.startsWith('.') ? `*${entry}` : entry));

    if (entries.includes('*')) {
        return '*';
    }

    return [...new Set([...entries, ...DEFAULT_PROXY_BYPASS])].join(',');
}

/**
 * Whatever is on disk (or arrived from the settings window) as a setting the
 * shell can act on: `{ mode: 'system' | 'direct' | 'manual', server }`.
 *
 * A manual entry that Chromium could not use is not kept as a broken proxy —
 * it falls back to `system`, the same place the shell starts from when nobody
 * has chosen anything. Callers that need to *tell the user* their entry was
 * unusable check `parseProxyServer` themselves; this one only has to be safe.
 */
function normalizeProxySetting(value) {
    if (!value || typeof value !== 'object') {
        return { mode: 'system', server: '' };
    }

    const mode = typeof value.mode === 'string' ? value.mode.trim().toLowerCase() : '';

    if (!PROXY_MODES.includes(mode) || mode === 'system') {
        return { mode: 'system', server: '' };
    }

    if (mode === 'direct') {
        return { mode: 'direct', server: '' };
    }

    const server = parseProxyServer(value.server);

    return server ? { mode: 'manual', server } : { mode: 'system', server: '' };
}

/** One value (a flag, or CYBERIA_PROXY) -> a proxy configuration or `null`. */
function proxyFromValue(value, bypass) {
    if (DIRECT_VALUES.has(value.toLowerCase())) {
        return { mode: 'direct' };
    }

    const server = parseProxyServer(value);

    return server ? { proxyRules: server, proxyBypassRules: bypass } : null;
}

/**
 * Resolves the proxy for the shell session, and says where it came from.
 *
 * Order, most explicit first:
 *
 * 1. `--no-proxy` / `--proxy=<url>` — this run was launched with an
 *    instruction, and it holds for this run.
 * 2. The setting saved in the app's own proxy window. It outranks the
 *    environment because it is the last thing a human chose *about this app*,
 *    and because for someone who starts Cyberia from an icon it is the only
 *    lever that exists — an ambient `http_proxy` they never typed must not
 *    quietly win over the one they did.
 * 3. `CYBERIA_PROXY`, then `https_proxy` / `http_proxy` / `all_proxy`.
 * 4. Nothing set — Chromium keeps using the system configuration.
 *
 * A value nobody can use (`nonsense://x`) resolves to `null` like an unset one,
 * which is reported as `system`: the shell never pins a proxy it invented.
 */
function resolveProxyDecision(env, argv, saved = null) {
    const bypass = parseProxyBypass(pickEnv(env, ['no_proxy', 'NO_PROXY']));

    const decide = (source, proxy) => (proxy ? { source, proxy } : { source: 'system', proxy: null });

    if (argv.includes('--no-proxy')) {
        return { source: 'flag', proxy: { mode: 'direct' } };
    }

    const flag = readFlag(argv, '--proxy=');

    if (flag !== null) {
        return decide('flag', proxyFromValue(flag, bypass));
    }

    const setting = normalizeProxySetting(saved);

    if (setting.mode === 'direct') {
        return { source: 'saved', proxy: { mode: 'direct' } };
    }

    if (setting.mode === 'manual') {
        return { source: 'saved', proxy: { proxyRules: setting.server, proxyBypassRules: bypass } };
    }

    const configured = pickEnv(env, ['CYBERIA_PROXY']);

    if (configured !== null) {
        return decide('env', proxyFromValue(configured, bypass));
    }

    const fallback = parseProxyServer(pickEnv(env, ['all_proxy', 'ALL_PROXY']));
    const http = parseProxyServer(pickEnv(env, ['http_proxy', 'HTTP_PROXY'])) ?? fallback;
    const https = parseProxyServer(pickEnv(env, ['https_proxy', 'HTTPS_PROXY'])) ?? fallback;

    if (!http && !https) {
        return { source: 'system', proxy: null };
    }

    // One server for everything when both schemes agree; otherwise the
    // per-scheme form, so an unset scheme keeps going out directly.
    const rules = http === https
        ? http
        : [http ? `http=${http}` : null, https ? `https=${https}` : null].filter(Boolean).join(';');

    return { source: 'env', proxy: { proxyRules: rules, proxyBypassRules: bypass } };
}

/**
 * The proxy configuration to pin onto the session, or `null` to leave Chromium
 * on the system one.
 */
function resolveProxy(env, argv, saved = null) {
    return resolveProxyDecision(env, argv, saved).proxy;
}

/** Whether this run was launched with a proxy instruction of its own. */
function hasProxyFlag(argv) {
    return argv.includes('--no-proxy') || readFlag(argv, '--proxy=') !== null;
}

/** Short human-readable form of a resolved proxy, for logs and the offline page. */
function describeProxy(proxy) {
    if (!proxy) {
        return 'system';
    }

    return proxy.mode === 'direct' ? 'direct' : proxy.proxyRules;
}

module.exports = {
    BLOCKED_EXTERNAL_SCHEMES,
    DEFAULT_APP_PATH,
    DEFAULT_APP_URL,
    DEFAULT_PROXY_BYPASS,
    NAVIGABLE_HOSTS,
    PARTITION,
    PROTOCOL,
    PROXY_MODES,
    describeProxy,
    hasProxyFlag,
    isAppHost,
    isExternallyOpenable,
    isNavigable,
    normalizeProxySetting,
    parseProxyBypass,
    parseProxyServer,
    resolveAppPath,
    resolveAppUrl,
    resolveProxy,
    resolveProxyDecision,
    resolveStartUrl,
};
