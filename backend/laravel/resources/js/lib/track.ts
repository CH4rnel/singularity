/**
 * Fire-and-forget funnel tracking (POST /api/events → site_events →
 * CRM /crm/analytics). Never throws, never blocks the UI; event names must
 * stay in sync with App\Models\SiteEvent::EVENTS.
 */
import { apiFetch } from '@/lib/http';

export type SiteEventName =
    | 'page_view'
    | 'wallet_connected'
    | 'swap_executed'
    | 'liquidity_added';

const SESSION_KEY = 'site.session_id';

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
        wallet_address?: string;
        metadata?: Record<string, unknown>;
    } = {},
): void {
    const id = sessionId();

    if (!id) {
        return;
    }

    void apiFetch('/api/events', {
        method: 'POST',
        body: JSON.stringify({
            session_id: id,
            event,
            page: data.page ?? window.location.pathname,
            wallet_address: data.wallet_address,
            metadata: data.metadata,
        }),
    }).catch(() => {});
}
