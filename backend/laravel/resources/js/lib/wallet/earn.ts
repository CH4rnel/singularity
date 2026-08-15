import { Contract, JsonRpcProvider } from 'ethers';
import { FARM_CHAINS } from '@/lib/farmChains';
import type { FarmChainConfig } from '@/lib/farmChains';
import { evmSigner } from '@/lib/wallet/keys';
import type { WalletKeySource } from '@/lib/wallet/keys';

/**
 * Earning on a pool position, from inside the wallet.
 *
 * The farm is a Uniswap-V2-style MasterChef: it holds staked LP tokens and
 * mints one reward token against an allocation ledger. Two things follow, and
 * this file is built on both.
 *
 * The first is that a *pool* and a *position* are different objects with
 * different sources. What a pool is worth — TVL, volume, the APR that follows
 * from them — is a claim about the whole chain over a whole day, and it comes
 * from this site's own indexer snapshot (`/api/dex/apr`), keyless and cached.
 * What *you* hold in it is read from the chain, per address, because a server
 * that answered that question would have to be told which address to answer
 * about. They are never merged into one number: an APR the indexer could not
 * compute is null and stays null, next to a stake that is perfectly readable.
 *
 * The second is that staking is a plain ERC-20 deposit, which is why it is here
 * at all. Adding liquidity is not — it is two assets, a ratio that moves
 * between quote and signature, two allowances and a slippage floor on both
 * sides. That belongs where the pool composer already is, and this screen
 * links to it rather than growing a second, thinner version of it.
 *
 * Allowances are for exactly the amount being staked, never `MaxUint256` — the
 * same rule the swap screen follows, for the same reason: an unlimited
 * allowance to a contract is a standing permission nobody remembers granting.
 */

const MASTERCHEF_ABI = [
    'function poolLength() view returns (uint256)',
    'function poolInfo(uint256) view returns (address lpToken, uint256 allocPoint, uint256 lastRewardBlock, uint256 accRewardPerShare)',
    'function userInfo(uint256, address) view returns (uint256 amount, uint256 rewardDebt)',
    'function pendingReward(uint256, address) view returns (uint256)',
    'function totalAllocPoint() view returns (uint256)',
    'function rewardToken() view returns (address)',
    'function deposit(uint256 pid, uint256 amount)',
    'function withdraw(uint256 pid, uint256 amount)',
];

const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
    'function totalSupply() view returns (uint256)',
];

/**
 * A V2 pair answers `token0`; a single-asset staking token does not. That one
 * call is how a two-sided pool is told apart from a solo pool, and the answer
 * decides everything the screen says about impermanent loss.
 */
const PAIR_ABI = [
    'function token0() view returns (address)',
    'function token1() view returns (address)',
    'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32)',
    'function totalSupply() view returns (uint256)',
];

/**
 * Gas ceilings, and the same promise the swap screen makes: the quoted fee is
 * what the transaction may cost, so anything that would exceed it is refused
 * rather than signed for the difference.
 *
 * A MasterChef deposit updates one pool and one user record and pays out any
 * accrued reward on the way through, which is a token transfer or a mint.
 */
export const STAKE_GAS_CAP = 350_000n;

/** Gas for one `approve`, generous on purpose — unused gas is refunded. */
export const EARN_APPROVE_GAS = 70_000n;

/** Cyberia's node caps a JSON-RPC batch at 20 calls. */
const BATCH_MAX = 20;

export type EarnPool = {
    pid: number;
    /** The token that gets staked: an LP pair, or a single asset. */
    stakingToken: string;
    label: string;
    decimals: number;
    /** Two-sided pool, and so subject to impermanent loss. */
    isPair: boolean;
    /** Share of the farm's emission this pool is allocated, 0–1. */
    share: number;
    /** Staked by this account. */
    staked: bigint;
    /** Reward accrued and not yet claimed. */
    pending: bigint;
    /** Held in the wallet and *not* staked — the thing people forget. */
    idle: bigint;
    /** What the chef may already move on this account's behalf. */
    allowance: bigint;
    /** Everything staked in this pool by everyone. */
    totalStaked: bigint;
};

