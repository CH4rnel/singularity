/**
 * Where a user came from, and where one visit ends.
 *
 * Both are pure functions over what the browser already knows, so both are
 * pinned by `tests/Frontend/AnalyticsClientTest.mjs` — attribution is the one
 * part of an analytics system whose bugs are invisible until a quarter's
 * marketing decisions have already been made on it.
 */

export type Attribution = {
    source?: string;
    medium?: string;
    campaign?: string;
    content?: string;
    /** Origin only. A full referring URL is somebody's browsing history. */
    referrer?: string;
    landing_path?: string;
};

const clean = (value: string | null | undefined): string | undefined => {
    if (typeof value !== 'string') {
        return undefined;
    }

    const trimmed = value.trim().slice(0, 100);

    return trimmed === '' ? undefined : trimmed;
};

/**
 * Read the campaign off a landing URL.
 *
 * Three sources, in the order they can be trusted:
 *
 *   `utm_*` on the URL, which is what every campaign link this project hands
 *   out already carries — `lib/track.ts` even propagates them across
 *   same-origin links, so a visitor who arrived on the landing page and walked
 *   to /wallet still has them.
 *
 *   Telegram's `startapp` payload, which is the only campaign channel that
 *   survives into the Mini App: `t.me/<bot>/app?startapp=<campaign>` arrives
 *   as `tgWebAppStartParam` in the launch parameters and there is no URL bar
 *   to carry a `utm_source` instead.
 *
 *   The referring site, reduced to its origin, for everything with no tag at
 *   all — enough to tell a link from a search from a direct open.
 */
export const parseAttribution = (
    href: string,
    referrer: string,
    startParam: string | null = null,
): Attribution => {
    let params: URLSearchParams;
    let path: string | undefined;
    let origin = '';

    try {
        const url = new URL(href);
        params = url.searchParams;
        path = url.pathname;
        origin = url.origin;
    } catch {
        params = new URLSearchParams();
    }

    const attribution: Attribution = {
        source: clean(params.get('utm_source') ?? params.get('ref')),
        medium: clean(params.get('utm_medium')),
        campaign: clean(params.get('utm_campaign')),
        content: clean(params.get('utm_content')),
        landing_path: path,
    };

    // A Mini App opened from a campaign button: Telegram is the source, the
    // frame is the medium, and the payload the operator chose is the campaign.
    if (!attribution.source && startParam) {
        attribution.source = 'telegram';
        attribution.medium = 'mini_app';
        attribution.campaign = clean(startParam);
    }

    const from = clean(referrer);

    if (from) {
        try {
            const url = new URL(from);

            // A link from our own pages is navigation, not acquisition.
            if (url.origin !== origin) {
                attribution.referrer = `${url.protocol}//${url.host}`;
                attribution.source ??= url.hostname;
                attribution.medium ??= 'referral';
            }
        } catch {
            /* Not a URL; there is nothing to learn from it. */
        }
    }

    for (const key of Object.keys(attribution) as (keyof Attribution)[]) {
        if (attribution[key] === undefined) {
            delete attribution[key];
        }
    }

    return attribution;
};

/**
 * First-touch, and it means exactly that: once an installation has recorded
 * where it came from, nothing replaces it.
 *
 * The alternative — letting a later tagged link overwrite an untagged first
 * visit — is the most common way an acquisition report ends up crediting the
 * retargeting campaign for users the launch announcement brought in.
 */
export const firstTouch = (
    stored: Attribution | null,
    candidate: Attribution,
): Attribution => stored ?? candidate;

/**
 * Whether the gap since the last activity opens a new session.
 *
 * Thirty minutes by convention. Decided here rather than on the server because
 * inactivity is something only this side can see: to a server, a wallet that
 * was idle and a wallet that was offline look identical.
 */
export const sessionExpired = (
    now: number,
    lastActivity: number | null,
    timeoutMs: number,
): boolean => lastActivity === null || now - lastActivity >= timeoutMs;
