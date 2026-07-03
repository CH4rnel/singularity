import {
    createAssociatedTokenAccountInstruction,
    createTransferCheckedInstruction,
    getAccount,
    getAssociatedTokenAddress,
    TOKEN_2022_PROGRAM_ID,
    TOKEN_PROGRAM_ID,
} from '@solana/spl-token';
import { Connection, PublicKey, Transaction } from '@solana/web3.js';
import { ref } from 'vue';

export type SlotToken = {
    mint: string;
    symbol: string | null;
    decimals: number;
    logo_url: string | null;
    weight: number;
    balance: string;
    min_bet: string;
    max_bet: string | null;
    token_program: 'token' | 'token-2022';
};

export type PoolState = {
    pool_id: number;
    hot_wallet: string;
    burn_bps: number;
    house_edge_bps: number;
    jackpot_bps: number;
    cluster: 'devnet' | 'mainnet';
    rpc_url: string;
    tokens: SlotToken[];
};

export type PrizeLine = {
    mint: string;
    amount: string;
    decimals: number;
    symbol: string | null;
};

export type SpinResult = {
    spin_id: number;
    outcome_type: 'pending' | 'loss' | 'win' | 'jackpot';
    reels: string[][];
    prize_payload: PrizeLine[];
    payout_tx_hash: string | null;
    burn_amount: string | null;
    server_seed: string;
    server_seed_hash: string;
    client_seed: string;
    nonce: number;
};

interface PhantomLike {
    publicKey: { toBase58(): string } | null;
    signAndSendTransaction(
        tx: Transaction,
        opts?: object,
    ): Promise<{ signature: string }>;
}

const pool = ref<PoolState | null>(null);
const isLoading = ref(false);
const isSpinning = ref(false);
const lastResult = ref<SpinResult | null>(null);
const error = ref<string | null>(null);

export const useSlotMachine = (rpcUrlOverride?: string) => {
    const loadPool = async () => {
        isLoading.value = true;
        error.value = null;

        try {
            const r = await fetch('/api/slots/pool');

            if (!r.ok) {
                throw new Error(`pool: ${r.status}`);
            }

            pool.value = await r.json();
        } catch (e) {
            error.value = (e as Error).message;
        } finally {
            isLoading.value = false;
        }
    };

    const resolveProgram = (name: SlotToken['token_program']): PublicKey =>
        name === 'token-2022' ? TOKEN_2022_PROGRAM_ID : TOKEN_PROGRAM_ID;

    const spin = async (
        phantom: PhantomLike,
        betMint: string,
        betAmountRaw: string,
        clientSeed: string,
    ): Promise<SpinResult> => {
        if (!pool.value) {
            throw new Error('Pool not loaded');
        }

        if (!phantom.publicKey) {
            throw new Error('Wallet not connected');
        }

        const token = pool.value.tokens.find((t) => t.mint === betMint);

        if (!token) {
            throw new Error('Bet mint not in pool');
        }

        isSpinning.value = true;
        error.value = null;
        lastResult.value = null;

        try {
            const userAddress = phantom.publicKey.toBase58();

            const prepareRes = await fetch('/api/slots/spin/prepare', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    wallet_address: userAddress,
                    bet_mint: betMint,
                    bet_amount: betAmountRaw,
                    client_seed: clientSeed,
                }),
            });

            if (!prepareRes.ok) {
                const body = await prepareRes.json().catch(() => ({}));

                throw new Error(body.error ?? `prepare: ${prepareRes.status}`);
            }

            const prep = (await prepareRes.json()) as {
                spin_id: number;
                deposit_address: string;
                expected_amount: string;
            };

            const rpcUrl = rpcUrlOverride ?? pool.value.rpc_url;
            const connection = new Connection(rpcUrl, 'confirmed');
            const userPubkey = new PublicKey(userAddress);
            const program = resolveProgram(token.token_program);
            const mint = new PublicKey(betMint);
            const hotWallet = new PublicKey(prep.deposit_address);

            const userAta = await getAssociatedTokenAddress(
                mint,
                userPubkey,
                false,
                program,
            );
            const hotAta = await getAssociatedTokenAddress(
                mint,
                hotWallet,
                false,
                program,
            );

            const tx = new Transaction();

            try {
                await getAccount(connection, hotAta, 'confirmed', program);
            } catch {
                tx.add(
                    createAssociatedTokenAccountInstruction(
                        userPubkey,
                        hotAta,
                        hotWallet,
                        mint,
                        program,
                    ),
                );
            }

            tx.add(
                createTransferCheckedInstruction(
                    userAta,
                    mint,
                    hotAta,
                    userPubkey,
                    BigInt(prep.expected_amount),
                    token.decimals,
                    [],
                    program,
                ),
            );

            const { blockhash, lastValidBlockHeight } =
                await connection.getLatestBlockhash('confirmed');
            tx.recentBlockhash = blockhash;
            tx.feePayer = userPubkey;

            const { signature } = await phantom.signAndSendTransaction(tx);
            await connection.confirmTransaction(
                { signature, blockhash, lastValidBlockHeight },
                'confirmed',
            );

            const confirmRes = await fetch('/api/slots/spin/confirm', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    spin_id: prep.spin_id,
                    deposit_tx_hash: signature,
                }),
            });

            if (!confirmRes.ok) {
                const body = await confirmRes.json().catch(() => ({}));

                throw new Error(body.error ?? `confirm: ${confirmRes.status}`);
            }

            const result = (await confirmRes.json()) as SpinResult;
            lastResult.value = result;
            await loadPool(); // refresh weights post-settle

            return result;
        } catch (e) {
            error.value = (e as Error).message;

            throw e;
        } finally {
            isSpinning.value = false;
        }
    };

    return { pool, isLoading, isSpinning, lastResult, error, loadPool, spin };
};