export type EarnSnapshot = {
    pools: EarnPool[];
    reward: { symbol: string; decimals: number };
    /** Pools whose reads failed outright, so the screen can say how many. */
    unreadable: number;
};

/* ------------------------------------------------------------ registry -- */

/** Networks with a farm this wallet can stake on. */
export const earnChains = (): readonly FarmChainConfig[] => FARM_CHAINS;

export const earnChainFor = (chainId: number): FarmChainConfig => {
    const config = FARM_CHAINS.find(
        (candidate) => candidate.chainId === chainId,
    );

    if (!config) {
        throw new Error(`No farm is deployed on chain ${chainId}`);
    }

    return config;
};

export const hasEarn = (chainId: number | undefined): boolean =>
    chainId !== undefined &&
    FARM_CHAINS.some((candidate) => candidate.chainId === chainId);

const provider = (config: FarmChainConfig, rpcUrl?: string): JsonRpcProvider =>
    new JsonRpcProvider(rpcUrl || config.readRpcUrl, config.chainId, {
        staticNetwork: true,
        batchMaxCount: BATCH_MAX,
    });

/* ---------------------------------------------------------------- pure -- */

/**
 * What a stake is worth of the two assets under it.
 *
 * An LP token is a claim on a share of the pool's reserves, so the position is
 * that share of both sides — not a price, which is why nothing here returns
 * one. Zero supply is a pool nobody has funded, and its share is zero rather
 * than a division by zero.
 */
export const poolShare = (
    lpAmount: bigint,
    totalSupply: bigint,
    reserves: [bigint, bigint],
): { share: number; amounts: [bigint, bigint] } => {
    if (totalSupply <= 0n || lpAmount <= 0n) {
        return { share: 0, amounts: [0n, 0n] };
    }

    return {
        // Basis points before the float, because two bigints divided as
        // numbers lose the answer entirely at eighteen decimals.
        share: Number((lpAmount * 1_000_000n) / totalSupply) / 1_000_000,
        amounts: [
            (reserves[0] * lpAmount) / totalSupply,
            (reserves[1] * lpAmount) / totalSupply,
        ],
    };
};

/**
 * Whether a stake can be signed at all, and why not when it cannot.
 *
 * Separated from the screen because every one of these is a different sentence
 * for the user: an amount larger than the balance is a mistake they can fix by
 * typing less, and an amount larger than the stake is a different mistake in
 * the other direction.
 */
export type StakeRefusal = 'ok' | 'empty' | 'tooMuch' | 'nothingStaked';

export const canStake = (amount: bigint, idle: bigint): StakeRefusal =>
    amount <= 0n ? 'empty' : amount > idle ? 'tooMuch' : 'ok';

export const canUnstake = (amount: bigint, staked: bigint): StakeRefusal =>
    staked <= 0n
        ? 'nothingStaked'
        : amount <= 0n
          ? 'empty'
          : amount > staked
            ? 'tooMuch'
            : 'ok';

/* --------------------------------------------------------------- reads -- */

/**
 * Every pool on one farm, with this account's position in it.
 *
 * Pools are enumerated from the chef rather than from a list here, so a pool
 * the operator adds tomorrow appears without a release. A pool that will not
 * read is counted and dropped rather than rendered with zeroes — a zero stake
 * is a claim about this account, and an unread pool is a claim about the node.
 */
