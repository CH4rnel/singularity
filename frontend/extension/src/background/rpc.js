/**
 * Talking to chains, token indexes and the price endpoint.
 *
 * Every request in the extension goes out from here, which is also what makes
 * the relay honest: when the browser is routed through a relay these calls go
 * with it, and when the relay is down they fail instead of quietly finding
 * their own way out.
 */
import { PRICES_URL, chainById } from '../shared/chains.js';

let counter = 0;

/** Long enough for a slow node, short enough that the popup is not stuck. */
const TIMEOUT_MS = 20_000;

const post = async (url, body) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(TIMEOUT_MS),
        cache: 'no-store',
        credentials: 'omit',
    });

    if (!response.ok) {
        throw new Error(`RPC HTTP ${response.status}`);
    }

    return response.json();
};

/** One JSON-RPC call against a chain in the registry. */
export const rpc = async (chainId, method, params = []) => {
    const chain = chainById(chainId);

    if (!chain) {
        throw new Error(`chain ${chainId} is not in this wallet`);
    }

    const payload = await post(chain.rpc, {
        jsonrpc: '2.0',
        id: ++counter,
        method,
        params,
    });

    if (payload?.error) {
        const error = new Error(payload.error.message ?? 'RPC error');
        error.code = payload.error.code;
        error.data = payload.error.data;
        throw error;
    }

    return payload?.result ?? null;
};

export const balanceOf = (chainId, address) =>
    rpc(chainId, 'eth_getBalance', [address, 'latest']);

export const nonceOf = (chainId, address) =>
    rpc(chainId, 'eth_getTransactionCount', [address, 'pending']);

export const sendRaw = (chainId, raw) => rpc(chainId, 'eth_sendRawTransaction', [raw]);

/**
 * A gas limit for a transaction the user is about to sign.
 *
 * The Cyberia node answers `eth_estimateGas` for plain transfers and calls but
 * not for every contract deploy, so a failed estimate is not fatal: a plain
 * transfer falls back to the 21000 every EVM charges for one, and anything
 * with calldata is refused rather than signed for a number nobody checked.
 */
export const gasFor = async (chainId, transaction) => {
    try {
        return await rpc(chainId, 'eth_estimateGas', [transaction]);
    } catch (error) {
        const plain = !transaction.data || transaction.data === '0x';

        if (plain) {
            return '0x5208';
        }

        throw error;
    }
};

/**
 * Fee fields for a chain, in the pricing model that chain actually uses.
 *
 * A block with `baseFeePerGas` is a London chain and gets an EIP-1559
 * transaction; anything else gets a legacy gas price. Guessing wrong here is
 * not cosmetic — a type-2 transaction on a pre-London node is rejected at the
 * mempool, and a legacy one on an 1559 chain overpays.
 */
export const feesFor = async (chainId) => {
    const block = await rpc(chainId, 'eth_getBlockByNumber', ['latest', false]);
    const base = block?.baseFeePerGas ? BigInt(block.baseFeePerGas) : null;

    if (base === null) {
        const gasPrice = BigInt(await rpc(chainId, 'eth_gasPrice'));

        return { type: 0, gasPrice: `0x${gasPrice.toString(16)}` };
    }

    let tip = 1_000_000_000n;

    try {
        tip = BigInt(await rpc(chainId, 'eth_maxPriorityFeePerGas'));
    } catch {
        // Not every node implements it; the default above is the usual 1 gwei.
    }

    // Two base fees of headroom is the common rule: it survives a block that
    // fills up without handing the miner the difference, which is refunded.
    const max = base * 2n + tip;

    return {
        type: 2,
        maxFeePerGas: `0x${max.toString(16)}`,
        maxPriorityFeePerGas: `0x${tip.toString(16)}`,
    };
};

/**
 * ERC-20 balances from the chain's own Blockscout index.
 *
 * One keyless call returns symbol, decimals and balance together, which is why
 * nothing here carries a token list of its own: no vendor server, and no
 * hardcoded decimals to be wrong about (Cyberia's USDC is 6, not 18).
 */
export const tokensOf = async (chainId, address) => {
    const chain = chainById(chainId);

    if (!chain?.tokens) {
        return [];
    }

    const url = new URL(chain.tokens);
    url.searchParams.set('module', 'account');
    url.searchParams.set('action', 'tokenlist');
    url.searchParams.set('address', address);

    const response = await fetch(url, {
        signal: AbortSignal.timeout(TIMEOUT_MS),
        cache: 'no-store',
        credentials: 'omit',
    });

    if (!response.ok) {
        throw new Error(`token index HTTP ${response.status}`);
    }

    const payload = await response.json();
    const rows = Array.isArray(payload?.result) ? payload.result : [];

    return rows
        .filter((row) => (row.type ?? 'ERC-20') === 'ERC-20' && BigInt(row.balance ?? '0') > 0n)
        .map((row) => ({
            contract: String(row.contractAddress ?? '').toLowerCase(),
            symbol: row.symbol || '???',
            name: row.name || row.symbol || 'Token',
            decimals: Number(row.decimals ?? 18),
            balance: String(row.balance ?? '0'),
        }));
};

/**
 * USD quotes from cyberia.church.
 *
 * The project's own endpoint, the same one the wallet page reads — not a
 * third-party feed, and not something that decides what the wallet shows: a
 * quote that cannot be read stays null and the popup renders a dash.
 */
export const quotes = async () => {
    const response = await fetch(PRICES_URL, {
        signal: AbortSignal.timeout(TIMEOUT_MS),
        cache: 'no-store',
        credentials: 'omit',
    });

    if (!response.ok) {
        throw new Error(`prices HTTP ${response.status}`);
    }

    return response.json();
};
