import { BrowserProvider, Contract, formatEther, formatUnits } from 'ethers';
import { ref } from 'vue';
import { ensureEvmChain, evmProviderSupportsChain } from '@/lib/evmChains';
import type { EvmChain } from '@/lib/evmChains';
import {
    getEvmWalletProvider,
    getEvmWalletProviders,
    getMetaMaskProvider,
    getSelectedEvmProvider,
    getSelectedEvmWalletProvider,
    hasWalletConnectSession,
    initWalletConnectProvider,
    resolveEvmWalletProvider,
    selectEvmWalletProvider,
} from '@/lib/evmProvider';
import type { EvmWalletProvider } from '@/lib/evmProvider';
import { track } from '@/lib/track';
import { evmWalletSupportsCyberia } from '@/lib/walletChoices';
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
const walletProviders = ref<EvmWalletProvider[]>([]);
const selectedWalletName = ref<string | null>(null);
let listenersSetup = false;
let listenerProvider: EthereumProvider | null = null;
let accountChangedHandler: ((accounts: unknown) => void) | null = null;
let chainChangedHandler: (() => void) | null = null;
let disconnectHandler: (() => void) | null = null;
let restored = false;

const refreshWalletProviders = (): EvmWalletProvider[] => {
    walletProviders.value = getEvmWalletProviders();

    return walletProviders.value;
};

const getDefaultWalletProvider = (): EvmWalletProvider | null => {
    const providers = refreshWalletProviders();

    // Wallets that cannot switch to Cyberia (Phantom EVM) are a last resort:
    // they can still sign a login message but no on-chain action will work.
    return (
        providers.find((wallet) => wallet.rdns === 'io.metamask') ??
        providers.find((wallet) => evmWalletSupportsCyberia(wallet)) ??
        providers[0] ??
        null
    );
};

const getProvider = (): EthereumProvider | null => getSelectedEvmProvider();

