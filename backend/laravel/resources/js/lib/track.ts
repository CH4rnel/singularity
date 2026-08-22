/**
 * Fire-and-forget funnel tracking (POST /api/events → site_events →
 * CRM /crm/analytics). Never throws, never blocks the UI; event names must
 * stay in sync with App\Models\SiteEvent::EVENTS.
 */
import { apiFetch } from '@/lib/http';

export type SiteEventName =
    | 'page_view'
    | 'landing_view'
    | 'wallet_connect_started'
    | 'wallet_connected'
    | 'network_switch'
    | 'bridge_started'
    | 'bridge_completed'
    | 'swap_started'
    | 'swap_completed'
    | 'staking_started'
    | 'staking_completed'
    | 'partner_cta_clicked'
    | 'liquidity_added';

const SESSION_KEY = 'site.session_id';
const ATTRIBUTION_KEY = 'site.utm_attribution';

export type SafeEventMetadata = {
    source?: string;
    medium?: string;
    campaign?: string;
    partner?: string;
    network?: string;
    token?: string;
    action_type?: string;
};

const UTM_FIELDS = {
    utm_source: 'source',
    utm_medium: 'medium',
    utm_campaign: 'campaign',
} as const satisfies Record<string, keyof SafeEventMetadata>;

const safeValue = (value: unknown): string | undefined => {
    if (typeof value !== 'string') {
        return undefined;
    }

    const normalized = value.trim().slice(0, 100);

    return normalized === '' ? undefined : normalized;
};

export function captureAttribution(): SafeEventMetadata {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const stored = window.sessionStorage.getItem(ATTRIBUTION_KEY);
        const attribution: SafeEventMetadata = stored
            ? (JSON.parse(stored) as SafeEventMetadata)
            : {};
        const params = new URLSearchParams(window.location.search);
        let changed = false;

        for (const [queryKey, metadataKey] of Object.entries(UTM_FIELDS)) {
            const value = safeValue(params.get(queryKey));

            if (value) {
                attribution[metadataKey] = value;
                changed = true;
            }
        }

        if (changed || !stored) {
            window.sessionStorage.setItem(
                ATTRIBUTION_KEY,
                JSON.stringify(attribution),
            );
        }

        return attribution;
    } catch {
        return {};
    }
}

const sanitizeMetadata = (
    metadata: SafeEventMetadata = {},
): SafeEventMetadata => {
    const clean: SafeEventMetadata = {};

    for (const key of Object.values(UTM_FIELDS)) {
        const value = safeValue(metadata[key]);

        if (value) {
            clean[key] = value;
        }
    }

    for (const key of ['partner', 'network', 'token', 'action_type'] as const) {
        const value = safeValue(metadata[key]);

        if (value) {
            clean[key] = value;
        }
    }

    return clean;
};

export function attributedUrl(href: string): string {
    if (typeof window === 'undefined') {
        return href;
    }

    const attribution = captureAttribution();

    if (!attribution.source && !attribution.medium && !attribution.campaign) {
        return href;
    }

    try {
        const url = new URL(href, window.location.origin);

        for (const [queryKey, metadataKey] of Object.entries(UTM_FIELDS)) {
            const value = attribution[metadataKey];

            if (value && !url.searchParams.has(queryKey)) {
                url.searchParams.set(queryKey, value);
            }
        }

        return url.origin === window.location.origin
            ? `${url.pathname}${url.search}${url.hash}`
            : url.toString();
    } catch {
        return href;
    }
}

function sessionId(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        let id = window.localStorage.getItem(SESSION_KEY);

        if (!id) {
            id = window.crypto.randomUUID();
            window.localStorage.setItem(SESSION_KEY, id);
        }

        return id;
    } catch {
        return null;
    }
}

export function track(
    event: SiteEventName,
    data: {
        page?: string;
        metadata?: SafeEventMetadata;
    } = {},
): void {
    const id = sessionId();

    if (!id) {
        return;
    }

    const metadata = sanitizeMetadata({
        ...captureAttribution(),
        ...data.metadata,
    });

    void apiFetch('/api/events', {
        method: 'POST',
        keepalive: true,
        body: JSON.stringify({
            session_id: id,
            event,
            page: data.page ?? window.location.pathname,
            metadata: Object.keys(metadata).length > 0 ? metadata : undefined,
        }),
    }).catch(() => {});
}
