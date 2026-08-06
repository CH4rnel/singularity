import { computed, ref } from 'vue';
import {
    WALLET_CHAINS,
    deriveAccounts,
    forgetVault,
    hasVault,
    isValidMnemonic,
    openVault,
    parseUnits,
    saveVault,
    seedFromMnemonic,
    walletChain,
} from '@/lib/wallet';
import type {
    WalletAccount,
    WalletChainId,
    WalletFeeQuote,
    WalletFeeTier,
    WalletTx,
} from '@/lib/wallet';

/**
 * Session state of the unified multichain wallet.
 *
 * The decrypted phrase lives in a plain module variable, deliberately not in a
 * ref: reactive state ends up in Vue devtools and in every component that
 * touches it. Nothing here returns the phrase except `reveal()`, which asks
 * for the password again, and nothing here ever logs. Locking drops the
 * phrase, so a locked tab holds no key material at all.
 */

export type WalletBalance = {
    value: bigint | null;
    loading: boolean;
    error: string | null;
};

export type WalletHistory = {
    items: WalletTx[];
    loading: boolean;
    error: string | null;
};

let phrase: string | null = null;

const accounts = ref<WalletAccount[]>([]);
const exists = ref(false);
const unlocked = ref(false);
const balances = ref<Record<string, WalletBalance>>({});
const history = ref<Record<string, WalletHistory>>({});
const fees = ref<Record<string, WalletFeeQuote[]>>({});
const busy = ref(false);

/** Minutes of inactivity after which the vault seals itself. 0 disables it. */
const autoLockMinutes = ref(15);
const lastActivity = ref(Date.now());
let autoLockTimer: ReturnType<typeof setInterval> | null = null;

/**
 * Per-chain RPC overrides. Every chain adapter already carries a public
 * default, so this is only for the endpoints the server prefers we use.
 */
export type WalletRpcEndpoints = Partial<Record<WalletChainId, string>>;

/** The session object the wallet page hands to its screens. */
export type MultiWallet = ReturnType<typeof useMultiWallet>;

