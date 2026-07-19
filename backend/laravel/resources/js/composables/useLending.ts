import { usePage } from '@inertiajs/vue3';
import { Interface, formatUnits, getAddress } from 'ethers';
import { computed, ref } from 'vue';
import { useWallet } from '@/composables/useWallet';
import { CYBERIA_CHAIN, CYBERIA_RPC, ensureEvmChain } from '@/lib/evmChains';
import { getSelectedEvmProvider } from '@/lib/evmProvider';

export type MarketAction = 'supply' | 'withdraw' | 'borrow' | 'repay';

export type MarketView = {
    address: string;
    underlying: string;
    symbol: string;
    decimals: number;
    cash: bigint;
    totalBorrows: bigint;
    totalSupplyUnderlying: bigint;
    supplyApy: number;
    borrowApy: number;
    collateralFactor: number;
    priceMantissa: bigint;
    userSupplyShares: bigint;
    userSupplyUnderlying: bigint;
    userBorrow: bigint;
    userUnderlyingBalance: bigint;
    userAllowance: bigint;
    entered: boolean;
    exchangeRate: bigint;
};

export const COMPTROLLER_ABI = [
    'function getAllMarkets() view returns (address[])',
    'function getAssetsIn(address) view returns (address[])',
    'function collateralFactor(address) view returns (uint256)',
    'function getAccountLiquidity(address) view returns (uint256,uint256,uint256)',
    'function oracle() view returns (address)',
    'function enterMarkets(address[]) returns (uint256[])',
    'function exitMarket(address)',
    'function closeFactorMantissa() view returns (uint256)',
    'function liquidationIncentiveMantissa() view returns (uint256)',
];

export const MARKET_ABI = [
    'function underlying() view returns (address)',
    'function getCash() view returns (uint256)',
    'function totalBorrows() view returns (uint256)',
    'function totalSupply() view returns (uint256)',
    'function balanceOf(address) view returns (uint256)',
    'function borrowBalanceStored(address) view returns (uint256)',
    // Non-view: forces accrueInterest first, so the returned amount reflects
    // every block of interest up to the current head. We call it via Multicall3
    // inside an eth_call simulation — the state mutation is discarded.
    'function borrowBalanceCurrent(address) returns (uint256)',
    'function exchangeRateCurrent() returns (uint256)',
    'function exchangeRateStored() view returns (uint256)',
    'function accrueInterest()',
    'function interestRateModel() view returns (address)',
    'function reserveFactorMantissa() view returns (uint256)',
    'function mint(uint256) returns (uint256)',
    'function redeemUnderlying(uint256) returns (uint256)',
    'function borrow(uint256)',
    'function repayBorrow(uint256) returns (uint256)',
    'function liquidateBorrow(address borrower, uint256 repayAmount, address collateralMarket) returns (uint256)',
];

export const COMPTROLLER_LIQ_ABI = [
    'function liquidateCalculateSeizeShares(address marketBorrowed, address marketCollateral, uint256 actualRepayAmount) view returns (uint256)',
];

export const MARKET_EVENTS_ABI = [
    'event Borrow(address indexed borrower, uint256 borrowAmount, uint256 accountBorrows, uint256 totalBorrows)',
];

export const ORACLE_ABI = [
    'function getUnderlyingPrice(address) view returns (uint256)',
];

export const RATE_MODEL_ABI = [
    'function getBorrowRate(uint256,uint256,uint256) view returns (uint256)',
    'function getSupplyRate(uint256,uint256,uint256,uint256) view returns (uint256)',
    'function blocksPerYear() view returns (uint256)',
];

export const ERC20_ABI = [
    'function symbol() view returns (string)',
    'function decimals() view returns (uint8)',
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
];

const MULTICALL_ABI = [
    'function aggregate3((address target, bool allowFailure, bytes callData)[] calls) view returns ((bool success, bytes returnData)[])',
];

// Configure once deployed. Empty string => UI prompts for address.
export const DEFAULT_COMPTROLLER = ((import.meta as any).env
    ?.VITE_LENDING_COMPTROLLER ?? '') as string;