export const readEarnPools = async (
    chainId: number,
    owner: string,
    rpcUrl?: string,
): Promise<EarnSnapshot> => {
    const config = earnChainFor(chainId);
    const rpc = provider(config, rpcUrl);
    const chef = new Contract(config.masterchef, MASTERCHEF_ABI, rpc);

    const [length, totalAlloc, rewardAddress] = await Promise.all([
        chef.poolLength() as Promise<bigint>,
        chef.totalAllocPoint() as Promise<bigint>,
        chef.rewardToken() as Promise<string>,
    ]);

    const rewardToken = new Contract(rewardAddress, ERC20_ABI, rpc);
    const [rewardSymbol, rewardDecimals] = await Promise.all([
        rewardToken.symbol() as Promise<string>,
        rewardToken.decimals() as Promise<bigint>,
    ]);

    const hidden = new Set(
        (config.hiddenPools ?? []).map((address) => address.toLowerCase()),
    );

    const results = await Promise.allSettled(
        Array.from({ length: Number(length) }, async (_unused, pid) => {
            const info = (await chef.poolInfo(pid)) as {
                lpToken: string;
                allocPoint: bigint;
            };

            if (hidden.has(info.lpToken.toLowerCase())) {
                return null;
            }

            const token = new Contract(info.lpToken, ERC20_ABI, rpc);
            const pair = new Contract(info.lpToken, PAIR_ABI, rpc);

            const [decimals, staked, pending, idle, allowance, totalStaked] =
                await Promise.all([
                    token.decimals() as Promise<bigint>,
                    chef.userInfo(pid, owner) as Promise<{ amount: bigint }>,
                    chef.pendingReward(pid, owner) as Promise<bigint>,
                    token.balanceOf(owner) as Promise<bigint>,
                    token.allowance(
                        owner,
                        config.masterchef,
                    ) as Promise<bigint>,
                    token.balanceOf(config.masterchef) as Promise<bigint>,
                ]);

            // Two-sided or not is the question the whole risk note turns on,
            // and a pair answers it by having a `token0` at all.
            let label = '';
            let isPair = false;

            try {
                const [token0, token1] = await Promise.all([
                    pair.token0() as Promise<string>,
                    pair.token1() as Promise<string>,
                ]);
                const [symbol0, symbol1] = await Promise.all([
                    new Contract(
                        token0,
                        ERC20_ABI,
                        rpc,
                    ).symbol() as Promise<string>,
                    new Contract(
                        token1,
                        ERC20_ABI,
                        rpc,
                    ).symbol() as Promise<string>,
                ]);

                label = `${symbol0} / ${symbol1}`;
                isPair = true;
            } catch {
                label = (await token.symbol()) as string;
            }

            return {
                pid,
                stakingToken: info.lpToken,
                label,
                decimals: Number(decimals),
                isPair,
                share:
                    totalAlloc > 0n
                        ? Number((info.allocPoint * 10_000n) / totalAlloc) /
                          10_000
                        : 0,
                staked: staked.amount,
                pending,
                idle,
                allowance,
                totalStaked,
            } satisfies EarnPool;
        }),
    );

    const pools: EarnPool[] = [];
    let unreadable = 0;

    for (const result of results) {
        if (result.status === 'rejected') {
            unreadable += 1;

            continue;
        }

        if (result.value !== null) {
            pools.push(result.value);
        }
    }

    // A position first, then whatever is earning most: the list is read by
    // someone checking on their own money before it is read as a menu.
    pools.sort((a, b) => {
        const mine = Number(b.staked > 0n) - Number(a.staked > 0n);

        return mine !== 0 ? mine : b.share - a.share;
    });

    return {
        pools,
        reward: { symbol: rewardSymbol, decimals: Number(rewardDecimals) },
        unreadable,
    };
};

/** Reserves and supply behind one LP token, for what a position is made of. */
export const readPairComposition = async (
    chainId: number,
    pairAddress: string,
    rpcUrl?: string,
): Promise<{
    totalSupply: bigint;
    reserves: [bigint, bigint];
    symbols: [string, string];
    decimals: [number, number];
} | null> => {
    const config = earnChainFor(chainId);
    const rpc = provider(config, rpcUrl);
    const pair = new Contract(pairAddress, PAIR_ABI, rpc);

    try {
        const [token0, token1, reserves, totalSupply] = await Promise.all([
            pair.token0() as Promise<string>,
            pair.token1() as Promise<string>,
            pair.getReserves() as Promise<[bigint, bigint, bigint]>,
            pair.totalSupply() as Promise<bigint>,
        ]);

        const first = new Contract(token0, ERC20_ABI, rpc);
        const second = new Contract(token1, ERC20_ABI, rpc);

        const [symbol0, symbol1, decimals0, decimals1] = await Promise.all([
            first.symbol() as Promise<string>,
            second.symbol() as Promise<string>,
            first.decimals() as Promise<bigint>,
            second.decimals() as Promise<bigint>,
        ]);

        return {
            totalSupply,
            reserves: [reserves[0], reserves[1]],
            symbols: [symbol0, symbol1],
            decimals: [Number(decimals0), Number(decimals1)],
        };
    } catch {
        // A staking token that is not a pair, or a node that would not answer.
        // Either way the screen says nothing rather than guessing a shape.
        return null;
    }
};

