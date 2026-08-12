import { Contract, JsonRpcProvider, getAddress } from 'ethers';
import type { LiquidityChainConfig } from '@/lib/liquidityChains';
import { evmSigner } from '@/lib/wallet/keys';
import type { WalletKeySource } from '@/lib/wallet/keys';
import { swapChainFor } from '@/lib/wallet/swap';

/**
 * Wrapping the native coin, and unwrapping it again.
 *
 * CYBER is not an ERC20 — it is the coin the chain runs on — so every pool,
 * every farm and every contract that takes "a token" takes WCYBER instead.
 * `WCYBER` (crypto/hardhat/contracts/WCYBER.sol) is the WETH9 pattern: coin in,
 * token out, one for one, forever, held by the contract itself. The same holds
 * for the wrapped native of every satellite chain in the DEX registry.
 *
 * This is deliberately not part of `swap.ts`. A wrap has no route, no price,
 * no slippage and no counterparty — the only number that can surprise anyone
 * is the gas — so it gets a screen that says one true thing instead of four
 * meaningless ones. What you put in is what you can always take back out.
 */

/** Deposit, withdraw, and the balance that says how much is wrapped. */
const WRAPPED_ABI = [
    'function deposit() payable',
    'function withdraw(uint256 amount)',
    'function balanceOf(address owner) view returns (uint256)',
];

/**
 * Gas this wallet will spend wrapping or unwrapping.
 *
 * A deposit is a storage write and an event (~45k); a withdraw adds the coin
 * transfer back (~40k). The cap exists for the same reason it does everywhere
 * else here: it is what the quoted fee promises, and a call that would cost
 * more is refused rather than signed for the difference.
 */
export const WRAP_GAS_CAP = 150_000n;

/** Headroom over a live estimate, for the drift between quote and mine. */
const GAS_MARGIN = [125n, 100n] as const;

export type WrapDirection = 'wrap' | 'unwrap';

export type WrapQuote = {
    chainId: number;
    direction: WrapDirection;
    /** The wrapped-native contract, so the screen can name what it signs. */
    contract: string;
    /** In wei — a wrap is one-for-one, so this is both sides of it. */
    amount: bigint;
    gasLimit: bigint;
    gasPrice: bigint;
    fee: bigint;
};

/**
 * Whether a pair of assets is a wrap rather than a trade.
 *
 * `null` on either side is the network's own coin, which is how the whole
 * wallet spells it. Pure, so the swap screen can ask this question about what
 * the user picked before it asks a router about it — the router has no pool
 * for a coin and its own wrapper, and would answer with a revert.
 */
export const wrapDirection = (
    config: LiquidityChainConfig,
    from: string | null,
    to: string | null,
): WrapDirection | null => {
    const wrapped = config.wrappedNative.toLowerCase();

    if (from === null && to !== null && to.toLowerCase() === wrapped) {
        return 'wrap';
    }

    if (to === null && from !== null && from.toLowerCase() === wrapped) {
        return 'unwrap';
    }

    return null;
};

const provider = (
    config: LiquidityChainConfig,
    rpcUrl?: string,
): JsonRpcProvider =>
    new JsonRpcProvider(rpcUrl || config.readRpcUrl, config.chainId, {
        staticNetwork: true,
    });

/**
 * What this wrap will cost, before anything is signed.
 *
 * Estimated against the real call from the real account, because the common
 * failure is having no coin to pay with and the node says so here rather than
 * after a signature.
 */
export const quoteWrap = async (request: {
    chainId: number;
    direction: WrapDirection;
    amount: bigint;
    account: string;
    /** Price per unit of gas, floors already applied by the chain adapter. */
    gasPrice: bigint;
    rpcUrl?: string;
}): Promise<WrapQuote> => {
    const config = swapChainFor(request.chainId);

    if (request.amount <= 0n) {
        throw new Error('Enter an amount');
    }

    const contract = getAddress(config.wrappedNative);
    const wrapped = new Contract(
        contract,
        WRAPPED_ABI,
        provider(config, request.rpcUrl),
    );

    const estimate = await (async (): Promise<bigint | null> => {
        try {
            return request.direction === 'wrap'
                ? ((await wrapped.deposit.estimateGas({
                      from: request.account,
                      value: request.amount,
                  })) as bigint)
                : ((await wrapped.withdraw.estimateGas(request.amount, {
                      from: request.account,
                  })) as bigint);
        } catch {
            // Some of these nodes answer eth_estimateGas unreliably. The cap
            // is already the figure the user is shown and agrees to, so
            // falling back to it changes nothing they were told.
            return null;
        }
    })();

    const gasLimit =
        estimate === null
            ? WRAP_GAS_CAP
            : (estimate * GAS_MARGIN[0]) / GAS_MARGIN[1];

    if (gasLimit > WRAP_GAS_CAP) {
        throw new Error(
            'This costs more gas than this wallet will spend on a wrap.',
        );
    }

    return {
        chainId: config.chainId,
        direction: request.direction,
        contract,
        amount: request.amount,
        gasLimit,
        gasPrice: request.gasPrice,
        fee: gasLimit * request.gasPrice,
    };
};

/**
 * Sign and broadcast the wrap. Returns the transaction hash.
 *
 * The amount and the gas are the ones that were quoted and held for; there is
 * no re-read in between, because the only thing that could have changed is the
 * price of gas, and re-pricing after the hold would sign for a number nobody
 * saw.
 */
export const executeWrap = async (
    source: WalletKeySource,
    request: { quote: WrapQuote; rpcUrl?: string },
): Promise<string> => {
    const { quote } = request;
    const config = swapChainFor(quote.chainId);
    const signer = evmSigner(source).connect(provider(config, request.rpcUrl));
    const wrapped = new Contract(quote.contract, WRAPPED_ABI, signer);

    const tx =
        quote.direction === 'wrap'
            ? await wrapped.deposit({
                  value: quote.amount,
                  gasLimit: quote.gasLimit,
                  gasPrice: quote.gasPrice,
              })
            : await wrapped.withdraw(quote.amount, {
                  gasLimit: quote.gasLimit,
                  gasPrice: quote.gasPrice,
              });

    return tx.hash as string;
};
