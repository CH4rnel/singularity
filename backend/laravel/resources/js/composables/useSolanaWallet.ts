import { ref } from 'vue';
import {
    connectSolanaWalletProvider,
    disconnectSolanaWalletProvider,
    getSelectedSolanaAddress,
    getSelectedSolanaTransactionProvider,
    getSolanaWalletProvider,
    getSolanaWalletProviders,
    onSelectedSolanaAccountChange,
    signSolanaMessage,
} from '@/lib/solanaWalletProvider';
import type {
    SolanaTransactionProvider,
    SolanaWalletProvider,
} from '@/lib/solanaWalletProvider';

export type SolanaWalletState = {
    isConnected: boolean;
    isConnecting: boolean;
    address: string | null;
    error: string | null;
};

// Global singleton state
const isConnected = ref(false);
const isConnecting = ref(false);
const address = ref<string | null>(null);
const error = ref<string | null>(null);
const walletProviders = ref<SolanaWalletProvider[]>([]);
const selectedWalletName = ref<string | null>(null);
let removeAccountListener: (() => void) | null = null;
let restored = false;

const refreshWalletProviders = (): SolanaWalletProvider[] => {
    walletProviders.value = getSolanaWalletProviders();

    return walletProviders.value;
};

const getDefaultWalletProvider = (): SolanaWalletProvider | null => {
    const providers = refreshWalletProviders();

    return (
        providers.find((wallet) => wallet.name.toLowerCase() === 'phantom') ??
        providers[0] ??
        null
    );
};

const setupListeners = (): void => {
    removeAccountListener?.();
    removeAccountListener = onSelectedSolanaAccountChange((nextAddress) => {
        if (nextAddress) {
            address.value = nextAddress;
        } else {
            address.value = null;
            isConnected.value = false;
            selectedWalletName.value = null;
        }
    });
};

export const useSolanaWallet = () => {
    const isPhantomInstalled = (): boolean =>
        refreshWalletProviders().some(
            (wallet) => wallet.name.toLowerCase() === 'phantom',
        );

    const isSolanaWalletInstalled = (): boolean =>
        refreshWalletProviders().length > 0;

    /**
     * Restore wallet state from the authenticated user's saved solana_wallet_address
     * and try to silently reconnect via any available Solana wallet.
     */
    const restore = async (savedAddress?: string | null): Promise<void> => {
        if (restored || isConnected.value) {
            return;
        }

        restored = true;

        if (savedAddress) {
            address.value = savedAddress;
            isConnected.value = true;
        }

        for (const wallet of refreshWalletProviders()) {
            try {
                const connectedAddress = await connectSolanaWalletProvider(
                    wallet,
                    true,
                );

                if (!connectedAddress) {
                    continue;
                }

                if (savedAddress && connectedAddress !== savedAddress) {
                    await disconnectSolanaWalletProvider();
                    continue;
                }

                address.value = connectedAddress;
                selectedWalletName.value = wallet.name;
                isConnected.value = true;
                setupListeners();

                break;
            } catch {
                // Wallet not trusted yet or not available.
            }
        }
    };

    const connect = async (walletId?: string): Promise<string | null> => {
        const wallet = walletId
            ? getSolanaWalletProvider(walletId)
            : getDefaultWalletProvider();

        if (!wallet) {
            error.value =
                'No Solana wallet detected. Install Phantom, Solflare, Backpack, or another Wallet Standard wallet.';

            return null;
        }

        error.value = null;
        isConnecting.value = true;

        try {
            const connectedAddress = await connectSolanaWalletProvider(wallet);

            if (!connectedAddress) {
                throw new Error('No Solana accounts found');
            }

            address.value = connectedAddress;
            selectedWalletName.value = wallet.name;
            isConnected.value = true;
            setupListeners();

            return address.value;
        } catch (err) {
            error.value =
                err instanceof Error
                    ? err.message
                    : 'Failed to connect Solana wallet';
            isConnected.value = false;
            address.value = null;
            selectedWalletName.value = null;

            return null;
        } finally {
            isConnecting.value = false;
        }
    };

    function disconnect(): void {
        removeAccountListener?.();
        removeAccountListener = null;

        disconnectSolanaWalletProvider().catch(() => {});

        address.value = null;
        isConnected.value = false;
        error.value = null;
        selectedWalletName.value = null;
    }

    const signMessage = async (message: string): Promise<string | null> => {
        if (!address.value) {
            error.value = 'Solana wallet not connected';

            return null;
        }

        try {
            return await signSolanaMessage(message);
        } catch (err) {
            error.value =
                err instanceof Error ? err.message : 'Failed to sign message';

            return null;
        }
    };

    const getTransactionProvider = (
        cluster: 'mainnet' | 'devnet' = 'mainnet',
    ): SolanaTransactionProvider | null => {
        if (!address.value && getSelectedSolanaAddress()) {
            address.value = getSelectedSolanaAddress();
            isConnected.value = true;
        }

        return getSelectedSolanaTransactionProvider(cluster);
    };

    const formatAddress = (addr: string, chars = 4): string => {
        return `${addr.slice(0, chars)}...${addr.slice(-chars)}`;
    };

    return {
        isConnected,
        isConnecting,
        address,
        error,
        walletProviders,
        selectedWalletName,
        isPhantomInstalled,
        isSolanaWalletInstalled,
        refreshWalletProviders,
        connect,
        disconnect,
        restore,
        signMessage,
        getTransactionProvider,
        formatAddress,
    };
};
