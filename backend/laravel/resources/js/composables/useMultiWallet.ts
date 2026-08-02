import { computed, ref } from 'vue';
import {
    WALLET_CHAINS,
    createMnemonic,
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
import type { WalletAccount, WalletChainId } from '@/lib/wallet';

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

let phrase: string | null = null;

const accounts = ref<WalletAccount[]>([]);
const exists = ref(false);
const unlocked = ref(false);
const balances = ref<Record<string, WalletBalance>>({});
const busy = ref(false);

export type WalletRpcEndpoints = {
    solana?: string;
    cyberia?: string;
};

export const useMultiWallet = (rpc: WalletRpcEndpoints = {}) => {
    const refreshExists = (): void => {
        exists.value = typeof window !== 'undefined' && hasVault();
    };

    refreshExists();

    const load = (): void => {
        accounts.value = phrase ? deriveAccounts(phrase) : [];
        unlocked.value = phrase !== null;
    };

    const rpcFor = (chain: WalletChainId): string | undefined =>
        chain === 'solana' ? rpc.solana : rpc.cyberia;

    /** Seal a phrase into this device's vault and open it. */
    const adopt = async (
        candidate: string,
        password: string,
    ): Promise<void> => {
        busy.value = true;

        try {
            await saveVault(candidate, password);
            phrase = candidate;
            refreshExists();
            load();
        } finally {
            busy.value = false;
        }
    };

    const create = async (
        password: string,
        words: 12 | 24 = 12,
    ): Promise<string> => {
        const created = createMnemonic(words);

        await adopt(created, password);

        return created;
    };

    const restore = (candidate: string, password: string): Promise<void> =>
        adopt(candidate, password);

    const unlock = async (password: string): Promise<void> => {
        busy.value = true;

        try {
            phrase = await openVault(password);
            load();
        } finally {
            busy.value = false;
        }
    };

    const lock = (): void => {
        phrase = null;
        accounts.value = [];
        balances.value = {};
        unlocked.value = false;
    };

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

    /**
     * Broadcast a payment. The caller is expected to have shown the user a
     * confirmation step first — this is the point of no return.
     */
    const send = async (
        chainId: WalletChainId,
        to: string,
        amount: string,
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
        exists: computed(() => exists.value),
        unlocked: computed(() => unlocked.value),
        busy: computed(() => busy.value),
        isValidMnemonic,
        create,
        restore,
        unlock,
        lock,
        reveal,
        forget,
        refreshBalances,
        send,
    };
};
