import { Buffer } from 'buffer';
import {
    createAssociatedTokenAccountInstruction,
    createTransferCheckedInstruction,
    getAccount,
    getAssociatedTokenAddress,
    TOKEN_2022_PROGRAM_ID,
    TOKEN_PROGRAM_ID,
} from '@solana/spl-token';
import {
    Connection,
    PublicKey,
    Transaction,
    TransactionInstruction,
} from '@solana/web3.js';
import { ref } from 'vue';
import type { SolanaTransactionProvider } from '@/lib/solanaWalletProvider';
import { state as stakingState } from '@/routes/staking/solana';
import { store as claimStore } from '@/routes/staking/solana/claims';
import {
    confirm as depositConfirm,
    prepare as depositPrepare,
} from '@/routes/staking/solana/deposits';
import { store as withdrawalStore } from '@/routes/staking/solana/withdrawals';

const MEMO_PROGRAM = new PublicKey(
    'MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr',
);
const PENDING_DEPOSIT_KEY = 'cyberia-staking-pending-deposit';

export type SolanaStakingConfig = {
    enabled: boolean;
    cluster: 'mainnet' | 'devnet';
    rpc_url: string;
    treasury_address: string;
    cyber_sol_mint: string;
    cyber_sol_decimals: number;
    token_program: 'token' | 'token-2022';
    ash_decimals: number;
    ash_per_cyber_per_day: string;
    withdrawals_enabled: boolean;
    claims_enabled: boolean;
};

export type SolanaStakingTransaction = {
    uuid: string;
    type: 'deposit' | 'withdrawal' | 'reward_claim';
    amount_raw: string;
    tx_hash: string | null;
    status: 'prepared' | 'processing' | 'completed' | 'needs_review';
    error_message: string | null;
    created_at?: string | null;
};

export type SolanaStakingPosition = {
    solana_address: string;
    principal_raw: string;
    available_principal_raw: string;
    accrued_ash_raw: string;
    available_ash_raw: string;
    total_deposited_raw: string;
    total_withdrawn_raw: string;
    total_claimed_ash_raw: string;
    transactions: SolanaStakingTransaction[];
};

type PendingDeposit = { uuid: string; txHash: string };

const config = ref<SolanaStakingConfig | null>(null);
const position = ref<SolanaStakingPosition | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);
const pendingDeposit = ref<PendingDeposit | null>(null);

const csrfToken = (): string => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

const responseJson = async <T>(response: Response): Promise<T> => {
    const body = (await response.json().catch(() => ({}))) as T & {
        message?: string;
    };

    if (!response.ok) {
        throw new Error(body.message ?? `Request failed (${response.status})`);
    }

    return body;
};

const post = async <T>(url: string, body: Record<string, string> = {}) =>
    responseJson<T>(
        await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }),
    );

export const rawTokenAmount = (value: string, decimals: number): string => {
    const normalized = value.trim();

    if (!/^\d+(?:\.\d+)?$/.test(normalized)) {
        throw new Error('Enter a valid token amount');
    }

    const [whole, fraction = ''] = normalized.split('.');

    if (fraction.length > decimals) {
        throw new Error(`Amount supports at most ${decimals} decimal places`);
    }

    return (
        BigInt(whole) * 10n ** BigInt(decimals) +
        BigInt((fraction + '0'.repeat(decimals)).slice(0, decimals) || '0')
    ).toString();
};

export const formatRawTokenAmount = (
    raw: string,
    decimals: number,
    visibleDecimals = 6,
): string => {
    const value = BigInt(raw || '0');
    const scale = 10n ** BigInt(decimals);
    const whole = value / scale;
    const fraction = (value % scale)
        .toString()
        .padStart(decimals, '0')
        .slice(0, visibleDecimals)
        .replace(/0+$/, '');

    return fraction ? `${whole}.${fraction}` : whole.toString();
};

