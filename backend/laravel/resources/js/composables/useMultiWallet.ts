import { computed, ref } from 'vue';
import {
    customWalletChain,
    deriveAccounts,
    forgetVault,
    hasVault,
    isValidMnemonic,
    openVault,
    parseUnits,
    mergeTokens,
    readCustomNetworks,
    readManualTokens,
    sameToken,
    saveVault,
    seedFromMnemonic,
    setCustomWalletChains,
    validateCustomNetwork,
    walletChain,
    walletChains,
    withToken,
    withoutToken,
    writeCustomNetworks,
    writeManualTokens,
} from '@/lib/wallet';
import type {
    CustomNetwork,
    CustomNetworkProblem,
    ManualToken,
    WalletAccount,
    WalletChainId,
    WalletFeeQuote,
    WalletFeeTier,
    WalletTokenBalance,
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

export type WalletTokens = {
    items: WalletTokenBalance[];
    loading: boolean;
    error: string | null;
};

let phrase: string | null = null;

const customNetworks = ref<CustomNetwork[]>([]);
const accounts = ref<WalletAccount[]>([]);
const exists = ref(false);
const unlocked = ref(false);
const balances = ref<Record<string, WalletBalance>>({});
const history = ref<Record<string, WalletHistory>>({});
const tokens = ref<Record<string, WalletTokens>>({});
const manualTokens = ref<ManualToken[]>([]);
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

/**
 * Fee quotes are keyed by what is being moved, not only by where.
 *
 * A token transfer is a contract call and costs several times what moving the
 * coin does, so one quote per chain would price a USDC transfer as if it were
 * a CYBER one — and the sentence above the signature would be wrong.
 */
const feeKey = (chain: WalletChainId, token: string | null): string =>
    token ? `${chain}:${token.toLowerCase()}` : chain;

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

    /**
     * Publish the stored networks to the chain registry and re-derive.
     *
     * The adapters are rebuilt from the records rather than kept alongside
     * them, so a network is only ever as trustworthy as what the user typed —
     * there is no cached adapter that could outlive a removal.
     */
    const syncCustomNetworks = (networks: CustomNetwork[]): void => {
        customNetworks.value = networks;
        setCustomWalletChains(networks.map(customWalletChain));
        load();
    };

    if (customNetworks.value.length === 0) {
        syncCustomNetworks(readCustomNetworks());
    }

    if (manualTokens.value.length === 0) {
        manualTokens.value = readManualTokens();
    }

    /**
     * Derive an account on a network the user describes. Returns what is wrong
     * with it, or null once it has been added — the seed is not touched, only
     * re-walked at a path this vault already covers.
     */
    const addNetwork = (draft: CustomNetwork): CustomNetworkProblem | null => {
        const problem = validateCustomNetwork(draft, walletChains());

        if (problem !== null) {
            return problem;
        }

        const next = [...customNetworks.value, draft];
        writeCustomNetworks(next);
        syncCustomNetworks(next);

        return null;
    };

    /**
     * Forget a network's settings. The account behind it stays derivable from
     * the seed forever — this removes an endpoint, never coins.
     */
    const removeNetwork = (id: WalletChainId): void => {
        const next = customNetworks.value.filter(
            (network) => network.id !== id,
        );
        writeCustomNetworks(next);
        syncCustomNetworks(next);
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
        tokens.value = {};
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
        // The network list goes with the keys. It is a record of what this
        // person held, and "delete local vault" has to mean the device keeps
        // nothing about them — the accounts themselves come back from the seed.
        writeCustomNetworks([]);
        syncCustomNetworks([]);
        writeManualTokens([]);
        manualTokens.value = [];
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
     * ERC20-style assets on one chain: whatever its index reports plus every
     * contract the user added by hand.
     *
     * The two sources are kept apart on purpose. An indexed token at zero is
     * noise the explorer happens to remember; a hand-added one at zero was an
     * explicit act and stays on the list until it is explicitly removed.
     */
    const refreshTokens = async (chainId: WalletChainId): Promise<void> => {
        const chain = walletChain(chainId);
        const account = accounts.value.find(
            (candidate) => candidate.chain === chainId,
        );

        if (!account || (!chain.fetchTokens && !chain.readToken)) {
            return;
        }

        const mine = manualTokens.value.filter(
            (entry) => entry.chain === chainId,
        );

        if (!chain.fetchTokens && mine.length === 0) {
            return;
        }

        tokens.value = {
            ...tokens.value,
            [chainId]: {
                items: tokens.value[chainId]?.items ?? [],
                loading: true,
                error: null,
            },
        };

        // A token the user asked for is read individually; one that fails to
        // read is dropped from this pass rather than taking the list with it,
        // because a dead contract must not hide the assets that do answer.
        const manual = await Promise.all(
            mine.map(async (entry) => {
                try {
                    return await chain.readToken?.(
                        entry.address,
                        account.address,
                        rpcFor(chainId),
                    );
                } catch {
                    return undefined;
                }
            }),
        );

        try {
            const indexed = chain.fetchTokens
                ? await chain.fetchTokens(account.address, rpcFor(chainId))
                : [];

            tokens.value = {
                ...tokens.value,
                [chainId]: {
                    items: mergeTokens(
                        indexed,
                        manual.filter(
                            (token): token is WalletTokenBalance =>
                                token !== undefined,
                        ),
                    ),
                    loading: false,
                    error: null,
                },
            };
        } catch (error) {
            tokens.value = {
                ...tokens.value,
                [chainId]: {
                    items: manual.filter(
                        (token): token is WalletTokenBalance =>
                            token !== undefined,
                    ),
                    loading: false,
                    error:
                        error instanceof Error
                            ? error.message
                            : 'Tokens unavailable',
                },
            };
        }
    };

    /**
     * Track a token by contract address. Returns null once it is on the list,
     * or why the contract could not be read — a wrong address is the common
     * case here, and it has to fail before anything is stored.
     */
    const addToken = async (
        chainId: WalletChainId,
        contract: string,
    ): Promise<string | null> => {
        const chain = walletChain(chainId);
        const account = accounts.value.find(
            (candidate) => candidate.chain === chainId,
        );

        if (!chain.readToken || !account) {
            return 'This network cannot hold tokens';
        }

        try {
            // Read it before storing it: a contract that cannot answer
            // symbol() and decimals() is not a token this wallet can render.
            await chain.readToken(contract, account.address, rpcFor(chainId));
        } catch {
            return 'That address did not answer as a token contract';
        }

        const next = withToken(manualTokens.value, chainId, contract);
        manualTokens.value = next;
        writeManualTokens(next);
        await refreshTokens(chainId);

        return null;
    };

    /** Stop tracking a token. The balance stays on chain, only the row goes. */
    const removeToken = async (
        chainId: WalletChainId,
        contract: string,
    ): Promise<void> => {
        const next = withoutToken(manualTokens.value, chainId, contract);
        manualTokens.value = next;
        writeManualTokens(next);
        tokens.value = {
            ...tokens.value,
            [chainId]: {
                items: (tokens.value[chainId]?.items ?? []).filter(
                    (token) => !sameToken(token.address, contract),
                ),
                loading: false,
                error: tokens.value[chainId]?.error ?? null,
            },
        };
        await refreshTokens(chainId);
    };

    /**
     * Live fee tiers for one chain. A failure leaves the previous quote in
     * place and is reported by the absence of a fresh one — the send screen
     * refuses to build a transaction it cannot price.
     */
    const refreshFees = async (
        chainId: WalletChainId,
        token: string | null = null,
    ): Promise<void> => {
        const chain = walletChain(chainId);
        const account = accounts.value.find(
            (candidate) => candidate.chain === chainId,
        );

        if (!chain.fetchFees || !account) {
            return;
        }

        try {
            fees.value = {
                ...fees.value,
                [feeKey(chainId, token)]: await chain.fetchFees({
                    address: account.address,
                    rpcUrl: rpcFor(chainId),
                    token,
                }),
            };
        } catch {
            fees.value = { ...fees.value, [feeKey(chainId, token)]: [] };
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
        token: WalletTokenBalance | null = null,
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
                // A token counts in its own units, not the chain's: sending
                // 5 USDC on a six-decimal contract is 5_000_000, and using the
                // chain's eighteen would move a millionth of what was typed.
                amount: parseUnits(amount, token?.decimals ?? chain.decimals),
                tier,
                rpcUrl: rpcFor(chainId),
                token: token?.address ?? null,
            });
        } finally {
            busy.value = false;
        }
    };

    return {
        chains: computed(() => walletChains()),
        customNetworks: computed(() => customNetworks.value),
        addNetwork,
        removeNetwork,
        tokens: computed(() => tokens.value),
        manualTokens: computed(() => manualTokens.value),
        /** The quote for one asset — the native coin when `token` is null. */
        feesFor: (chainId: WalletChainId, token: string | null = null) =>
            fees.value[feeKey(chainId, token)] ?? [],
        refreshTokens,
        addToken,
        removeToken,
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
