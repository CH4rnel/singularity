import { ref } from 'vue';

export type SolanaWalletState = {
    isConnected: boolean;
    isConnecting: boolean;
    address: string | null;
    error: string | null;
};

interface PhantomProvider {
    isPhantom: boolean;
    publicKey: { toBase58(): string; toBytes(): Uint8Array } | null;
    isConnected: boolean;
    connect(opts?: {
        onlyIfTrusted?: boolean;
    }): Promise<{ publicKey: { toBase58(): string } }>;
    disconnect(): Promise<void>;
    signMessage(
        message: Uint8Array,
        encoding?: string,
    ): Promise<{ signature: Uint8Array; publicKey: { toBase58(): string } }>;
    on(event: string, handler: (...args: unknown[]) => void): void;
    off(event: string, handler: (...args: unknown[]) => void): void;
}

// Global singleton state
const isConnected = ref(false);
const isConnecting = ref(false);
const address = ref<string | null>(null);
const error = ref<string | null>(null);
let listenersSetup = false;
let restored = false;

const getPhantom = (): PhantomProvider | null => {
    if (typeof window === 'undefined') return null;

    // Phantom injects at window.phantom.solana or window.solana
    const provider =
        (window as unknown as { phantom?: { solana?: PhantomProvider } })
            .phantom?.solana ??
        (window as unknown as { solana?: PhantomProvider }).solana;

    if (provider?.isPhantom) {
        return provider;
    }

    return null;
};

/**
 * Wait for Phantom to be injected (it may load after page scripts).
 * Returns the provider or null after timeout.
 */
const waitForPhantom = (timeoutMs = 3000): Promise<PhantomProvider | null> => {
    return new Promise((resolve) => {
        const existing = getPhantom();
        if (existing) {
            resolve(existing);
            return;
        }

        let resolved = false;
        const handler = () => {
            if (resolved) return;
            const p = getPhantom();
            if (p) {
                resolved = true;
                resolve(p);
            }
        };

        window.addEventListener('phanton#initialized', handler);

        // Also poll in case the event was missed
        const interval = setInterval(() => {
            const p = getPhantom();
            if (p) {
                resolved = true;
                clearInterval(interval);
                resolve(p);
            }
        }, 100);

        setTimeout(() => {
            if (!resolved) {
                resolved = true;
                clearInterval(interval);
                resolve(null);
            }
        }, timeoutMs);
    });
};

export const useSolanaWallet = () => {
    const isPhantomInstalled = (): boolean => {
        return getPhantom() !== null;
    };

    /**
     * Restore wallet state from the authenticated user's saved solana_wallet_address
     * and try to silently reconnect via Phantom (onlyIfTrusted — no popup).
     */
    const restore = async (savedAddress?: string | null): Promise<void> => {
        if (restored || isConnected.value) return;
        restored = true;

        if (savedAddress) {
            address.value = savedAddress;
            isConnected.value = true;
        }

        const phantom = await waitForPhantom();
        if (phantom) {
            try {
                const resp = await phantom.connect({ onlyIfTrusted: true });
                address.value = resp.publicKey.toBase58();
                isConnected.value = true;
                setupListeners();
            } catch {
                // Phantom not trusted yet or not available
            }
        }
    };

    const connect = async (): Promise<string | null> => {
        const phantom = await waitForPhantom();

        if (!phantom) {
            error.value =
                'Phantom wallet is not installed. Please install it from phantom.app';
            return null;
        }

        error.value = null;
        isConnecting.value = true;

        try {
            const resp = await phantom.connect();
            address.value = resp.publicKey.toBase58();
            isConnected.value = true;

            setupListeners();

            return address.value;
        } catch (err) {
            error.value =
                err instanceof Error
                    ? err.message
                    : 'Failed to connect Phantom wallet';
            isConnected.value = false;
            address.value = null;
            return null;
        } finally {
            isConnecting.value = false;
        }
    };

    const disconnect = (): void => {
        const phantom = getPhantom();
        if (phantom) {
            phantom.disconnect().catch(() => {});
        }
        address.value = null;
        isConnected.value = false;
        error.value = null;
        removeListeners();
    };

    const signMessage = async (message: string): Promise<string | null> => {
        const phantom = getPhantom();

        if (!phantom || !address.value) {
            error.value = 'Phantom wallet not connected';
            return null;
        }

        try {
            const encodedMessage = new TextEncoder().encode(message);
            const { signature } = await phantom.signMessage(
                encodedMessage,
                'utf8',
            );

            // Convert Uint8Array to base64 for transport
            return btoa(String.fromCharCode(...signature));
        } catch (err) {
            error.value =
                err instanceof Error ? err.message : 'Failed to sign message';
            return null;
        }
    };

    const setupListeners = (): void => {
        const phantom = getPhantom();
        if (!phantom || listenersSetup) return;
        listenersSetup = true;

        phantom.on('accountChanged', (publicKey: unknown) => {
            if (publicKey) {
                const pk = publicKey as { toBase58(): string };
                address.value = pk.toBase58();
            } else {
                disconnect();
            }
        });

        phantom.on('disconnect', () => {
            disconnect();
        });
    };

    const removeListeners = (): void => {
        const phantom = getPhantom();
        if (!phantom) return;
        listenersSetup = false;

        phantom.off('accountChanged', () => {});
        phantom.off('disconnect', () => {});
    };

    const formatAddress = (addr: string, chars = 4): string => {
        return `${addr.slice(0, chars)}...${addr.slice(-chars)}`;
    };

    return {
        isConnected,
        isConnecting,
        address,
        error,
        isPhantomInstalled,
        connect,
        disconnect,
        restore,
        signMessage,
        formatAddress,
    };
};