// Reads go through the same-origin proxy at /api/rpc/cyberia — the public
// Cyberia RPC blocks browser CORS and the app may be served over HTTPS
// (mixed-content guard). Chain params come from the shared registry.
// Pre-deployed Multicall3 on Cyberia (from crypto/hardhat/deployments/cyberia-quickswap.json).
export const MULTICALL3 = '0x176C70dD7CF17056596D8c4C7E2b1f2537df978F';

export const MANTISSA = 10n ** 18n;

export const comptrollerIface = new Interface(COMPTROLLER_ABI);
export const marketIface = new Interface(MARKET_ABI);
export const oracleIface = new Interface(ORACLE_ABI);
export const rateModelIface = new Interface(RATE_MODEL_ABI);
export const erc20Iface = new Interface(ERC20_ABI);

export async function rpcCall<T>(
    method: string,
    params: unknown[],
): Promise<T> {
    const res = await fetch(CYBERIA_RPC, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }),
    });
    const json = (await res.json()) as {
        result?: T;
        error?: { message: string };
    };

    if (json.error) {
        throw new Error(json.error.message);
    }

    return json.result as T;
}

export type Call = { target: string; allowFailure: boolean; callData: string };

export async function multicall(
    calls: Call[],
): Promise<{ success: boolean; returnData: string }[]> {
    const mcIface = new Interface(MULTICALL_ABI);
    const data = mcIface.encodeFunctionData('aggregate3', [calls]);
    const result = await rpcCall<string>('eth_call', [
        { to: MULTICALL3, data },
        'latest',
    ]);
    const [decoded] = mcIface.decodeFunctionResult('aggregate3', result);

    return (decoded as Array<[boolean, string]>).map(
        ([success, returnData]) => ({
            success,
            returnData,
        }),
    );
}

export function rateToApy(ratePerBlock: bigint, blocksPerYear: bigint): number {
    if (blocksPerYear === 0n) {
        return 0;
    }

    // APY ≈ (1 + r)^n - 1, but n is very large; use continuous compounding for stability.
    const r = Number(ratePerBlock) / 1e18;

    return (Math.exp(r * Number(blocksPerYear)) - 1) * 100;
}

export function decodeBigint(
    iface: Interface,
    fn: string,
    data: string,
): bigint {
    return iface.decodeFunctionResult(fn, data)[0] as bigint;
}

export function decodeAddress(
    iface: Interface,
    fn: string,
    data: string,
): string {
    return iface.decodeFunctionResult(fn, data)[0] as string;
}

export function decodeAddressArray(
    iface: Interface,
    fn: string,
    data: string,
): string[] {
    return iface.decodeFunctionResult(fn, data)[0] as string[];
}

export function formatToken(raw: bigint, decimals: number, digits = 4): string {
    if (raw === 0n) {
        return '0';
    }

    const n = Number(formatUnits(raw, decimals));

    if (n < 0.0001) {
        return '<0.0001';
    }

    return n.toLocaleString('en', {
        maximumFractionDigits: digits,
    });
}

export function formatUsd(raw: bigint, priceMantissa: bigint): string {
    if (raw === 0n) {
        return '$0';
    }

    // raw is in underlying units; priceMantissa is normalized to 1e(36 - decimals)
    // so price * raw / 1e36 = USD. Divide by 1e32 then by 1e4 to keep 4 USD decimals.
    const usd = Number((raw * priceMantissa) / 10n ** 32n) / 1e4;

    if (usd < 0.01) {
        return '<$0.01';
    }

    return '$' + usd.toLocaleString('en', { maximumFractionDigits: 2 });
}

export async function ensureCyberia(): Promise<void> {
    const injected = getSelectedEvmProvider();

    if (!injected) {
        throw new Error('EVM wallet not detected');
    }

    await ensureEvmChain(injected, CYBERIA_CHAIN);
}

