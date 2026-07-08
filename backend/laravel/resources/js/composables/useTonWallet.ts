import type { TonConnectUI } from '@tonconnect/ui';
import { ref } from 'vue';

/**
 * TON wallet connection via TON Connect (Tonkeeper, MyTonWallet, Tonhub, …).
 * The TonConnectUI instance is a lazily-created browser-only singleton: the
 * SDK is imported dynamically so SSR never evaluates it, and a previously
 * approved session is restored automatically on first use.
 */

// Global singleton state (mirrors useSolanaWallet's shape).
const isConnected = ref(false);
const isConnecting = ref(false);
const address = ref<string | null>(null); // user-friendly (UQ…)
const rawAddress = ref<string | null>(null); // raw "0:hex" form
const error = ref<string | null>(null);

let uiPromise: Promise<TonConnectUI> | null = null;

const TON_CONNECT_MANIFEST_URL =
    'https://cyberia.church/tonconnect-manifest.json';

/**
 * Lazily create (or return) the TonConnectUI singleton. Browser-only —
 * throws when called during SSR.
 */
export const getTonConnectUI = (): Promise<TonConnectUI> => {
    if (typeof window === 'undefined') {
        return Promise.reject(
            new Error('TON Connect is only available in the browser'),
        );
    }

    uiPromise ??= import('@tonconnect/ui').then(
        ({ TonConnectUI: TonConnectUIClass, toUserFriendlyAddress }) => {
            const ui = new TonConnectUIClass({
                manifestUrl: TON_CONNECT_MANIFEST_URL,
            });

            const apply = (raw: string | null) => {
                rawAddress.value = raw;
                address.value = raw ? toUserFriendlyAddress(raw) : null;
                isConnected.value = raw !== null;
            };

            ui.onStatusChange((wallet) => {
                apply(wallet?.account.address ?? null);
            });

            // Restore a previously approved session (Tonkeeper keeps it until
            // the user disconnects) so the bridge reconnects silently.
            void ui.connectionRestored.then((restored) => {
                if (restored && ui.account) {
                    apply(ui.account.address);
                }
            });

            return ui;
        },
    );

    return uiPromise;
};

export const useTonWallet = () => {
    /**
     * Initialize the SDK and restore a previous session without opening the
     * wallet modal. Safe to call on page mount.
     */
    const restore = async (): Promise<void> => {
        try {
            await getTonConnectUI();
        } catch {
            // SSR or SDK load failure — the connect button still works later.
        }
    };

    /**
     * Open the TON Connect modal (QR / Tonkeeper deep link) and wait until
     * the user connects or dismisses it. Returns the connected address.
     */
    const connect = async (): Promise<string | null> => {
        error.value = null;
        isConnecting.value = true;

        try {
            const ui = await getTonConnectUI();

            if (ui.connected && address.value) {
                return address.value;
            }

            await new Promise<void>((resolve) => {
                const unsubscribe = ui.onModalStateChange((state) => {
                    if (state.status === 'closed') {
                        unsubscribe();
                        // Let the status-change listener apply the account
                        // before we read it.
                        setTimeout(resolve, 50);
                    }
                });

                void ui.openModal();
            });

            return address.value;
        } catch (err) {
            error.value =
                err instanceof Error
                    ? err.message
                    : 'Failed to connect TON wallet';

            return null;
        } finally {
            isConnecting.value = false;
        }
    };

    const disconnect = async (): Promise<void> => {
        try {
            const ui = await getTonConnectUI();

            await ui.disconnect();
        } catch {
            // Already disconnected or SDK unavailable.
        }

        address.value = null;
        rawAddress.value = null;
        isConnected.value = false;
        error.value = null;
    };

    return {
        isConnected,
        isConnecting,
        address,
        rawAddress,
        error,
        restore,
        connect,
        disconnect,
    };
};