/* -------------------------------------------------------------- writing -- */

export type EarnReceipt = {
    /** Hash of the allowance transaction, when this stake needed one. */
    approvalHash: string | null;
    hash: string;
};

const signerFor = (
    source: WalletKeySource,
    config: FarmChainConfig,
    rpcUrl?: string,
) => evmSigner(source).connect(provider(config, rpcUrl));

/**
 * Stake LP into the farm.
 *
 * The allowance, when one is needed, is for exactly this amount: an unlimited
 * approval to a contract is a standing permission the user would have to
 * remember revoking, and this wallet does not hand those out.
 */
export const stake = async (
    source: WalletKeySource,
    request: {
        chainId: number;
        pid: number;
        stakingToken: string;
        amount: bigint;
        allowance: bigint;
        gasPrice: bigint;
        rpcUrl?: string;
        onApproved?: (hash: string) => void;
    },
): Promise<EarnReceipt> => {
    const config = earnChainFor(request.chainId);
    const signer = signerFor(source, config, request.rpcUrl);
    const chef = new Contract(config.masterchef, MASTERCHEF_ABI, signer);

    let approvalHash: string | null = null;

    if (request.allowance < request.amount) {
        const token = new Contract(request.stakingToken, ERC20_ABI, signer);
        const overrides = {
            gasLimit: EARN_APPROVE_GAS,
            gasPrice: request.gasPrice,
        };

        // An LP token is this DEX's own contract and never a USDT-style one,
        // so a leftover non-zero allowance can be raised in a single call.
        const approval = await token.approve(
            config.masterchef,
            request.amount,
            overrides,
        );

        await approval.wait();
        approvalHash = approval.hash as string;
        request.onApproved?.(approvalHash);
    }

    const tx = await chef.deposit(request.pid, request.amount, {
        gasLimit: STAKE_GAS_CAP,
        gasPrice: request.gasPrice,
    });

    return { approvalHash, hash: tx.hash as string };
};

/** Take a stake back out. Pending rewards are paid out on the way. */
export const unstake = async (
    source: WalletKeySource,
    request: {
        chainId: number;
        pid: number;
        amount: bigint;
        gasPrice: bigint;
        rpcUrl?: string;
    },
): Promise<EarnReceipt> => {
    const config = earnChainFor(request.chainId);
    const chef = new Contract(
        config.masterchef,
        MASTERCHEF_ABI,
        signerFor(source, config, request.rpcUrl),
    );

    const tx = await chef.withdraw(request.pid, request.amount, {
        gasLimit: STAKE_GAS_CAP,
        gasPrice: request.gasPrice,
    });

    return { approvalHash: null, hash: tx.hash as string };
};

/**
 * Take the reward and leave the stake where it is.
 *
 * A MasterChef pays out on every deposit, so depositing nothing is the harvest
 * — there is no separate `claim` to call, and inventing one in the ABI would
 * only produce a transaction that reverts.
 */
export const claim = async (
    source: WalletKeySource,
    request: {
        chainId: number;
        pid: number;
        gasPrice: bigint;
        rpcUrl?: string;
    },
): Promise<EarnReceipt> => {
    const config = earnChainFor(request.chainId);
    const chef = new Contract(
        config.masterchef,
        MASTERCHEF_ABI,
        signerFor(source, config, request.rpcUrl),
    );

    const tx = await chef.deposit(request.pid, 0n, {
        gasLimit: STAKE_GAS_CAP,
        gasPrice: request.gasPrice,
    });

    return { approvalHash: null, hash: tx.hash as string };
};

/** Where a farm transaction ends up being read, once it is broadcast. */
export const earnTxUrl = (config: FarmChainConfig, hash: string): string =>
    `${config.explorer}/tx/${hash}`;