export const useWallet = () => {
    const isMetaMaskInstalled = (): boolean => getMetaMaskProvider() !== null;

    // Kept for callers that only care that *some* EVM wallet exists.
    const isEvmProviderInstalled = (): boolean =>
        refreshWalletProviders().length > 0;

    /**
     * Restore wallet state from the authenticated user's saved wallet_address
     * and try to silently reconnect via an injected wallet (eth_accounts — no popup).
     * Should be called once on app mount.
     */
    const restore = async (savedAddress?: string | null): Promise<void> => {
        if (restored || isConnected.value) {
            return;
        }

        restored = true;

        // If user has a wallet_address saved in DB, set it immediately
        // so the UI shows the address even before an injected wallet responds.
        if (savedAddress) {
            address.value = savedAddress;
            isConnected.value = true;
        }

        // Try silent reconnect through injected wallets (no popup). A saved
        // WalletConnect pairing joins the list via a no-modal SDK init.
        // Cyberia-capable wallets go first so a fixed-chain wallet (Phantom
        // EVM) only becomes the selected provider when nothing else matches.
        const providers = [...refreshWalletProviders()].sort(
            (a, b) =>
                Number(evmWalletSupportsCyberia(b)) -
                Number(evmWalletSupportsCyberia(a)),
        );

        for (const wallet of providers) {
            try {
                const provider =
                    wallet.provider ??
                    (wallet.source === 'walletconnect' &&
                    hasWalletConnectSession()
                        ? await initWalletConnectProvider()
                        : null);

                if (!provider) {
                    continue;
                }

                const accounts = (await provider.request({
                    method: 'eth_accounts',
                })) as string[];

                if (accounts.length > 0) {
                    const account = accounts[0];

                    if (
                        savedAddress &&
                        account.toLowerCase() !== savedAddress.toLowerCase()
                    ) {
                        continue;
                    }

                    selectEvmWalletProvider({ ...wallet, provider });
                    selectedWalletName.value = wallet.name;
                    address.value = account;
                    isConnected.value = true;
                    setupListeners();
                    // Fire-and-forget — don't block UI
                    fetchBalance();
                    fetchChainId();
                    fetchCyberBalance();

                    break;
                }
            } catch {
                // Wallet not available or not authorized — keep savedAddress if any.
            }
        }
    };

    const connectTo = async (
        picked: EvmWalletProvider,
    ): Promise<string | null> => {
        error.value = null;
        isConnecting.value = true;

        try {
            removeListeners();

            // WalletConnect initialises its SDK here; the QR / deep-link
            // modal opens on the eth_requestAccounts call below.
            const wallet = await resolveEvmWalletProvider(picked);

            selectEvmWalletProvider(wallet);
            selectedWalletName.value = wallet.name;

            const accounts = (await wallet.provider.request({
                method: 'eth_requestAccounts',
            })) as string[];

            if (accounts.length === 0) {
                throw new Error('No accounts found');
            }

            address.value = accounts[0];
            isConnected.value = true;

            track('wallet_connected', {
                wallet_address: accounts[0],
                metadata: {
                    wallet_provider: wallet.name,
                    wallet_provider_source: wallet.source,
                },
            });

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
            selectEvmWalletProvider(null);
            selectedWalletName.value = null;

            return null;
        } finally {
            isConnecting.value = false;
        }
    };

    const connect = async (walletId?: string): Promise<string | null> => {
        const picked = walletId
            ? getEvmWalletProvider(walletId)
            : getDefaultWalletProvider();

        if (!picked) {
            error.value =
                'No EVM wallet detected. Install MetaMask, Rabby, Coinbase Wallet, Trust Wallet, OKX, or another injected EVM wallet — or connect a mobile wallet via WalletConnect.';

            return null;
        }

        return connectTo(picked);
    };

    const disconnect = (): void => {
        const selected = getSelectedEvmWalletProvider();

        // Injected wallets have no programmatic disconnect; WalletConnect
        // sessions must be killed or the pairing silently restores next visit.
        if (selected?.source === 'walletconnect') {
            selected.provider?.disconnect?.().catch(() => {});
        }

        address.value = null;
        isConnected.value = false;
        balance.value = null;
        chainId.value = null;
        error.value = null;
        cyberBalance.value = null;
        selectedWalletName.value = null;
        selectEvmWalletProvider(null);
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

    /**
     * Switch the connected wallet to `chain` (adding it when unknown). The
     * wallet's chainChanged event refreshes balances; chainId is re-fetched
     * here too in case the wallet does not emit it.
     *
     * A chain-locked wallet (Phantom EVM) is never asked about foreign
     * chains: the switch is handed to another installed wallet that can do
     * it — MetaMask first — and that wallet becomes the active provider.
     */
    const switchChain = async (chain: EvmChain): Promise<boolean> => {
        // The picker can be visible with only a saved DB address (no live
        // wallet session) — establish a real connection first so the switch
        // has a wallet to talk to and the site gets an account.
        if (!getSelectedEvmWalletProvider() && !(await connect())) {
            return false;
        }

        let injected = getProvider();

        if (!injected) {
            error.value = 'Wallet not connected';

            return false;
        }

        if (!evmProviderSupportsChain(injected, chain.chainId)) {
            const current = injected;
            const candidates = refreshWalletProviders().filter(
                (wallet) =>
                    wallet.provider &&
                    wallet.provider !== current &&
                    wallet.source !== 'walletconnect' &&
                    evmProviderSupportsChain(wallet.provider, chain.chainId),
            );
            const fallback =
                candidates.find((wallet) => wallet.rdns === 'io.metamask') ??
                candidates[0];

            if (!fallback) {
                error.value = `${selectedWalletName.value ?? 'This wallet'} cannot switch to ${chain.name} and no other EVM wallet is installed.`;

                return false;
            }

            if (!(await connectTo(fallback))) {
                return false;
            }

            injected = getProvider();

            if (!injected) {
                return false;
            }
        }

        try {
            await ensureEvmChain(injected, chain);
            await fetchChainId();

            return true;
        } catch (err) {
            error.value =
                err instanceof Error
                    ? err.message
                    : `Failed to switch to ${chain.name}`;

            return false;
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
        listenerProvider = injected;

        accountChangedHandler = (accounts: unknown) => {
            const accs = accounts as string[];

            if (accs.length === 0) {
                disconnect();
            } else if (accs[0] !== address.value) {
                address.value = accs[0];
                fetchBalance();
                fetchCyberBalance();
            }
        };

        chainChangedHandler = () => {
            fetchChainId();
            fetchBalance();
            fetchCyberBalance();
        };

        disconnectHandler = () => {
            disconnect();
        };

        injected.on('accountsChanged', accountChangedHandler);
        injected.on('chainChanged', chainChangedHandler);
        injected.on('disconnect', disconnectHandler);
    };

    const removeListeners = (): void => {
        const injected = listenerProvider;

        if (!injected) {
            return;
        }

        listenersSetup = false;

        if (accountChangedHandler) {
            injected.removeListener('accountsChanged', accountChangedHandler);
        }

        if (chainChangedHandler) {
            injected.removeListener('chainChanged', chainChangedHandler);
        }

        if (disconnectHandler) {
            injected.removeListener('disconnect', disconnectHandler);
        }

        listenerProvider = null;
        accountChangedHandler = null;
        chainChangedHandler = null;
        disconnectHandler = null;
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
        walletProviders,
        selectedWalletName,
        isMetaMaskInstalled,
        isEvmProviderInstalled,
        refreshWalletProviders,
        connect,
        disconnect,
        restore,
        signMessage,
        switchChain,
        fetchBalance,
        fetchCyberBalance,
        fetchChainId,
        formatAddress,
    };
};
