import { BrowserProvider, Contract, formatEther, formatUnits } from 'ethers';
import { ref } from 'vue';
import { getMetaMaskProvider } from '@/lib/evmProvider';
import { track } from '@/lib/track';
import type { EthereumProvider } from '@/types/global';

export type WalletState = {
    isConnected: boolean;
    isConnecting: boolean;
    address: string | null;
    balance: string | null;
    chainId: number | null;
    error: string | null;
};

const ERC20_ABI = [
    'function balanceOf(address owner) view returns (uint256)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
];

const CYBER_CONTRACT = '0x38Fb766Fa8c03fc098B6Ff74d1Ed1293bDdAcF7f';

export type TokenBalance = {
    symbol: string;
    balance: string;
    formatted: string;
};

// Global singleton state — persists across component re-renders and page navigations
const isConnected = ref(false);
const isConnecting = ref(false);
const address = ref<string | null>(null);
const balance = ref<string | null>(null);
const chainId = ref<number | null>(null);
const error = ref<string | null>(null);
const cyberBalance = ref<TokenBalance | null>(null);
let listenersSetup = false;
let restored = false;

// The MetaMask provider, resolved via EIP-6963 so we never fall through to
// Phantom (or another wallet) when several are installed.
const getProvider = (): EthereumProvider | null => getMetaMaskProvider();

export const useWallet = () => {
    const isMetaMaskInstalled = (): boolean => getProvider() !== null;

    // Kept for callers that only care that *some* EVM wallet exists.
    const isEvmProviderInstalled = isMetaMaskInstalled;

    /**
     * Restore wallet state from the authenticated user's saved wallet_address
     * and try to silently reconnect via MetaMask (eth_accounts — no popup).
     * Should be called once on app mount.
     */
    const restore = async (savedAddress?: string | null): Promise<void> => {
        if (restored || isConnected.value) {
            return;
        }

        restored = true;

        // If user has a wallet_address saved in DB, set it immediately
        // so the UI shows the address even before MetaMask responds
        if (savedAddress) {
            address.value = savedAddress;
            isConnected.value = true;
        }

        // Try silent reconnect through MetaMask (no popup)
        const provider = getProvider();

        if (provider) {
            try {
                const accounts = (await provider.request({
                    method: 'eth_accounts',
                })) as string[];

                if (accounts.length > 0) {
                    address.value = accounts[0];
                    isConnected.value = true;
                    setupListeners();
                    // Fire-and-forget — don't block UI
                    fetchBalance();
                    fetchChainId();
                    fetchCyberBalance();
                }
            } catch {
                // MetaMask not available or rejected — keep savedAddress if any
            }
        }
    };

    const connect = async (): Promise<string | null> => {
        const provider = getProvider();

        if (!provider) {
            error.value =
                'MetaMask not detected. Install MetaMask, or disable other wallets that hijack the EVM provider.';

            return null;
        }

        error.value = null;
        isConnecting.value = true;

        try {
            const accounts = (await provider.request({
                method: 'eth_requestAccounts',
            })) as string[];

            if (accounts.length === 0) {
                throw new Error('No accounts found');
            }

            address.value = accounts[0];
            isConnected.value = true;

            track('wallet_connected', { wallet_address: accounts[0] });

            await fetchBalance();
            await fetchChainId();
            await fetchCyberBalance();

            setupListeners();

            return address.value;
        } catch (err) {
            error.value =
                err instanceof Error ? err.message : 'Failed to connect wallet';
            isConnected.value = false;
            address.value = null;

            return null;
        } finally {
            isConnecting.value = false;
        }
    };

    const disconnect = (): void => {
        address.value = null;
        isConnected.value = false;
        balance.value = null;
        chainId.value = null;
        error.value = null;
        cyberBalance.value = null;
        removeListeners();
    };

    const fetchBalance = async (): Promise<void> => {
        const injected = getProvider();

        if (!address.value || !injected) {
            return;
        }

        try {
            const provider = new BrowserProvider(injected);
            const balanceBigInt = await provider.getBalance(address.value);
            balance.value = formatEther(balanceBigInt);
        } catch {
            balance.value = null;
        }
    };

    const fetchCyberBalance = async (): Promise<void> => {
        const injected = getProvider();

        if (!address.value || !injected) {
            return;
        }

        try {
            const provider = new BrowserProvider(injected);
            const contract = new Contract(CYBER_CONTRACT, ERC20_ABI, provider);

            const balanceRaw = (await contract.balanceOf(
                address.value,
            )) as bigint;
            const decimals = (await contract.decimals()) as number;
            const symbol = (await contract.symbol()) as string;

            const formatted = formatUnits(balanceRaw, decimals);

            cyberBalance.value = {
                symbol,
                balance: balanceRaw.toString(),
                formatted,
            };
        } catch {
            cyberBalance.value = null;
        }
    };

    const fetchChainId = async (): Promise<void> => {
        const injected = getProvider();

        if (!injected) {
            return;
        }

        try {
            const chainIdHex = (await injected.request({
                method: 'eth_chainId',
            })) as string;
            chainId.value = parseInt(chainIdHex, 16);
        } catch {
            chainId.value = null;
        }
    };

    const signMessage = async (message: string): Promise<string | null> => {
        const injected = getProvider();

        if (!address.value || !injected) {
            error.value = 'Wallet not connected';

            return null;
        }

        try {
            const provider = new BrowserProvider(injected);
            const signer = await provider.getSigner();

            const signature = await signer.signMessage(message);

            return signature;
        } catch (err) {
            error.value =
                err instanceof Error ? err.message : 'Failed to sign message';

            return null;
        }
    };

    const setupListeners = (): void => {
        const injected = getProvider();

        if (!injected || listenersSetup) {
            return;
        }

        listenersSetup = true;

        injected.on('accountsChanged', (accounts: unknown) => {
            const accs = accounts as string[];

            if (accs.length === 0) {
                disconnect();
            } else if (accs[0] !== address.value) {
                address.value = accs[0];
                fetchBalance();
                fetchCyberBalance();
            }
        });

        injected.on('chainChanged', () => {
            fetchChainId();
            fetchBalance();
            fetchCyberBalance();
        });

        injected.on('disconnect', () => {
            disconnect();
        });
    };

    const removeListeners = (): void => {
        const injected = getProvider();

        if (!injected) {
            return;
        }

        listenersSetup = false;

        injected.removeAllListeners?.('accountsChanged');
        injected.removeAllListeners?.('chainChanged');
        injected.removeAllListeners?.('disconnect');
    };

    const formatAddress = (addr: string, chars = 4): string => {
        return `${addr.slice(0, chars + 2)}...${addr.slice(-chars)}`;
    };

    return {
        isConnected,
        isConnecting,
        address,
        balance,
        chainId,
        error,
        cyberBalance,
        isMetaMaskInstalled,
        isEvmProviderInstalled,
        connect,
        disconnect,
        restore,
        signMessage,
        fetchBalance,
        fetchCyberBalance,
        fetchChainId,
        formatAddress,
    };
};
