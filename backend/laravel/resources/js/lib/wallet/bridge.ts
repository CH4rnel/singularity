import { Contract, JsonRpcProvider } from 'ethers';
import type {
    PublicChain,
    PublicRouteData,
    PublicToken,
} from '@/lib/bridgeConfig';
import { evmSigner } from '@/lib/wallet/keys';
import type { WalletKeySource } from '@/lib/wallet/keys';

/**
 * Bridging from inside the wallet.
 *
 * A transfer across chains here is two acts by two different parties, and the
 * screen is honest about the seam. The wallet performs the first: it signs an
 * ordinary transfer of the asset to the bridge's deposit address on the source
 * chain, with its own key, and nothing about that transaction is special —
 * it is a send with a known recipient. Cyberia's relayer performs the second,
 * paying out on the destination against that deposit. Between the two there is
 * no cancel, which is the one sentence this whole file exists to earn.
 *
 * What the wallet will sign is deliberately narrow: a *deposit* on an EVM
 * source chain, either the coin itself or an ERC-20. Corridors that lock
 * through the bridge contract (CYBER against CYBER.sol), or that start on
 * Solana, TON or a UTXO chain, are listed and explained rather than half-built
 * — a corridor whose lock leg this wallet cannot construct correctly is one
 * where a signature would send funds to an address that will not credit them.
 *
 * The registry itself is the server's (`BridgeConfigService`), the same tables
 * /bridge renders, so a corridor opened in config opens in both places at once
 * and this file never carries its own idea of what is live.
 */

const ERC20_ABI = [
    'function transfer(address to, uint256 amount) returns (bool)',
];

/*
 * The gas a deposit is signed for is not decided here: an ERC-20 leg uses
 * `ERC20_TRANSFER_GAS_CAP` and a coin leg uses `nativeSendGas()`, which reads
 * `eth_getCode` on the deposit address first. On Cyberia a coin transfer *to a
 * contract* runs its `receive()` on the sender's gas — the failure that once
 * cost the gas station its first funding — and this chain's `eth_estimateGas`
 * answers 21000 either way, so the recipient is read rather than estimated.
 */

export type BridgeConfig = {
    chains: PublicChain[];
    routes: PublicRouteData[];
    tokens: PublicToken[];
    /** Fallback deposit EOA for EVM chains that name none of their own. */
    relayer: string | null;
    feeBps: number;
    feeFlatUsd: number;
};

/** Why a corridor cannot be used from this wallet, or 'ok'. */
export type BridgeBlock =
    | 'ok'
    | 'closed'
    | 'noAccount'
    | 'sourceUnsupported'
    | 'contractLock'
    | 'noDeposit';

export type BridgeOption = {
    direction: string;
    source: string;
    destination: string;
    sourceLabel: string;
    destinationLabel: string;
    /** Wallet chain the source leg would be signed on, when there is one. */
    sourceChainId: number | null;
    tokens: string[];
    block: BridgeBlock;
    /** The server's own words for a corridor it is not currently running. */
    unavailableReason: string | null;
};

const chainOf = (config: BridgeConfig, key: string): PublicChain | null =>
    config.chains.find((chain) => chain.key === key) ?? null;

const tokenOf = (config: BridgeConfig, symbol: string): PublicToken | null =>
    config.tokens.find((token) => token.symbol === symbol) ?? null;

/**
 * Where a deposit on this chain goes.
 *
 * The chain's own address when it names one, and the relayer EOA otherwise —
 * which is what the bridge page does, and what the relayer watches. Null is a
 * corridor nobody has funded an address for, and no amount of UI can fix that
 * from here.
 */
export const depositAddressFor = (
    config: BridgeConfig,
    chainKey: string,
): string | null => chainOf(config, chainKey)?.depositAddress ?? config.relayer;

/**
 * Whether the wallet can construct the lock leg of one corridor for one token.
 *
 * `contractLock` is the interesting refusal. A token whose model is `native`
 * does not move by transfer at all — it goes through the bridge contract, which
 * emits the nonce the relayer matches the payout to. Sending it to the deposit
 * address instead would be a transfer the relayer never credits, so the answer
 * is no rather than an attempt.
 */
export const bridgeBlockFor = (
    config: BridgeConfig,
    route: PublicRouteData,
    symbol: string | null,
    walletChainIds: number[],
): BridgeBlock => {
    if (route.operational === false) {
        return 'closed';
    }

    const source = chainOf(config, route.source);

    if (!source || source.type !== 'evm') {
        return 'sourceUnsupported';
    }

    if (
        source.evmChainId === null ||
        !walletChainIds.includes(source.evmChainId)
    ) {
        return 'noAccount';
    }

    if (depositAddressFor(config, route.source) === null) {
        return 'noDeposit';
    }

    if (symbol !== null && tokenOf(config, symbol)?.model === 'native') {
        return 'contractLock';
    }

    return 'ok';
};

