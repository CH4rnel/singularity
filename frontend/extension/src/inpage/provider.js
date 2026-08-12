/**
 * The provider a page actually sees.
 *
 * This file runs in the page's own world, so treat it as public: it holds no
 * key, no address the page was not told, and no way to reach an extension API.
 * All it can do is put a request on `window.postMessage` and wait — the answer
 * comes back from a content script that the page cannot call directly.
 *
 * Both discovery paths are covered. EIP-6963 is the one that lets a browser
 * with several wallets offer a choice; `window.ethereum` is set only when no
 * one else has claimed it, because overwriting another wallet's provider is
 * how a user ends up signing in the wallet they did not pick.
 */
import { CONTENT_TO_PAGE, PAGE_TO_CONTENT, PROVIDER_INFO } from '../shared/protocol.js';

const pending = new Map();
const listeners = new Map();

let nextId = 0;
let chainId = null;
let accounts = [];

const emit = (event, payload) => {
    for (const listener of listeners.get(event) ?? []) {
        try {
            listener(payload);
        } catch {
            // A dapp's own handler throwing is the dapp's problem, not ours.
        }
    }
};

window.addEventListener('message', (message) => {
    if (message.source !== window || message.data?.channel !== CONTENT_TO_PAGE) {
        return;
    }

    const { id, result, error, event } = message.data;

    if (event) {
        if (event.type === 'accountsChanged') {
            accounts = event.accounts ?? [];
            emit('accountsChanged', accounts);
        } else if (event.type === 'chainChanged') {
            chainId = event.chainId;
            emit('chainChanged', chainId);
        } else if (event.type === 'connect') {
            chainId = event.chainId;
            emit('connect', { chainId });
        } else if (event.type === 'lock') {
            accounts = [];
            emit('accountsChanged', []);
        } else if (event.type === 'disconnect') {
            emit('disconnect', event.error ?? { code: 4900, message: 'Wallet disconnected' });
        }

        return;
    }

    const waiting = pending.get(id);

    if (!waiting) {
        return;
    }

    pending.delete(id);

    if (error) {
        const failure = new Error(error.message ?? 'Request failed');
        failure.code = error.code;
        failure.data = error.data;
        waiting.reject(failure);
    } else {
        waiting.resolve(result);
    }
});

const send = (payload) =>
    new Promise((resolve, reject) => {
        const id = `${Date.now().toString(36)}:${++nextId}`;
        pending.set(id, { resolve, reject });
        window.postMessage({ channel: PAGE_TO_CONTENT, id, payload }, window.location.origin);
    });

class CyberiaProvider {
    /** Legacy flags dapps still branch on. Never `isMetaMask`. */
    isCyberia = true;

    isCyberiaWallet = true;

    async request(args) {
        if (!args || typeof args.method !== 'string') {
            const error = new Error('Invalid request');
            error.code = -32602;
            throw error;
        }

        const result = await send({ method: args.method, params: args.params ?? [] });

        if (args.method === 'eth_requestAccounts' || args.method === 'eth_accounts') {
            accounts = Array.isArray(result) ? result : [];
        }

        if (args.method === 'eth_chainId') {
            chainId = result;
        }

        return result;
    }

    on(event, listener) {
        listeners.set(event, [...(listeners.get(event) ?? []), listener]);

        return this;
    }

    addListener(event, listener) {
        return this.on(event, listener);
    }

    removeListener(event, listener) {
        listeners.set(
            event,
            (listeners.get(event) ?? []).filter((entry) => entry !== listener),
        );

        return this;
    }

    removeAllListeners(event) {
        if (event) {
            listeners.delete(event);
        } else {
            listeners.clear();
        }

        return this;
    }

    /** Pre-1193 entry point; some older dapps still call it first. */
    enable() {
        return this.request({ method: 'eth_requestAccounts' });
    }

    isConnected() {
        return accounts.length > 0;
    }

    get chainId() {
        return chainId;
    }

    get selectedAddress() {
        return accounts[0] ?? null;
    }
}

const provider = new CyberiaProvider();

const announce = () => {
    window.dispatchEvent(
        new CustomEvent('eip6963:announceProvider', {
            detail: Object.freeze({
                info: {
                    ...PROVIDER_INFO,
                    // Inline so the announcement needs no network and no
                    // web-accessible resource: a hollow square, the mark the
                    // wallet uses everywhere.
                    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiI+PHJlY3Qgd2lkdGg9IjMyIiBoZWlnaHQ9IjMyIiBmaWxsPSIjMDcwODBBIi8+PHJlY3QgeD0iNi41IiB5PSI2LjUiIHdpZHRoPSIxOSIgaGVpZ2h0PSIxOSIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjMkZFOUUwIi8+PHJlY3QgeD0iMTMiIHk9IjEzIiB3aWR0aD0iNiIgaGVpZ2h0PSI2IiBmaWxsPSIjMkZFOUUwIi8+PC9zdmc+',
                },
                provider,
            }),
        }),
    );
};

window.addEventListener('eip6963:requestProvider', announce);
announce();

if (!window.ethereum) {
    Object.defineProperty(window, 'ethereum', {
        value: provider,
        configurable: true,
        writable: false,
    });
}

window.cyberia = provider;
