const SESSION_KEY = 'bridgeSessionId';

export type BridgeEventType =
    | 'page_view'
    | 'direction_selected'
    | 'evm_wallet_connected'
    | 'solana_wallet_connected'
    | 'amount_entered'
    | 'destination_entered'
    | 'lock_tx_submitted'
    | 'lock_tx_confirmed'
    | 'lock_tx_rejected'
    | 'bridge_submitted'
    | 'bridge_submit_failed'
    | 'tracking_started';

export type BridgeEventPayload = {
    direction?: 'sol_to_evm' | 'evm_to_sol';
    amount?: string | number;
    source_address?: string;
    destination_address?: string;
    error_message?: string;
    bridge_request_id?: number;
    metadata?: Record<string, unknown>;
};

const ensureSessionId = (): string => {
    if (typeof window === 'undefined') {
        return '';
    }

    let id = window.sessionStorage.getItem(SESSION_KEY);

    if (!id) {
        id = window.crypto?.randomUUID?.() ?? fallbackUuid();
        window.sessionStorage.setItem(SESSION_KEY, id);
    }

    return id;
};

const fallbackUuid = (): string =>
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;

        return v.toString(16);
    });

export const useBridgeAnalytics = () => {
    const sessionId = ensureSessionId();

    const track = (
        eventType: BridgeEventType,
        payload: BridgeEventPayload = {},
    ): void => {
        if (typeof window === 'undefined') {
            return;
        }

        const body = JSON.stringify({
            session_id: sessionId,
            event_type: eventType,
            ...payload,
        });

        try {
            void fetch('/api/bridge/events', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body,
                keepalive: true,
                credentials: 'same-origin',
            }).catch(() => {
                // analytics failures must not break the UX
            });
        } catch {
            // ignore
        }
    };

    return {
        sessionId,
        track,
    };
};
