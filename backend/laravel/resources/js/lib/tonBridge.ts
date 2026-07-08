/**
 * TON-side bridge deposits, signed in the user's wallet through TON Connect
 * (Tonkeeper & friends) — the automatic counterpart of the old paste-a-tx-hash
 * flow. Also does the client-side tonapi reads (balances, jetton wallets,
 * message→transaction resolution).
 */
import { Address, beginCell, Cell } from '@ton/core';

import { getTonConnectUI } from '@/composables/useTonWallet';
import type { PublicTokenChain } from '@/lib/bridgeConfig';

const TONAPI_BASE = 'https://tonapi.io';

const JETTON_TRANSFER_OP = 0x0f8a7ea5;

/** TON attached to a jetton-wallet call for processing fees (excess bounces back). */
const JETTON_TRANSFER_TON = 50_000_000n; // 0.05 TON

/** Native-TON reserve kept back from MAX so the wallet can pay message fees. */
export const TON_GAS_RESERVE = 0.05;

/** Human decimal string → raw smallest units (bigint-safe, no float math). */
export function toRawUnits(amount: string, decimals: number): bigint {
    const [whole, fraction = ''] = amount.trim().split('.');
    const paddedFraction = (fraction + '0'.repeat(decimals)).slice(0, decimals);

    return (
        BigInt(whole || '0') * 10n ** BigInt(decimals) +
        BigInt(paddedFraction || '0')
    );
}

/** Raw smallest units (string) → human decimal string. */
export function fromRawUnits(raw: string, decimals: number): string {
    const value = BigInt(raw);
    const base = 10n ** BigInt(decimals);
    const whole = value / base;
    const fraction = (value % base).toString().padStart(decimals, '0');

    return `${whole}.${fraction}`.replace(/\.?0+$/, '') || '0';
}

async function tonapiGet(path: string): Promise<Record<string, unknown> | null> {
    try {
        const response = await fetch(`${TONAPI_BASE}${path}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return null;
        }

        return (await response.json()) as Record<string, unknown>;
    } catch {
        return null;
    }
}

/** Native TON balance of an account, human units ('0' for uninit accounts). */
export async function fetchTonNativeBalance(
    owner: string,
): Promise<string | null> {
    const json = await tonapiGet(`/v2/accounts/${encodeURIComponent(owner)}`);

    if (!json) {
        return null;
    }

    const balance = json.balance;

    if (typeof balance !== 'number' && typeof balance !== 'string') {
        return '0';
    }

    return fromRawUnits(String(balance), 9);
}

/**
 * Jetton balance + the owner's jetton-wallet address (needed to build the
 * transfer). null balance data means "no jetton wallet yet" (balance 0).
 */
export async function fetchTonJettonBalance(
    owner: string,
    master: string,
): Promise<{ balance: string; walletAddress: string | null }> {
    const json = await tonapiGet(
        `/v2/accounts/${encodeURIComponent(owner)}/jettons/${encodeURIComponent(master)}`,
    );

    if (!json) {
        return { balance: '0', walletAddress: null };
    }

    const balance = typeof json.balance === 'string' ? json.balance : '0';
    const wallet = json.wallet_address as { address?: string } | undefined;

    return {
        balance,
        walletAddress: wallet?.address ?? null,
    };
}

/**
 * Resolve the external-message hash (all TON Connect returns) to the indexed
 * transaction hash by polling tonapi. TON finality is ~5–15 s; give it two
 * minutes before falling back.
 */
export async function resolveTonTxHash(
    msgHash: string,
    attempts = 24,
    delayMs = 5000,
): Promise<string | null> {
    for (let attempt = 0; attempt < attempts; attempt++) {
        const json = await tonapiGet(
            `/v2/blockchain/messages/${msgHash}/transaction`,
        );

        const hash = json?.hash;

        if (typeof hash === 'string' && /^[0-9a-f]{64}$/i.test(hash)) {
            return hash.toLowerCase();
        }

        await new Promise((resolve) => setTimeout(resolve, delayMs));
    }

    return null;
}

/**
 * Send a bridge deposit from the connected TON wallet: a plain value transfer
 * for native TON, a standard TEP-74 jetton transfer for jettons. Returns the
 * source tx hash for /bridge/submit — the resolved transaction hash when
 * tonapi indexes it in time, otherwise the external-message hash (the backend
 * re-resolves it).
 */
export async function sendTonDeposit(options: {
    tokenEntry: PublicTokenChain;
    amount: string;
    depositAddress: string;
    senderRawAddress: string;
}): Promise<{ txHash: string; nonce: number }> {
    const ui = await getTonConnectUI();
    const amountRaw = toRawUnits(options.amount, options.tokenEntry.decimals);

    if (amountRaw <= 0n) {
        throw new Error('Amount is zero');
    }

    let message: { address: string; amount: string; payload?: string };

    if (options.tokenEntry.native) {
        message = {
            address: options.depositAddress,
            amount: amountRaw.toString(),
        };
    } else {
        if (!options.tokenEntry.master) {
            throw new Error('Token is not configured on TON');
        }

        const { walletAddress } = await fetchTonJettonBalance(
            options.senderRawAddress,
            options.tokenEntry.master,
        );

        if (!walletAddress) {
            throw new Error(
                'Could not find your jetton wallet — does this account hold the token?',
            );
        }

        const body = beginCell()
            .storeUint(JETTON_TRANSFER_OP, 32)
            .storeUint(0n, 64) // query_id (deposits are matched by tx, not id)
            .storeCoins(amountRaw)
            .storeAddress(Address.parse(options.depositAddress))
            .storeAddress(Address.parse(options.senderRawAddress)) // excess back
            .storeBit(false) // no custom payload
            .storeCoins(1n) // forward_ton_amount: 1 nanoton notification
            .storeBit(false) // no forward payload
            .endCell();

        message = {
            address: walletAddress,
            amount: JETTON_TRANSFER_TON.toString(),
            payload: body.toBoc().toString('base64'),
        };
    }

    const result = await ui.sendTransaction({
        validUntil: Math.floor(Date.now() / 1000) + 360,
        messages: [message],
    });

    // Hash of the signed external message — resolvable to the tx hash once
    // tonapi indexes it.
    const msgHash = Cell.fromBase64(result.boc).hash().toString('hex');
    const txHash = await resolveTonTxHash(msgHash);

    return { txHash: txHash ?? msgHash, nonce: 0 };
}
