import { Contract, JsonRpcProvider, ZeroAddress, formatUnits } from 'ethers';
import {
    DEFAULT_LAUNCHPAD_CHAIN_ID,
    LAUNCHPAD_CHAINS,
    launchpadChain,
    launchpadReadRpcUrl,
} from '@/lib/launchpadChains';
import type { LaunchpadChain } from '@/lib/launchpadChains';

/**
 * What the wallet reads about the launchpad.
 *
 * The launchpad on Cyberia is a fair launch: a token is deployed, the native
 * coin that paid for it is burned into permanently locked liquidity, and from
 * that moment the token is simply a pool on the DEX. There are no rounds, no
 * allocation tiers, no vesting and no cap — so this file has no vocabulary for
 * any of that, and the screens above it do not draw controls for things that
 * cannot happen.
 *
 * Everything here is a read against the chain the wallet already talks to.
 * Nothing is bought or signed from this file: buying a launched token is a
 * swap, and that lives in `lib/wallet/swap.ts` — the detail screen hands it a
 * contract address and the swap screen reads the token for itself, so nothing
 * here has to know a launch's decimals or quote a route.
 */

const LAUNCHPAD_ABI = [
    'function allTokensLength() view returns (uint256)',
    'function allTokens(uint256) view returns (address)',
    'function pairOf(address) view returns (address)',
];

const ERC20_ABI = [
    'function name() view returns (string)',
    'function symbol() view returns (string)',
    'function totalSupply() view returns (uint256)',
];

const PAIR_ABI = [
    'function token0() view returns (address)',
    'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)',
];

export type WalletLaunch = {
    /** Contract of the launched token — its identity on this chain. */
    address: string;
    name: string;
    symbol: string;
    /** Everything in existence, in the token's own units (always 18 here). */
    supply: bigint;
    /**
     * Native coin locked in the pool, in wei. This is the launch's liquidity
     * and it is burned: it is a floor, not a treasury somebody can withdraw.
     */
    liquidity: bigint;
    /** Price of one token in the chain's native coin, or null with no pool. */
    priceNative: number | null;
    /** Supply × price, in native coin, when both are known. */
    marketCapNative: number | null;
    explorerUrl: string;
    swapUrl: string;
};

/** How many launches one pass reads. Each costs several calls, so it is capped. */
const PAGE = 12;

const providerFor = (target: LaunchpadChain): JsonRpcProvider =>
    new JsonRpcProvider(launchpadReadRpcUrl(target), target.chain.chainId, {
        staticNetwork: true,
    });

/**
 * The reserves of one pool as a price, from the token's side.
 *
 * Reserves are ordered by token address, not by which of the two anyone cares
 * about, so `token0` decides which number is the quote. Getting this backwards
 * inverts every price on the screen — and a token worth 0.0001 CYBER would be
 * drawn as one worth 10,000 — so the ordering is read from the pool rather
 * than assumed, and it is pure here so it can be pinned by a test.
 */
export const poolQuote = (
    token0: string,
    token: string,
    reserves: readonly [bigint, bigint],
): { price: number; liquidity: bigint } | null => {
    const tokenIsFirst = token0.toLowerCase() === token.toLowerCase();
    const tokenReserve = tokenIsFirst ? reserves[0] : reserves[1];
    const nativeReserve = tokenIsFirst ? reserves[1] : reserves[0];

    // An empty side is a pool with no price, not a price of zero.
    if (tokenReserve === 0n) {
        return null;
    }

    return {
        price:
            Number(formatUnits(nativeReserve, 18)) /
            Number(formatUnits(tokenReserve, 18)),
        liquidity: nativeReserve,
    };
};

const poolPrice = async (
    provider: JsonRpcProvider,
    pair: string,
    token: string,
): Promise<{ price: number; liquidity: bigint } | null> => {
    try {
        const contract = new Contract(pair, PAIR_ABI, provider);
        const [token0, reserves] = await Promise.all([
            contract.token0() as Promise<string>,
            contract.getReserves() as Promise<[bigint, bigint, bigint]>,
        ]);

        return poolQuote(token0, token, [reserves[0], reserves[1]]);
    } catch {
        // A pool that cannot be read is a launch without a price, not a launch
        // that is worthless — the caller renders the difference.
        return null;
    }
};

/**
 * The most recent launches on one chain, newest first.
 *
 * A launch with no readable pool still appears: it exists on chain, and hiding
 * it because its price could not be fetched would be a quieter lie than showing
 * it with a dash.
 */
export const fetchLaunches = async (
    chainId: number = DEFAULT_LAUNCHPAD_CHAIN_ID,
    limit = PAGE,
): Promise<WalletLaunch[]> => {
    const target = launchpadChain(chainId);

    if (!target?.launchpad) {
        return [];
    }

    const provider = providerFor(target);
    const launchpad = new Contract(target.launchpad, LAUNCHPAD_ABI, provider);
    const total = Number((await launchpad.allTokensLength()) as bigint);

    if (total === 0) {
        return [];
    }

    const indices: number[] = [];

    for (let i = total - 1; i >= Math.max(0, total - limit); i--) {
        indices.push(i);
    }

    const addresses = (await Promise.all(
        indices.map((index) => launchpad.allTokens(index) as Promise<string>),
    )) as string[];

    return Promise.all(
        addresses.map(async (address): Promise<WalletLaunch> => {
            const token = new Contract(address, ERC20_ABI, provider);
            const [name, symbol, supply, pair] = await Promise.all([
                (token.name() as Promise<string>).catch(() => ''),
                (token.symbol() as Promise<string>).catch(() => ''),
                (token.totalSupply() as Promise<bigint>).catch(() => 0n),
                (launchpad.pairOf(address) as Promise<string>).catch(
                    () => ZeroAddress,
                ),
            ]);

            const pool =
                pair && pair !== ZeroAddress
                    ? await poolPrice(provider, pair, address)
                    : null;
            const whole = Number(formatUnits(supply, 18));

            return {
                address,
                name: String(name).slice(0, 40),
                symbol: String(symbol).slice(0, 12),
                supply,
                liquidity: pool?.liquidity ?? 0n,
                priceNative: pool?.price ?? null,
                marketCapNative: pool ? pool.price * whole : null,
                explorerUrl: `${target.explorerUrl}/address/${address}`,
                swapUrl: `${target.swapUrl}?outputCurrency=${address}`,
            };
        }),
    );
};

/** Chains with a launchpad deployed — the only ones with anything to list. */
export const launchpadChains = (): LaunchpadChain[] =>
    LAUNCHPAD_CHAINS.filter((entry) => entry.launchpad !== null);
