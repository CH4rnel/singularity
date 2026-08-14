import type { Commitment, Connection } from '@solana/web3.js';

/**
 * Solana, as reached from a browser.
 *
 * The public cluster refuses us: a JSON-RPC call that works from a terminal
 * comes back `403 Access forbidden` the moment it carries an `Origin` header,
 * which is every call a page makes. The endpoints that do answer browsers want
 * an api key in the URL, and a key in a bundle is a key anyone may spend.
 *
 * So the page asks this app instead (`POST /api/solana/rpc`) and this app asks
 * Solana. Nothing about the wallet changes: the keys, the signing and the
 * phrase stay on the device, and what travels to the relay is a signed
 * transaction or a read.
 */

/** Same-origin path of the relay. Cluster-suffixed for anything but mainnet. */
export const SOLANA_RPC_PATH = '/api/solana/rpc';

/**
 * The relay as an absolute URL, because `@solana/web3.js` rejects a relative
 * one — it parses the endpoint and insists on http(s).
 */
export const solanaRpcUrl = (cluster?: string): string => {
    const path =
        cluster && cluster !== 'mainnet'
            ? `${SOLANA_RPC_PATH}/${cluster}`
            : SOLANA_RPC_PATH;

    return typeof window === 'undefined'
        ? path
        : `${window.location.origin}${path}`;
};

/**
 * Wait for a signature by asking, not by subscribing.
 *
 * `Connection.confirmTransaction()` opens a WebSocket to `wss://` + the RPC
 * host and waits for a `signatureSubscribe` notification. The relay is HTTP —
 * a JSON-RPC POST endpoint on this app — so that socket has nothing to connect
 * to, and the call would sit there until the blockhash expired before failing
 * a transaction that had in fact landed.
 *
 * Polling `getSignatureStatuses` is the same answer over the transport we
 * have, and it is already how the wallet's own Solana adapter waits.
 *
 * @throws Error when the transaction failed on-chain, expired, or the wait ran out
 */
export const confirmSignature = async (
    connection: Connection,
    signature: string,
    options: {
        commitment?: Commitment;
        /** From `getLatestBlockhash`; lets an expiry be reported as one. */
        lastValidBlockHeight?: number;
        timeoutMs?: number;
        intervalMs?: number;
    } = {},
): Promise<void> => {
    const commitment = options.commitment ?? 'confirmed';
    const intervalMs = options.intervalMs ?? 2_000;
    const deadline = Date.now() + (options.timeoutMs ?? 90_000);

    for (;;) {
        const status = (
            await connection.getSignatureStatuses([signature], {
                searchTransactionHistory: true,
            })
        ).value[0];

        if (status?.err) {
            throw new Error(`Transaction ${signature} failed on Solana`);
        }

        if (
            status?.confirmationStatus === 'finalized' ||
            status?.confirmationStatus === commitment ||
            (commitment === 'processed' && !!status)
        ) {
            return;
        }

        // Nothing has been seen of it yet: if the blockhash it was signed
        // against can no longer be accepted, it never will be.
        if (!status && options.lastValidBlockHeight !== undefined) {
            const height = await connection.getBlockHeight(commitment);

            if (height > options.lastValidBlockHeight) {
                throw new Error(
                    `Transaction ${signature} expired before it was confirmed`,
                );
            }
        }

        if (Date.now() > deadline) {
            throw new Error(
                `Timed out waiting for ${signature} to be confirmed`,
            );
        }

        await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }
};