export const useMultiWallet = (rpc: WalletRpcEndpoints = {}) => {
    const refreshExists = (): void => {
        exists.value = typeof window !== 'undefined' && hasVault();
    };

    refreshExists();

    const load = (): void => {
        accounts.value = phrase ? deriveAccounts(phrase) : [];
        unlocked.value = phrase !== null;
    };

    const rpcFor = (chain: WalletChainId): string | undefined => rpc[chain];

    /**
     * Restart the idle countdown. The page calls this on real interaction, so
     * a wallet left open on a shared screen seals itself while one that is
     * being used stays open.
     */
    const touch = (): void => {
        lastActivity.value = Date.now();
    };

    /**
     * Seal a phrase into this device's vault and open it. Both onboarding
     * paths end here: a phrase this device generated and one the user typed
     * in are the same thing by the time they are stored.
     */
    const adopt = async (
        candidate: string,
        password: string,
    ): Promise<void> => {
        busy.value = true;

        try {
            await saveVault(candidate, password);
            phrase = candidate;
            touch();
            refreshExists();
            load();
        } finally {
            busy.value = false;
        }
    };

    const unlock = async (password: string): Promise<void> => {
        busy.value = true;

        try {
            phrase = await openVault(password);
            touch();
            load();
        } finally {
            busy.value = false;
        }
    };

    const lock = (): void => {
        phrase = null;
        accounts.value = [];
        balances.value = {};
        history.value = {};
        unlocked.value = false;
    };

    /** Change the idle limit and restart the countdown against it. */
    const setAutoLock = (minutes: number): void => {
        autoLockMinutes.value = minutes;
        touch();
    };

    const startAutoLock = (): void => {
        if (autoLockTimer !== null || typeof window === 'undefined') {
            return;
        }

        autoLockTimer = setInterval(() => {
            const limit = autoLockMinutes.value * 60_000;

            if (
                unlocked.value &&
                limit > 0 &&
                Date.now() - lastActivity.value >= limit
            ) {
                lock();
            }
        }, 10_000);
    };

    startAutoLock();

    /** The backup phrase, behind a fresh password check. */
    const reveal = (password: string): Promise<string> => openVault(password);

    const forget = (): void => {
        forgetVault();
        lock();
        refreshExists();
    };

    const refreshBalances = async (): Promise<void> => {
        await Promise.all(
            accounts.value
                .filter((account) => account.capabilities.balance)
                .map(async (account) => {
                    const chain = walletChain(account.chain);

                    balances.value = {
                        ...balances.value,
                        [account.chain]: {
                            value: balances.value[account.chain]?.value ?? null,
                            loading: true,
                            error: null,
                        },
                    };

                    try {
                        const value = await chain.fetchBalance!(
                            account.address,
                            rpcFor(account.chain),
                        );

                        balances.value = {
                            ...balances.value,
                            [account.chain]: {
                                value,
                                loading: false,
                                error: null,
                            },
                        };
                    } catch (error) {
                        balances.value = {
                            ...balances.value,
                            [account.chain]: {
                                value: null,
                                loading: false,
                                error:
                                    error instanceof Error
                                        ? error.message
                                        : 'Balance unavailable',
                            },
                        };
                    }
                }),
        );
    };

    /** Recent transfers for one chain, from its own indexer or RPC. */
    const refreshHistory = async (chainId: WalletChainId): Promise<void> => {
        const chain = walletChain(chainId);
        const account = accounts.value.find(
            (candidate) => candidate.chain === chainId,
        );

        if (!chain.fetchHistory || !account) {
            return;
        }

        history.value = {
            ...history.value,
            [chainId]: {
                items: history.value[chainId]?.items ?? [],
                loading: true,
                error: null,
            },
        };

        try {
            const items = await chain.fetchHistory(
                account.address,
                rpcFor(chainId),
            );

            history.value = {
                ...history.value,
                [chainId]: { items, loading: false, error: null },
            };
        } catch (error) {
            history.value = {
                ...history.value,
                [chainId]: {
                    items: [],
                    loading: false,
                    error:
                        error instanceof Error
                            ? error.message
                            : 'History unavailable',
                },
            };
        }
    };

    /**
     * Live fee tiers for one chain. A failure leaves the previous quote in
     * place and is reported by the absence of a fresh one — the send screen
     * refuses to build a transaction it cannot price.
     */
    const refreshFees = async (chainId: WalletChainId): Promise<void> => {
        const chain = walletChain(chainId);

        if (!chain.fetchFees) {
            return;
        }

        try {
            fees.value = {
                ...fees.value,
                [chainId]: await chain.fetchFees(rpcFor(chainId)),
            };
        } catch {
            fees.value = { ...fees.value, [chainId]: [] };
        }
    };

    /**
     * Broadcast a payment. The caller is expected to have shown the user a
     * confirmation step first — this is the point of no return.
     */
    const send = async (
        chainId: WalletChainId,
        to: string,
        amount: string,
        tier: WalletFeeTier = 'normal',
    ): Promise<string> => {
        if (!phrase) {
            throw new Error('Wallet is locked');
        }

        const chain = walletChain(chainId);

        if (!chain.send) {
            throw new Error(`${chain.label} payments are not supported here`);
        }

        if (!chain.isValidAddress(to.trim())) {
            throw new Error(`Not a valid ${chain.label} address`);
        }

        busy.value = true;

        try {
            return await chain.send(seedFromMnemonic(phrase), {
                to: to.trim(),
                amount: parseUnits(amount, chain.decimals),
                tier,
                rpcUrl: rpcFor(chainId),
            });
        } finally {
            busy.value = false;
        }
    };

    return {
        chains: WALLET_CHAINS,
        accounts: computed(() => accounts.value),
        balances: computed(() => balances.value),
        history: computed(() => history.value),
        fees: computed(() => fees.value),
        exists: computed(() => exists.value),
        unlocked: computed(() => unlocked.value),
        busy: computed(() => busy.value),
        autoLockMinutes: computed(() => autoLockMinutes.value),
        setAutoLock,
        isValidMnemonic,
        adopt,
        unlock,
        lock,
        touch,
        reveal,
        forget,
        refreshBalances,
        refreshHistory,
        refreshFees,
        send,
    };
};
