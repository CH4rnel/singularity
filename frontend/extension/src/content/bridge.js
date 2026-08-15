/**
 * The one-way valve between a page and the wallet.
 *
 * This script is isolated from the page: it can hold the port to the service
 * worker, and the page cannot. Everything it forwards is data — a method name
 * and parameters — and everything it sends back is an answer to a request the
 * page made or an event the wallet chose to publish. The origin is never taken
 * from the message; the service worker reads it off the sender.
 */
import { CONTENT_TO_PAGE, PAGE_TO_CONTENT, PROVIDER_PORT } from '../shared/protocol.js';

let port = null;

const toPage = (message) => window.postMessage({ channel: CONTENT_TO_PAGE, ...message }, window.location.origin);

const connect = () => {
    port = chrome.runtime.connect({ name: PROVIDER_PORT });

    port.onMessage.addListener((message) => {
        if (message?.kind === 'response') {
            toPage({ id: message.id, result: message.result, error: message.error });
        } else if (message?.kind === 'event') {
            toPage({ event: message.event });
        }
    });

    port.onDisconnect.addListener(() => {
        port = null;
        // The worker was evicted or the extension reloaded. The next request
        // reconnects; telling the page it is disconnected now would make every
        // dapp think the user walked away.
    });

    return port;
};

window.addEventListener('message', (message) => {
    if (message.source !== window || message.data?.channel !== PAGE_TO_CONTENT) {
        return;
    }

    const { id, payload } = message.data;

    if (typeof id !== 'string' || !payload || typeof payload.method !== 'string') {
        return;
    }

    try {
        (port ?? connect()).postMessage({ kind: 'request', id, payload });
    } catch {
        // Reconnect once: an evicted worker is the normal case, not an error.
        try {
            connect().postMessage({ kind: 'request', id, payload });
        } catch (error) {
            toPage({
                id,
                error: { code: 4900, message: `Cyberia Wallet is unreachable: ${error?.message ?? error}` },
            });
        }
    }
});

connect();