export const useSolanaStaking = () => {
    const setInitialState = (
        initialConfig: SolanaStakingConfig,
        initialPosition: SolanaStakingPosition | null,
    ): void => {
        config.value = initialConfig;
        position.value = initialPosition;

        try {
            const saved = localStorage.getItem(PENDING_DEPOSIT_KEY);
            pendingDeposit.value = saved
                ? (JSON.parse(saved) as PendingDeposit)
                : null;
        } catch {
            pendingDeposit.value = null;
        }
    };

    const refresh = async (): Promise<void> => {
        const response = await responseJson<{
            config: SolanaStakingConfig;
            position: SolanaStakingPosition | null;
        }>(
            await fetch(stakingState().url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            }),
        );
        config.value = response.config;
        position.value = response.position;
    };

    const confirmPendingDeposit = async (): Promise<void> => {
        if (!pendingDeposit.value) {
            return;
        }

        const response = await post<{
            position: SolanaStakingPosition;
        }>(depositConfirm().url, {
            uuid: pendingDeposit.value.uuid,
            tx_hash: pendingDeposit.value.txHash,
        });
        position.value = response.position;
        pendingDeposit.value = null;
        localStorage.removeItem(PENDING_DEPOSIT_KEY);
    };

    const deposit = async (
        wallet: SolanaTransactionProvider,
        amountRaw: string,
    ): Promise<void> => {
        if (!config.value?.enabled) {
            throw new Error('CYBER.sol staking is not enabled');
        }

        busy.value = true;
        error.value = null;

        try {
            const prepared = await post<{
                deposit: {
                    uuid: string;
                    amount_raw: string;
                    memo: string;
                    treasury_address: string;
                    mint: string;
                    decimals: number;
                    token_program: 'token' | 'token-2022';
                    cluster: 'mainnet' | 'devnet';
                    rpc_url: string;
                };
            }>(depositPrepare().url, { amount_raw: amountRaw });
            const request = prepared.deposit;
            const connection = new Connection(request.rpc_url, 'confirmed');
            const owner = new PublicKey(wallet.publicKey.toBase58());
            const treasury = new PublicKey(request.treasury_address);
            const mint = new PublicKey(request.mint);
            const tokenProgram =
                request.token_program === 'token-2022'
                    ? TOKEN_2022_PROGRAM_ID
                    : TOKEN_PROGRAM_ID;
            const ownerAta = await getAssociatedTokenAddress(
                mint,
                owner,
                false,
                tokenProgram,
            );
            const treasuryAta = await getAssociatedTokenAddress(
                mint,
                treasury,
                false,
                tokenProgram,
            );
            const transaction = new Transaction();

            try {
                await getAccount(
                    connection,
                    treasuryAta,
                    'confirmed',
                    tokenProgram,
                );
            } catch {
                transaction.add(
                    createAssociatedTokenAccountInstruction(
                        owner,
                        treasuryAta,
                        treasury,
                        mint,
                        tokenProgram,
                    ),
                );
            }

            transaction.add(
                createTransferCheckedInstruction(
                    ownerAta,
                    mint,
                    treasuryAta,
                    owner,
                    BigInt(request.amount_raw),
                    request.decimals,
                    [],
                    tokenProgram,
                ),
                new TransactionInstruction({
                    programId: MEMO_PROGRAM,
                    keys: [
                        { pubkey: owner, isSigner: true, isWritable: false },
                    ],
                    data: Buffer.from(request.memo, 'utf8'),
                }),
            );

            const { blockhash, lastValidBlockHeight } =
                await connection.getLatestBlockhash('confirmed');
            transaction.recentBlockhash = blockhash;
            transaction.feePayer = owner;

            const { signature } = await wallet.signAndSendTransaction(
                transaction,
                { preflightCommitment: 'confirmed', maxRetries: 5 },
            );
            pendingDeposit.value = {
                uuid: request.uuid,
                txHash: signature,
            };
            localStorage.setItem(
                PENDING_DEPOSIT_KEY,
                JSON.stringify(pendingDeposit.value),
            );
            await connection.confirmTransaction(
                { signature, blockhash, lastValidBlockHeight },
                'confirmed',
            );
            await confirmPendingDeposit();
        } catch (cause) {
            error.value =
                cause instanceof Error ? cause.message : String(cause);

            throw cause;
        } finally {
            busy.value = false;
        }
    };

    const withdraw = async (amountRaw: string): Promise<void> => {
        busy.value = true;
        error.value = null;

        try {
            const response = await post<{
                position: SolanaStakingPosition;
            }>(withdrawalStore().url, { amount_raw: amountRaw });
            position.value = response.position;
        } catch (cause) {
            error.value =
                cause instanceof Error ? cause.message : String(cause);

            throw cause;
        } finally {
            busy.value = false;
        }
    };

    const claim = async (): Promise<void> => {
        busy.value = true;
        error.value = null;

        try {
            const response = await post<{
                position: SolanaStakingPosition;
            }>(claimStore().url);
            position.value = response.position;
        } catch (cause) {
            error.value =
                cause instanceof Error ? cause.message : String(cause);

            throw cause;
        } finally {
            busy.value = false;
        }
    };

    return {
        config,
        position,
        busy,
        error,
        pendingDeposit,
        setInitialState,
        refresh,
        deposit,
        confirmPendingDeposit,
        withdraw,
        claim,
    };
};