/**
 * Every corridor, with the reason it cannot be used where it cannot.
 *
 * Closed and unsupported corridors stay in the list on purpose: "this bridge
 * does not go there" and "this bridge goes there but not from this app" are
 * different answers, and a list that silently dropped both would look like a
 * bridge that does less than it does.
 */
export const bridgeOptions = (
    config: BridgeConfig,
    walletChainIds: number[],
): BridgeOption[] =>
    config.routes.map((route) => {
        const source = chainOf(config, route.source);

        return {
            direction: route.direction,
            source: route.source,
            destination: route.destination,
            sourceLabel: route.sourceLabel,
            destinationLabel: route.destinationLabel,
            sourceChainId: source?.evmChainId ?? null,
            tokens: route.tokens ?? [],
            block: bridgeBlockFor(config, route, null, walletChainIds),
            unavailableReason: route.unavailableReason ?? null,
        };
    });

/**
 * The bridge's own cut, in the asset being sent.
 *
 * Flat USD plus a rate; the flat half needs a price and is left to the caller,
 * because a fee this wallet cannot price is stated as unknown rather than
 * rendered as zero.
 */
export const bridgeRateFee = (amount: bigint, feeBps: number): bigint =>
    feeBps <= 0 ? 0n : (amount * BigInt(feeBps)) / 10_000n;

/* -------------------------------------------------------------- writing -- */

export type BridgeLock = {
    txHash: string;
    /**
     * Event nonce of the lock, which deposit-address corridors do not have —
     * the relayer matches those by transaction hash. Zero is what the bridge
     * API expects for them, and is not a placeholder for a missing read.
     */
    nonce: number;
};

/**
 * Sign the deposit that starts a transfer.
 *
 * One transaction, to an address the server named, for an amount the user
 * read. There is no approval and no contract call: an ERC-20 `transfer` and a
 * coin transfer are the only two shapes here, which is exactly why this is the
 * part the wallet is willing to sign on its own.
 */
export const lockOnEvm = async (
    source: WalletKeySource,
    request: {
        chainId: number;
        rpcUrl: string;
        deposit: string;
        /** Contract of the ERC-20 being sent, or null for the chain's coin. */
        contract: string | null;
        amount: bigint;
        gasPrice: bigint;
        gasLimit: bigint;
    },
): Promise<BridgeLock> => {
    const signer = evmSigner(source).connect(
        new JsonRpcProvider(request.rpcUrl, request.chainId, {
            staticNetwork: true,
        }),
    );

    const overrides = {
        gasLimit: request.gasLimit,
        gasPrice: request.gasPrice,
    };

    if (request.contract === null) {
        const tx = await signer.sendTransaction({
            to: request.deposit,
            value: request.amount,
            ...overrides,
        });

        return { txHash: tx.hash, nonce: 0 };
    }

    const token = new Contract(request.contract, ERC20_ABI, signer);
    const tx = await token.transfer(request.deposit, request.amount, overrides);

    return { txHash: tx.hash as string, nonce: 0 };
};

const csrfToken = (): string => {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

export type BridgeSubmission = {
    direction: string;
    token: string;
    sourceTxHash: string;
    sourceNonce: number;
    sender: string;
    recipient: string;
    /** Human amount, as the bridge API wants it — not smallest units. */
    amount: string;
};

/**
 * Tell the relayer a deposit exists.
 *
 * Sent *after* the transfer is broadcast, and a failure here does not undo it:
 * the coin has already moved, and the honest thing is to hand back the hash so
 * it can be submitted again rather than to imply nothing happened. The screen
 * says exactly that.
 */
export const submitBridge = async (
    request: BridgeSubmission,
): Promise<{
    ok: boolean;
    id: number | null;
    status: string | null;
    destinationTxHash: string | null;
    message: string | null;
}> => {
    let response: Response;

    try {
        response = await fetch('/bridge/submit', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                direction: request.direction,
                token: request.token,
                source_tx_hash: request.sourceTxHash,
                source_nonce: request.sourceNonce,
                sender_address: request.sender,
                recipient_address: request.recipient,
                amount: request.amount,
            }),
        });
    } catch {
        return {
            ok: false,
            id: null,
            status: null,
            destinationTxHash: null,
            message: null,
        };
    }

    const body = (await response.json().catch(() => ({}))) as {
        bridge_request?: {
            id?: number;
            status?: string;
            destination_tx_hash?: string | null;
        };
        message?: string;
    };

    return {
        ok: response.ok,
        id: body.bridge_request?.id ?? null,
        status: body.bridge_request?.status ?? null,
        // An auto-processed corridor is often already paid out by the time
        // this returns, and the hash is the only proof of that worth showing.
        destinationTxHash: body.bridge_request?.destination_tx_hash ?? null,
        message: body.message ?? null,
    };
};