/// Shared lending state + market loader. Both the Lending dashboard and the
/// standalone Liquidate page build on the same set of markets, the connected
/// account, and the comptroller address, so the wiring lives here once.
export function useLending() {
    const wallet = useWallet();
    const page = usePage();
    const authUser = computed(
        () =>
            page.props.auth?.user as
                | { wallet_address?: string | null }
                | undefined,
    );

    // Address we actually use for reads: prefer the connected EVM account, fall
    // back to the saved wallet_address from Sanctum auth. Without this fallback,
    // hitting the page directly (without going through Welcome/AppSidebar where
    // `wallet.restore()` runs) leaves `wallet.address` empty and balanceOf
    // queries silently return 0.
    const queryAddress = computed<string | null>(
        () => wallet.address.value || authUser.value?.wallet_address || null,
    );
    const comptrollerAddress = ref<string>(DEFAULT_COMPTROLLER);
    const inputComptroller = ref<string>(DEFAULT_COMPTROLLER);
    const markets = ref<MarketView[]>([]);
    const liquidity = ref<{ liquidity: bigint; shortfall: bigint } | null>(
        null,
    );
    const loading = ref(false);
    const error = ref<string | null>(null);

    const isReady = computed(
        () =>
            !!queryAddress.value &&
            comptrollerAddress.value &&
            comptrollerAddress.value.length === 42,
    );

    async function loadMarkets() {
        error.value = null;

        if (!isReady.value) {
            return;
        }

        loading.value = true;

        try {
            const account = getAddress(queryAddress.value!);
            const comptrollerAddr = getAddress(comptrollerAddress.value);

            // --- Round 1: comptroller metadata + per-account state ----------------
            const round1: Call[] = [
                {
                    target: comptrollerAddr,
                    allowFailure: false,
                    callData: comptrollerIface.encodeFunctionData('oracle'),
                },
                {
                    target: comptrollerAddr,
                    allowFailure: false,
                    callData: '0xb0772d0b' /* getAllMarkets() */,
                },
                {
                    target: comptrollerAddr,
                    allowFailure: false,
                    callData: comptrollerIface.encodeFunctionData(
                        'getAssetsIn',
                        [account],
                    ),
                },
                {
                    target: comptrollerAddr,
                    allowFailure: true,
                    callData: comptrollerIface.encodeFunctionData(
                        'getAccountLiquidity',
                        [account],
                    ),
                },
            ];
            const r1 = await multicall(round1);
            const oracleAddress = decodeAddress(
                comptrollerIface,
                'oracle',
                r1[0].returnData,
            );
            const getAllMarketsIface = new Interface([
                'function getAllMarkets() view returns (address[])',
            ]);
            const allMarkets = decodeAddressArray(
                getAllMarketsIface,
                'getAllMarkets',
                r1[1].returnData,
            );
            const enteredList = decodeAddressArray(
                comptrollerIface,
                'getAssetsIn',
                r1[2].returnData,
            );
            const enteredSet = new Set(enteredList.map((a) => a.toLowerCase()));

            if (r1[3].success) {
                const decoded = comptrollerIface.decodeFunctionResult(
                    'getAccountLiquidity',
                    r1[3].returnData,
                );
                liquidity.value = {
                    liquidity: decoded[1] as bigint,
                    shortfall: decoded[2] as bigint,
                };
            } else {
                liquidity.value = { liquidity: 0n, shortfall: 0n };
            }

            // --- Round 2: each market's underlying address + rate model ----------
            const round2: Call[] = [];

            for (const m of allMarkets) {
                round2.push({
                    target: m,
                    allowFailure: false,
                    callData: marketIface.encodeFunctionData('underlying'),
                });
                round2.push({
                    target: m,
                    allowFailure: false,
                    callData:
                        marketIface.encodeFunctionData('interestRateModel'),
                });
            }

            const r2 = await multicall(round2);
            const underlyings: string[] = [];
            const rateModels: string[] = [];

            for (let i = 0; i < allMarkets.length; i++) {
                underlyings.push(
                    decodeAddress(
                        marketIface,
                        'underlying',
                        r2[2 * i].returnData,
                    ),
                );
                rateModels.push(
                    decodeAddress(
                        marketIface,
                        'interestRateModel',
                        r2[2 * i + 1].returnData,
                    ),
                );
            }

            // --- Round 3: per-market data --------------------------------------
            const calls: Call[] = [];

            for (let i = 0; i < allMarkets.length; i++) {
                const m = allMarkets[i];
                const u = underlyings[i];
                const rm = rateModels[i];
                calls.push(
                    {
                        target: u,
                        allowFailure: true,
                        callData: erc20Iface.encodeFunctionData('symbol'),
                    },
                    {
                        target: u,
                        allowFailure: true,
                        callData: erc20Iface.encodeFunctionData('decimals'),
                    },
                    {
                        target: m,
                        allowFailure: false,
                        callData: marketIface.encodeFunctionData('getCash'),
                    },
                    {
                        target: m,
                        allowFailure: false,
                        callData:
                            marketIface.encodeFunctionData('totalBorrows'),
                    },
                    {
                        target: m,
                        allowFailure: false,
                        callData: marketIface.encodeFunctionData('totalSupply'),
                    },
                    {
                        target: m,
                        allowFailure: true,
                        callData: marketIface.encodeFunctionData('balanceOf', [
                            account,
                        ]),
                    },
                    // borrowBalanceCurrent is non-view but Multicall3.aggregate3 uses
                    // .call(); inside an eth_call the simulated state mutation gives
                    // us the up-to-the-block owed amount, then is discarded.
                    {
                        target: m,
                        allowFailure: true,
                        callData: marketIface.encodeFunctionData(
                            'borrowBalanceCurrent',
                            [account],
                        ),
                    },
                    {
                        target: u,
                        allowFailure: true,
                        callData: erc20Iface.encodeFunctionData('balanceOf', [
                            account,
                        ]),
                    },
                    {
                        target: u,
                        allowFailure: true,
                        callData: erc20Iface.encodeFunctionData('allowance', [
                            account,
                            m,
                        ]),
                    },
                    {
                        target: m,
                        allowFailure: false,
                        callData:
                            marketIface.encodeFunctionData(
                                'exchangeRateStored',
                            ),
                    },
                    {
                        target: m,
                        allowFailure: false,
                        callData: marketIface.encodeFunctionData(
                            'reserveFactorMantissa',
                        ),
                    },
                    {
                        target: comptrollerAddr,
                        allowFailure: false,
                        callData: comptrollerIface.encodeFunctionData(
                            'collateralFactor',
                            [m],
                        ),
                    },
                    {
                        target: oracleAddress,
                        allowFailure: true,
                        callData: oracleIface.encodeFunctionData(
                            'getUnderlyingPrice',
                            [m],
                        ),
                    },
                    {
                        target: rm,
                        allowFailure: false,
                        callData:
                            rateModelIface.encodeFunctionData('blocksPerYear'),
                    },
                );
            }

            const r3 = await multicall(calls);

            // After we have cash/borrows/reserveFactor we compute the rate-model calls in round 4.
            const stride = 14;
            const partial = allMarkets.map((m, i) => {
                const base = i * stride;

                return {
                    marketAddress: m,
                    underlyingAddress: underlyings[i],
                    symbol: r3[base].success
                        ? (erc20Iface.decodeFunctionResult(
                              'symbol',
                              r3[base].returnData,
                          )[0] as string)
                        : '?',
                    decimals: r3[base + 1].success
                        ? Number(
                              erc20Iface.decodeFunctionResult(
                                  'decimals',
                                  r3[base + 1].returnData,
                              )[0],
                          )
                        : 18,
                    cash: decodeBigint(
                        marketIface,
                        'getCash',
                        r3[base + 2].returnData,
                    ),
                    borrows: decodeBigint(
                        marketIface,
                        'totalBorrows',
                        r3[base + 3].returnData,
                    ),
                    supplyShares: decodeBigint(
                        marketIface,
                        'totalSupply',
                        r3[base + 4].returnData,
                    ),
                    userShares: r3[base + 5].success
                        ? decodeBigint(
                              marketIface,
                              'balanceOf',
                              r3[base + 5].returnData,
                          )
                        : 0n,
                    userBorrow: r3[base + 6].success
                        ? decodeBigint(
                              marketIface,
                              'borrowBalanceCurrent',
                              r3[base + 6].returnData,
                          )
                        : 0n,
                    userBalance: r3[base + 7].success
                        ? decodeBigint(
                              erc20Iface,
                              'balanceOf',
                              r3[base + 7].returnData,
                          )
                        : 0n,
                    allowance: r3[base + 8].success
                        ? decodeBigint(
                              erc20Iface,
                              'allowance',
                              r3[base + 8].returnData,
                          )
                        : 0n,
                    exchangeRate: decodeBigint(
                        marketIface,
                        'exchangeRateStored',
                        r3[base + 9].returnData,
                    ),
                    reserveFactor: decodeBigint(
                        marketIface,
                        'reserveFactorMantissa',
                        r3[base + 10].returnData,
                    ),
                    cf: decodeBigint(
                        comptrollerIface,
                        'collateralFactor',
                        r3[base + 11].returnData,
                    ),
                    priceMantissa: r3[base + 12].success
                        ? decodeBigint(
                              oracleIface,
                              'getUnderlyingPrice',
                              r3[base + 12].returnData,
                          )
                        : 0n,
                    blocksPerYear: decodeBigint(
                        rateModelIface,
                        'blocksPerYear',
                        r3[base + 13].returnData,
                    ),
                    rateModel: rateModels[i],
                };
            });

            // --- Round 4: borrow + supply rates (depend on cash/borrows/reserveFactor)
            const round4: Call[] = [];

            for (const p of partial) {
                round4.push(
                    {
                        target: p.rateModel,
                        allowFailure: false,
                        callData: rateModelIface.encodeFunctionData(
                            'getBorrowRate',
                            [p.cash, p.borrows, 0n],
                        ),
                    },
                    {
                        target: p.rateModel,
                        allowFailure: false,
                        callData: rateModelIface.encodeFunctionData(
                            'getSupplyRate',
                            [p.cash, p.borrows, 0n, p.reserveFactor],
                        ),
                    },
                );
            }

            const r4 = await multicall(round4);

            markets.value = partial.map((p, i) => {
                const borrowRate = decodeBigint(
                    rateModelIface,
                    'getBorrowRate',
                    r4[2 * i].returnData,
                );
                const supplyRate = decodeBigint(
                    rateModelIface,
                    'getSupplyRate',
                    r4[2 * i + 1].returnData,
                );

                return <MarketView>{
                    address: p.marketAddress,
                    underlying: p.underlyingAddress,
                    symbol: p.symbol,
                    decimals: p.decimals,
                    cash: p.cash,
                    totalBorrows: p.borrows,
                    totalSupplyUnderlying:
                        (p.supplyShares * p.exchangeRate) / MANTISSA,
                    supplyApy: rateToApy(supplyRate, p.blocksPerYear),
                    borrowApy: rateToApy(borrowRate, p.blocksPerYear),
                    collateralFactor: Number(p.cf) / 1e18,
                    priceMantissa: p.priceMantissa,
                    userSupplyShares: p.userShares,
                    userSupplyUnderlying:
                        (p.userShares * p.exchangeRate) / MANTISSA,
                    userBorrow: p.userBorrow,
                    userUnderlyingBalance: p.userBalance,
                    userAllowance: p.allowance,
                    entered: enteredSet.has(p.marketAddress.toLowerCase()),
                    exchangeRate: p.exchangeRate,
                };
            });
        } catch (e) {
            console.error('[lending] loadMarkets failed', e);
            error.value =
                e instanceof Error
                    ? e.message
                    : 'Failed to load lending markets';
            markets.value = [];
        } finally {
            loading.value = false;
        }
    }

    function setComptroller() {
        if (!inputComptroller.value) {
            return;
        }

        comptrollerAddress.value = inputComptroller.value.trim();
        loadMarkets();
    }

    return {
        wallet,
        authUser,
        queryAddress,
        comptrollerAddress,
        inputComptroller,
        markets,
        liquidity,
        loading,
        error,
        isReady,
        loadMarkets,
        setComptroller,
    };
}
