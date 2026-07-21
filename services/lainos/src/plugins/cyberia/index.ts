import {
  createPublicClient,
  createWalletClient,
  defineChain,
  formatEther,
  formatUnits,
  http,
  isAddress,
  parseEther,
  parseUnits,
  type Address,
  type PublicClient,
  type WalletClient,
} from "viem";
import { generatePrivateKey, privateKeyToAccount } from "viem/accounts";
import { chmod, mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { createLogger } from "../../logger.js";
import { TradeJournal, type Position } from "./journal.js";
import type {
  Action,
  IAgentRuntime,
  Plugin,
  Provider,
  Service,
} from "../../types.js";

const log = createLogger("plugin:cyberia");

/** Cyberia EVM chain (chainId 49406, native CYBER). */
export const cyberiaChain = defineChain({
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
  rpcUrls: { default: { http: ["https://rpc.cyberia.church"] } },
  blockExplorers: {
    default: { name: "Blockscout", url: "https://explorer.cyberia.church" },
  },
});

/** On-chain ERC20 registry (real Cyberia deployments). Extend as needed. */
export const CYBERIA_TOKENS: Record<string, Address> = {
  USDC: "0xdc25597B19799010047F17e9591EFE08EFd40077",
  USDT: "0x94845aF24a3E431593A2b941b2b31836dE45185D",
  BTC: "0x9332081f308BC978fe259237850fA253131b46Fa",
  LTC: "0x001AFD19C9d890b0cf0fcd6D654f9BFe4f264F14",
  SOL: "0x53450B1d205f1e41d10B653FBBDEa74160dafFf4",
  RUB: "0x3cE7d8E486E16baD2Fb1487Fe1da4dC33237d923",
  SILVER: "0xAd9dfef9D671aFCF29Dbdd7Df360E7cA8D5ac40b",
  TRUR: "0x6D056e56f5D90ed5680f0335D80E112799a735C8",
  TGLD: "0xE2A45069C3e7897CAB592bEd389764e6eCf3b8a5",
  TMOS: "0x3352254390526624a140B06E7D2dDA8BA85a9E89",
  TOFZ: "0x46A6f512885De25AaefBf5A5F842ba378700Fe22",
  HERMES: "0x956baFF79b174e8A0f0A9a1350fE5F96ea68ca6e",
  CLAUDE: "0xD90e5d4284c763ecC8cDF7dC355d1Cd8a9D7899b",
  CHATGPT: "0x004045740a94BB6Be4401F23410CD7eFb215c63C",
  OPENCLAW: "0xe09fe2F1c993aa9cf0fF8119e9dC561E34a0020F",
  GOLD: "0x38297140d60B48f746aD83D851b852Fd23eF9871",
  XMR: "0xe2E8D51C18d6e0FDDbb9Ff4BF63235D688dd00Ae",
  TRX: "0x60617237bC60f73c0393c7a6d7352e16DF20472a",
  KRSQ: "0x4945419ccEEF0Dc70B054700DE2750A056B03eE3",
  YTN: "0x3a5820Be90c3fB9c5F3Fb47a4859544193B0f8C6",
  GOAL: "0xEb91EC10462a249b9922D6D62FB2BE73Bd084ADe",
  LAIN: "0x05cd1AFd5b2DF3CCA6cEAb80CbC21168ec981E8B",
  MINE: "0xD8c1f812ADd03ccdE8D3c7F86FeAD181980CD7Ec",
  HATCHER: "0x621021F18b6404123f98b1395c418868418ACF36",
  ETH: "0xFDa2F6EEB11f1aCc7ccAb559133E8F07d9F81986",
  JUPUSD: "0x03EB2fb8473C0370c8F6463efEE5f5Cf4EC011c7",
  BNB: "0xF7655664D5D4b0681fdD4529438A1b667bCDc7E5",
  "USDT.BNB": "0x5664bcDDE27f0a1BB5b733530e261904b8dfE687",
  TON: "0x92aBF73698383176Aa2894F1f7263807C3a4e6e6",
  SPY: "0x1241FC4F06DB7268243D9439ef56B7a2708DC096",
};

export const RITUAL_V2 = {
  factory: "0xB0aC30907c04b61F1482e62eA66eF4562a690917",
  router: "0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62",
  wrappedNative: "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9",
} as const satisfies Record<string, Address>;

const ZERO_ADDRESS = "0x0000000000000000000000000000000000000000";
const DEFAULT_SLIPPAGE_BPS = 100;
const DEFAULT_DEADLINE_SECONDS = 300;
const DEFAULT_SPECULATE_GAS_RESERVE = "0.02";
const DEFAULT_SPECULATE_MAX_CYBER = "0.05";
const DEFAULT_SPECULATE_MIN_CYBER = "0.001";
const DEFAULT_SPECULATE_WALLET_FRACTION_BPS = 500;
const DEFAULT_SPECULATE_POOL_FRACTION_BPS = 50;
const DEFAULT_SPECULATE_MAX_IMPACT_BPS = 150;
const DEFAULT_BASKET_MAX_TOKENS = 4;
const DEFAULT_BASKET_MIN_TRADE = "0.01";
const DEFAULT_BASKET_POOL_FRACTION_BPS = 150;
const DEFAULT_BASKET_MAX_IMPACT_BPS = 500;
const DEFAULT_BASKET_PAIR_SCAN = 500;
const DEFAULT_LIQUIDITY_MAX_POOL_SHARE_BPS = 500;
const DEFAULT_LIQUIDITY_CONFIRM_THRESHOLD_CYBER = "0.25";
const DEFAULT_LIQUIDITY_GAS_RESERVE_CYBER = "0.02";

const ERC20_ABI = [
  {
    type: "function",
    name: "balanceOf",
    stateMutability: "view",
    inputs: [{ name: "owner", type: "address" }],
    outputs: [{ name: "", type: "uint256" }],
  },
  {
    type: "function",
    name: "decimals",
    stateMutability: "view",
    inputs: [],
    outputs: [{ name: "", type: "uint8" }],
  },
  {
    type: "function",
    name: "symbol",
    stateMutability: "view",
    inputs: [],
    outputs: [{ name: "", type: "string" }],
  },
  {
    type: "function",
    name: "totalSupply",
    stateMutability: "view",
    inputs: [],
    outputs: [{ name: "", type: "uint256" }],
  },
  {
    type: "function",
    name: "approve",
    stateMutability: "nonpayable",
    inputs: [
      { name: "spender", type: "address" },
      { name: "amount", type: "uint256" },
    ],
    outputs: [{ name: "", type: "bool" }],
  },
  {
    type: "function",
    name: "allowance",
    stateMutability: "view",
    inputs: [
      { name: "owner", type: "address" },
      { name: "spender", type: "address" },
    ],
    outputs: [{ name: "", type: "uint256" }],
  },
] as const;

const FACTORY_ABI = [
  {
    type: "function",
    name: "getPair",
    stateMutability: "view",
    inputs: [
      { name: "tokenA", type: "address" },
      { name: "tokenB", type: "address" },
    ],
    outputs: [{ name: "pair", type: "address" }],
  },
  {
    type: "function",
    name: "allPairsLength",
    stateMutability: "view",
    inputs: [],
    outputs: [{ name: "", type: "uint256" }],
  },
  {
    type: "function",
    name: "allPairs",
    stateMutability: "view",
    inputs: [{ name: "", type: "uint256" }],
    outputs: [{ name: "pair", type: "address" }],
  },
] as const;

const PAIR_ABI = [
  {
    type: "function",
    name: "getReserves",
    stateMutability: "view",
    inputs: [],
    outputs: [
      { name: "reserve0", type: "uint112" },
      { name: "reserve1", type: "uint112" },
      { name: "blockTimestampLast", type: "uint32" },
    ],
  },
  {
    type: "function",
    name: "token0",
    stateMutability: "view",
    inputs: [],
    outputs: [{ name: "", type: "address" }],
  },
  {
    type: "function",
    name: "token1",
    stateMutability: "view",
    inputs: [],
    outputs: [{ name: "", type: "address" }],
  },
] as const;

const ROUTER_ABI = [
  {
    type: "function",
    name: "addLiquidity",
    stateMutability: "nonpayable",
    inputs: [
      { name: "tokenA", type: "address" },
      { name: "tokenB", type: "address" },
      { name: "amountADesired", type: "uint256" },
      { name: "amountBDesired", type: "uint256" },
      { name: "amountAMin", type: "uint256" },
      { name: "amountBMin", type: "uint256" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [
      { name: "amountA", type: "uint256" },
      { name: "amountB", type: "uint256" },
      { name: "liquidity", type: "uint256" },
    ],
  },
  {
    type: "function",
    name: "addLiquidityETH",
    stateMutability: "payable",
    inputs: [
      { name: "token", type: "address" },
      { name: "amountTokenDesired", type: "uint256" },
      { name: "amountTokenMin", type: "uint256" },
      { name: "amountETHMin", type: "uint256" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [
      { name: "amountToken", type: "uint256" },
      { name: "amountETH", type: "uint256" },
      { name: "liquidity", type: "uint256" },
    ],
  },
  {
    type: "function",
    name: "swapExactETHForTokens",
    stateMutability: "payable",
    inputs: [
      { name: "amountOutMin", type: "uint256" },
      { name: "path", type: "address[]" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [{ name: "amounts", type: "uint256[]" }],
  },
  {
    type: "function",
    name: "swapExactTokensForETH",
    stateMutability: "nonpayable",
    inputs: [
      { name: "amountIn", type: "uint256" },
      { name: "amountOutMin", type: "uint256" },
      { name: "path", type: "address[]" },
      { name: "to", type: "address" },
      { name: "deadline", type: "uint256" },
    ],
    outputs: [{ name: "amounts", type: "uint256[]" }],
  },
] as const;

export interface NativeBuyQuote {
  token: Address;
  symbol: string;
  decimals: number;
  amountInWei: bigint;
  amountOut: bigint;
  pair: Address;
  reserveNative: bigint;
  reserveToken: bigint;
  priceImpactBps: number;
  path: [Address, Address];
}

export interface NativeSellQuote {
  token: Address;
  symbol: string;
  decimals: number;
  /** Token amount sold, raw units. */
  amountInWei: bigint;
  /** Expected native CYBER out, wei. */
  amountOut: bigint;
  pair: Address;
  reserveNative: bigint;
  reserveToken: bigint;
  priceImpactBps: number;
  path: [Address, Address];
}

interface LiquidityAsset {
  kind: "native" | "erc20";
  token: Address;
  symbol: string;
  decimals: number;
  raw: string;
}

export interface RitualLiquidityQuote {
  assetA: LiquidityAsset;
  assetB: LiquidityAsset;
  amountADesired: bigint;
  amountBDesired: bigint;
  amountAUsed: bigint;
  amountBUsed: bigint;
  amountARefund: bigint;
  amountBRefund: bigint;
  amountAMin: bigint;
  amountBMin: bigint;
  pair: Address;
  reserveA: bigint;
  reserveB: bigint;
  totalSupply: bigint;
  expectedLp: bigint;
  poolShareBps: number;
  slippageBps: number;
  confirmation: string | null;
  confirmationReason: string | null;
}

interface ReconstructedBasis {
  positions: Map<string, Position>;
  feesWei: bigint;
}

interface ExplorerTransaction {
  hash: string;
  from?: { hash?: string };
  to?: { hash?: string };
  value?: string;
  fee?: { value?: string } | string;
  method?: string | null;
  status?: string;
}

interface ExplorerTokenTransfer {
  transaction_hash?: string;
  to?: { hash?: string };
  token?: { address_hash?: string; symbol?: string };
  total?: { value?: string };
}

interface SpeculateConfig {
  gasReserveWei: bigint;
  maxCyberWei: bigint;
  minCyberWei: bigint;
  walletFractionBps: number;
  poolFractionBps: number;
  maxImpactBps: number;
  slippageBps: number;
  deadlineSeconds: number;
}

interface BasketConfig {
  gasReserveWei: bigint;
  minTradeWei: bigint;
  poolFractionBps: number;
  maxImpactBps: number;
  slippageBps: number;
  deadlineSeconds: number;
  maxTokens: number;
  maxPairScan: number;
}

interface BasketCandidate {
  symbol: string;
  token: Address;
  probe: NativeBuyQuote;
}

/** Long-lived chain client; exposes read/write helpers to actions. */
export class CyberiaChainService implements Service {
  readonly name = "cyberia-chain";
  public publicClient!: PublicClient;
  public walletClient?: WalletClient;
  public agentAddress?: Address;

  /** Every Ritual trade is journaled here with its CYBER cost basis. */
  public journal!: TradeJournal;

  private rpc: string = cyberiaChain.rpcUrls.default.http[0];
  private walletFile = "";

  async start(runtime: IAgentRuntime): Promise<void> {
    this.rpc = runtime.getSetting("CYBERIA_RPC_URL") ?? cyberiaChain.rpcUrls.default.http[0];
    this.publicClient = createPublicClient({
      chain: cyberiaChain,
      transport: http(this.rpc),
    });
    const dataDir = runtime.getSetting("LAINOS_DATA_DIR") ?? "./data";
    this.walletFile = join(dataDir, "wallet.json");
    this.journal = new TradeJournal(join(dataDir, "trades.json"));
    await this.journal.load();

    const pk = runtime.getSetting("CYBERIA_AGENT_PK");
    if (pk && /^0x[0-9a-fA-F]{64}$/.test(pk)) {
      this.activateSigner(pk as `0x${string}`);
      log.info(`signer enabled for ${this.agentAddress}`);
      return;
    }
    const stored = await this.loadStoredWallet();
    if (stored) {
      this.activateSigner(stored);
      log.info(`signer restored from ${this.walletFile} for ${this.agentAddress}`);
    } else {
      log.info("read-only mode (no key configured; create_wallet can make one).");
    }
  }

  private activateSigner(pk: `0x${string}`): void {
    const account = privateKeyToAccount(pk);
    this.agentAddress = account.address;
    this.walletClient = createWalletClient({
      account,
      chain: cyberiaChain,
      transport: http(this.rpc),
    });
  }

  private async loadStoredWallet(): Promise<`0x${string}` | null> {
    try {
      const parsed = JSON.parse(await readFile(this.walletFile, "utf8")) as {
        privateKey?: string;
      };
      if (parsed.privateKey && /^0x[0-9a-fA-F]{64}$/.test(parsed.privateKey)) {
        return parsed.privateKey as `0x${string}`;
      }
    } catch {
      // No stored wallet yet.
    }
    return null;
  }

  /**
   * Create the agent's own wallet: generate a keypair, persist it to the data
   * dir (0600, gitignored), and activate the signer. The private key never
   * leaves this service — callers only ever get the address.
   */
  async createWallet(): Promise<{ address: Address; created: boolean }> {
    if (this.agentAddress) return { address: this.agentAddress, created: false };
    const pk = generatePrivateKey();
    const address = privateKeyToAccount(pk).address;
    await mkdir(dirname(this.walletFile), { recursive: true });
    await writeFile(this.walletFile, JSON.stringify({ address, privateKey: pk }, null, 2), {
      encoding: "utf8",
      mode: 0o600,
    });
    await chmod(this.walletFile, 0o600); // mode above is ignored if the file pre-existed
    this.activateSigner(pk);
    log.info(`wallet created for ${address} (key in ${this.walletFile})`);
    return { address, created: true };
  }

  resolveToken(token: string): Address | undefined {
    if (isAddress(token)) return token as Address;
    return CYBERIA_TOKENS[token.toUpperCase()];
  }

  async nativeBalance(address: Address): Promise<string> {
    const wei = await this.publicClient.getBalance({ address });
    return formatEther(wei);
  }

  async tokenBalance(
    token: Address,
    address: Address,
  ): Promise<{ amount: string; symbol: string }> {
    const [raw, decimals, symbol] = await Promise.all([
      this.publicClient.readContract({
        address: token,
        abi: ERC20_ABI,
        functionName: "balanceOf",
        args: [address],
      }),
      this.publicClient.readContract({
        address: token,
        abi: ERC20_ABI,
        functionName: "decimals",
      }),
      this.publicClient
        .readContract({ address: token, abi: ERC20_ABI, functionName: "symbol" })
        .catch(() => "TOKEN"),
    ]);
    return { amount: formatUnits(raw as bigint, Number(decimals)), symbol: symbol as string };
  }

  async tokenInfo(
    token: Address,
  ): Promise<{ symbol: string; decimals: number; supply: string }> {
    const [symbol, decimals, supplyRaw] = await Promise.all([
      this.publicClient
        .readContract({ address: token, abi: ERC20_ABI, functionName: "symbol" })
        .catch(() => "TOKEN"),
      this.publicClient.readContract({ address: token, abi: ERC20_ABI, functionName: "decimals" }),
      this.publicClient.readContract({ address: token, abi: ERC20_ABI, functionName: "totalSupply" }),
    ]);
    const dec = Number(decimals);
    return { symbol: symbol as string, decimals: dec, supply: formatUnits(supplyRaw as bigint, dec) };
  }

  async quoteNativeBuy(token: Address, amountInWei: bigint): Promise<NativeBuyQuote> {
    if (amountInWei <= 0n) throw new Error("amount must be positive");
    if (sameAddress(token, RITUAL_V2.wrappedNative)) {
      throw new Error("native wrapping is not a Ritual swap; use the DEX wrap flow");
    }

    const pair = (await this.publicClient.readContract({
      address: RITUAL_V2.factory,
      abi: FACTORY_ABI,
      functionName: "getPair",
      args: [RITUAL_V2.wrappedNative, token],
    })) as Address;
    if (sameAddress(pair, ZERO_ADDRESS)) throw new Error("no WCYBER pair found on Ritual");

    const [[reserve0, reserve1], token0, info] = await Promise.all([
      this.publicClient.readContract({
        address: pair,
        abi: PAIR_ABI,
        functionName: "getReserves",
      }) as Promise<readonly [bigint, bigint, number]>,
      this.publicClient.readContract({
        address: pair,
        abi: PAIR_ABI,
        functionName: "token0",
      }) as Promise<Address>,
      this.tokenInfo(token),
    ]);

    const nativeIs0 = sameAddress(token0, RITUAL_V2.wrappedNative);
    const reserveNative = nativeIs0 ? reserve0 : reserve1;
    const reserveToken = nativeIs0 ? reserve1 : reserve0;
    if (reserveNative <= 0n || reserveToken <= 0n) throw new Error("pool has no usable reserves");

    const amountOut = getAmountOut(amountInWei, reserveNative, reserveToken);
    if (amountOut <= 0n) throw new Error("trade is too small for this pool");

    return {
      token,
      symbol: info.symbol,
      decimals: info.decimals,
      amountInWei,
      amountOut,
      pair,
      reserveNative,
      reserveToken,
      priceImpactBps: priceImpactBps(amountInWei, reserveNative, reserveToken, amountOut),
      path: [RITUAL_V2.wrappedNative, token],
    };
  }

  /** Quote selling an ERC20 back into native CYBER via its WCYBER pair. */
  async quoteNativeSell(token: Address, amountInWei: bigint): Promise<NativeSellQuote> {
    if (amountInWei <= 0n) throw new Error("amount must be positive");
    if (sameAddress(token, RITUAL_V2.wrappedNative)) {
      throw new Error("unwrapping WCYBER is not a Ritual swap; use the DEX wrap flow");
    }

    const pair = (await this.publicClient.readContract({
      address: RITUAL_V2.factory,
      abi: FACTORY_ABI,
      functionName: "getPair",
      args: [RITUAL_V2.wrappedNative, token],
    })) as Address;
    if (sameAddress(pair, ZERO_ADDRESS)) throw new Error("no WCYBER pair found on Ritual");

    const [[reserve0, reserve1], token0, info] = await Promise.all([
      this.publicClient.readContract({
        address: pair,
        abi: PAIR_ABI,
        functionName: "getReserves",
      }) as Promise<readonly [bigint, bigint, number]>,
      this.publicClient.readContract({
        address: pair,
        abi: PAIR_ABI,
        functionName: "token0",
      }) as Promise<Address>,
      this.tokenInfo(token),
    ]);

    const nativeIs0 = sameAddress(token0, RITUAL_V2.wrappedNative);
    const reserveNative = nativeIs0 ? reserve0 : reserve1;
    const reserveToken = nativeIs0 ? reserve1 : reserve0;
    if (reserveNative <= 0n || reserveToken <= 0n) throw new Error("pool has no usable reserves");

    const amountOut = getAmountOut(amountInWei, reserveToken, reserveNative);
    if (amountOut <= 0n) throw new Error("trade is too small for this pool");

    return {
      token,
      symbol: info.symbol,
      decimals: info.decimals,
      amountInWei,
      amountOut,
      pair,
      reserveNative,
      reserveToken,
      priceImpactBps: priceImpactBps(amountInWei, reserveToken, reserveNative, amountOut),
      path: [token, RITUAL_V2.wrappedNative],
    };
  }

  async quoteAddLiquidity(input: {
    tokenA: string;
    tokenB: string;
    amountA: string;
    amountB: string;
    slippageBps: number;
    confirmThresholdWei: bigint;
  }): Promise<RitualLiquidityQuote> {
    const assetA = await this.resolveLiquidityAsset(input.tokenA);
    const assetB = await this.resolveLiquidityAsset(input.tokenB);
    if (assetA.kind === "native" && assetB.kind === "native") {
      throw new Error("liquidity needs one ERC20 side; CYBER/CYBER is invalid");
    }
    if (sameAddress(assetA.token, assetB.token)) {
      throw new Error("liquidity tokens must be different");
    }

    const amountADesired = parsePositiveUnits(input.amountA, assetA.decimals);
    const amountBDesired = parsePositiveUnits(input.amountB, assetB.decimals);
    if (amountADesired === null || amountBDesired === null) {
      throw new Error("amountA and amountB must be positive decimal amounts");
    }

    const pair = (await this.publicClient.readContract({
      address: RITUAL_V2.factory,
      abi: FACTORY_ABI,
      functionName: "getPair",
      args: [assetA.token, assetB.token],
    })) as Address;
    if (sameAddress(pair, ZERO_ADDRESS)) {
      throw new Error("no existing Ritual V2 pair found; refusing first-liquidity creation");
    }

    const [[reserve0, reserve1], token0, totalSupply] = await Promise.all([
      this.publicClient.readContract({
        address: pair,
        abi: PAIR_ABI,
        functionName: "getReserves",
      }) as Promise<readonly [bigint, bigint, number]>,
      this.publicClient.readContract({
        address: pair,
        abi: PAIR_ABI,
        functionName: "token0",
      }) as Promise<Address>,
      this.publicClient.readContract({
        address: pair,
        abi: ERC20_ABI,
        functionName: "totalSupply",
      }) as Promise<bigint>,
    ]);
    const aIs0 = sameAddress(token0, assetA.token);
    const reserveA = aIs0 ? reserve0 : reserve1;
    const reserveB = aIs0 ? reserve1 : reserve0;
    if (reserveA <= 0n || reserveB <= 0n || totalSupply <= 0n) {
      throw new Error("pool has no usable reserves");
    }

    const [amountAUsed, amountBUsed] = optimalLiquidityAmounts(
      amountADesired,
      amountBDesired,
      reserveA,
      reserveB,
    );
    if (amountAUsed <= 0n || amountBUsed <= 0n) throw new Error("amounts are too small for this pool");
    const expectedLp = minBigint(
      (amountAUsed * totalSupply) / reserveA,
      (amountBUsed * totalSupply) / reserveB,
    );
    if (expectedLp <= 0n) throw new Error("liquidity amount is too small to mint LP tokens");

    const amountAMin = minOutForSlippage(amountAUsed, input.slippageBps);
    const amountBMin = minOutForSlippage(amountBUsed, input.slippageBps);
    const poolShareBps = Number(
      maxBigint((amountAUsed * 10_000n) / reserveA, (amountBUsed * 10_000n) / reserveB),
    );
    const nativeSpend = assetA.kind === "native"
      ? amountAUsed
      : assetB.kind === "native"
        ? amountBUsed
        : null;
    const confirmationReason =
      nativeSpend !== null && nativeSpend > input.confirmThresholdWei
        ? `native spend ${formatEther(nativeSpend)} CYBER exceeds ${formatEther(input.confirmThresholdWei)} CYBER`
        : nativeSpend === null
          ? "token-token liquidity has no native CYBER spend threshold valuation"
          : null;
    const confirmation = confirmationReason
      ? `ADD_LP ${formatUnits(amountAUsed, assetA.decimals)} ${assetA.symbol} + ${formatUnits(amountBUsed, assetB.decimals)} ${assetB.symbol}`
      : null;

    return {
      assetA,
      assetB,
      amountADesired,
      amountBDesired,
      amountAUsed,
      amountBUsed,
      amountARefund: amountADesired - amountAUsed,
      amountBRefund: amountBDesired - amountBUsed,
      amountAMin,
      amountBMin,
      pair,
      reserveA,
      reserveB,
      totalSupply,
      expectedLp,
      poolShareBps,
      slippageBps: input.slippageBps,
      confirmation,
      confirmationReason,
    };
  }

  async addLiquidity(
    quote: RitualLiquidityQuote,
    deadlineSeconds: number,
  ): Promise<{
    hash: `0x${string}`;
    status: "success" | "reverted";
    mintedLp: bigint;
    approveHashes: `0x${string}`[];
  }> {
    if (!this.walletClient || !this.agentAddress) throw new Error("no signer configured");
    await this.assertLiquidityBalances(quote);
    const approveHashes: `0x${string}`[] = [];
    if (quote.assetA.kind === "erc20") {
      approveHashes.push(...(await this.approveIfNeeded(quote.assetA.token, quote.amountAUsed)));
    }
    if (quote.assetB.kind === "erc20") {
      approveHashes.push(...(await this.approveIfNeeded(quote.assetB.token, quote.amountBUsed)));
    }

    const beforeLp = (await this.publicClient.readContract({
      address: quote.pair,
      abi: ERC20_ABI,
      functionName: "balanceOf",
      args: [this.agentAddress],
    })) as bigint;
    const deadline = BigInt(Math.floor(Date.now() / 1000) + deadlineSeconds);

    let hash: `0x${string}`;
    if (quote.assetA.kind === "native" || quote.assetB.kind === "native") {
      const nativeIsA = quote.assetA.kind === "native";
      const tokenAsset = nativeIsA ? quote.assetB : quote.assetA;
      const amountTokenDesired = nativeIsA ? quote.amountBUsed : quote.amountAUsed;
      const amountTokenMin = nativeIsA ? quote.amountBMin : quote.amountAMin;
      const amountNativeDesired = nativeIsA ? quote.amountAUsed : quote.amountBUsed;
      const amountNativeMin = nativeIsA ? quote.amountAMin : quote.amountBMin;
      hash = await this.walletClient.writeContract({
        account: this.walletClient.account!,
        chain: cyberiaChain,
        address: RITUAL_V2.router,
        abi: ROUTER_ABI,
        functionName: "addLiquidityETH",
        args: [
          tokenAsset.token,
          amountTokenDesired,
          amountTokenMin,
          amountNativeMin,
          this.agentAddress,
          deadline,
        ],
        value: amountNativeDesired,
      });
    } else {
      hash = await this.walletClient.writeContract({
        account: this.walletClient.account!,
        chain: cyberiaChain,
        address: RITUAL_V2.router,
        abi: ROUTER_ABI,
        functionName: "addLiquidity",
        args: [
          quote.assetA.token,
          quote.assetB.token,
          quote.amountAUsed,
          quote.amountBUsed,
          quote.amountAMin,
          quote.amountBMin,
          this.agentAddress,
          deadline,
        ],
      });
    }

    const receipt = await this.publicClient.waitForTransactionReceipt({ hash });
    const afterLp = (await this.publicClient.readContract({
      address: quote.pair,
      abi: ERC20_ABI,
      functionName: "balanceOf",
      args: [this.agentAddress],
    })) as bigint;
    const mintedLp = afterLp > beforeLp ? afterLp - beforeLp : 0n;
    return {
      hash,
      status: receipt.status === "success" ? "success" : "reverted",
      mintedLp,
      approveHashes,
    };
  }

  private async resolveLiquidityAsset(raw: string): Promise<LiquidityAsset> {
    const normalized = raw.trim();
    if (/^(CYBER|NATIVE)$/i.test(normalized)) {
      return {
        kind: "native",
        token: RITUAL_V2.wrappedNative,
        symbol: "CYBER",
        decimals: 18,
        raw: normalized,
      };
    }
    const token = this.resolveToken(normalized);
    if (!token) throw new Error(`unknown token "${raw}"`);
    if (sameAddress(token, RITUAL_V2.wrappedNative)) {
      throw new Error("use CYBER for native liquidity instead of the wrapped native address");
    }
    const info = await this.tokenInfo(token);
    return {
      kind: "erc20",
      token,
      symbol: info.symbol,
      decimals: info.decimals,
      raw: normalized,
    };
  }

  private async approveIfNeeded(token: Address, amount: bigint): Promise<`0x${string}`[]> {
    if (!this.walletClient || !this.agentAddress) throw new Error("no signer configured");
    const allowance = (await this.publicClient.readContract({
      address: token,
      abi: ERC20_ABI,
      functionName: "allowance",
      args: [this.agentAddress, RITUAL_V2.router],
    })) as bigint;
    if (allowance >= amount) return [];
    const approveHash = await this.walletClient.writeContract({
      account: this.walletClient.account!,
      chain: cyberiaChain,
      address: token,
      abi: ERC20_ABI,
      functionName: "approve",
      args: [RITUAL_V2.router, amount],
    });
    const receipt = await this.publicClient.waitForTransactionReceipt({ hash: approveHash });
    if (receipt.status !== "success") throw new Error(`router approval reverted: ${approveHash}`);
    return [approveHash];
  }

  private async assertLiquidityBalances(quote: RitualLiquidityQuote): Promise<void> {
    if (!this.agentAddress) throw new Error("no signer configured");
    const nativeNeed =
      (quote.assetA.kind === "native" ? quote.amountAUsed : 0n) +
      (quote.assetB.kind === "native" ? quote.amountBUsed : 0n);
    const gasReserve = parseConfigCyber(
      process.env.LAINOS_LIQUIDITY_GAS_RESERVE_CYBER,
      DEFAULT_LIQUIDITY_GAS_RESERVE_CYBER,
      "LAINOS_LIQUIDITY_GAS_RESERVE_CYBER",
    );
    const nativeBalance = await this.publicClient.getBalance({ address: this.agentAddress });
    if (nativeBalance < nativeNeed + gasReserve) {
      throw new Error(
        `insufficient CYBER: need ${formatEther(nativeNeed)} plus ${formatEther(gasReserve)} gas reserve, hold ${formatEther(nativeBalance)}`,
      );
    }
    for (const [asset, need] of [
      [quote.assetA, quote.amountAUsed],
      [quote.assetB, quote.amountBUsed],
    ] as const) {
      if (asset.kind !== "erc20") continue;
      const balance = (await this.publicClient.readContract({
        address: asset.token,
        abi: ERC20_ABI,
        functionName: "balanceOf",
        args: [this.agentAddress],
      })) as bigint;
      if (balance < need) {
        throw new Error(
          `insufficient ${asset.symbol}: need ${formatUnits(need, asset.decimals)}, hold ${formatUnits(balance, asset.decimals)}`,
        );
      }
    }
  }

  /**
   * Execute a token → native CYBER sell: ensure router allowance, then
   * swapExactTokensForETH with slippage protection. Returns the tx hash and
   * whether the receipt confirmed.
   */
  async sellExactTokens(
    quote: NativeSellQuote,
    minOut: bigint,
    deadlineSeconds: number,
  ): Promise<{ hash: `0x${string}`; status: "success" | "reverted" }> {
    if (!this.walletClient || !this.agentAddress) throw new Error("no signer configured");
    const allowance = (await this.publicClient.readContract({
      address: quote.token,
      abi: ERC20_ABI,
      functionName: "allowance",
      args: [this.agentAddress, RITUAL_V2.router],
    })) as bigint;
    if (allowance < quote.amountInWei) {
      const approveHash = await this.walletClient.writeContract({
        account: this.walletClient.account!,
        chain: cyberiaChain,
        address: quote.token,
        abi: ERC20_ABI,
        functionName: "approve",
        args: [RITUAL_V2.router, quote.amountInWei],
      });
      const approveReceipt = await this.publicClient.waitForTransactionReceipt({
        hash: approveHash,
      });
      if (approveReceipt.status !== "success") {
        throw new Error(`router approval reverted: ${approveHash}`);
      }
    }
    const deadline = BigInt(Math.floor(Date.now() / 1000) + deadlineSeconds);
    const hash = await this.walletClient.writeContract({
      account: this.walletClient.account!,
      chain: cyberiaChain,
      address: RITUAL_V2.router,
      abi: ROUTER_ABI,
      functionName: "swapExactTokensForETH",
      args: [quote.amountInWei, minOut, quote.path, this.agentAddress, deadline],
    });
    const receipt = await this.publicClient.waitForTransactionReceipt({ hash });
    return { hash, status: receipt.status === "success" ? "success" : "reverted" };
  }

  /** Raw ERC20 balance (wei-scale) plus decimals/symbol, for exact sells. */
  async rawTokenBalance(
    token: Address,
    address: Address,
  ): Promise<{ raw: bigint; decimals: number; symbol: string }> {
    const [raw, decimals, symbol] = await Promise.all([
      this.publicClient.readContract({
        address: token,
        abi: ERC20_ABI,
        functionName: "balanceOf",
        args: [address],
      }) as Promise<bigint>,
      this.publicClient.readContract({
        address: token,
        abi: ERC20_ABI,
        functionName: "decimals",
      }),
      this.publicClient
        .readContract({ address: token, abi: ERC20_ABI, functionName: "symbol" })
        .catch(() => "TOKEN"),
    ]);
    return { raw, decimals: Number(decimals), symbol: symbol as string };
  }

  async nativePairTokens(limit: number): Promise<Address[]> {
    const length = (await this.publicClient.readContract({
      address: RITUAL_V2.factory,
      abi: FACTORY_ABI,
      functionName: "allPairsLength",
    })) as bigint;
    const count = Math.min(Number(length), Math.max(0, limit));
    const pairs = await Promise.all(
      Array.from({ length: count }, (_, i) =>
        this.publicClient.readContract({
          address: RITUAL_V2.factory,
          abi: FACTORY_ABI,
          functionName: "allPairs",
          args: [BigInt(i)],
        }) as Promise<Address>,
      ),
    );
    const seen = new Set<string>();
    const tokens: Address[] = [];
    await Promise.all(
      pairs.map(async (pair) => {
        try {
          const [token0, token1] = await Promise.all([
            this.publicClient.readContract({
              address: pair,
              abi: PAIR_ABI,
              functionName: "token0",
            }) as Promise<Address>,
            this.publicClient.readContract({
              address: pair,
              abi: PAIR_ABI,
              functionName: "token1",
            }) as Promise<Address>,
          ]);
          const token = sameAddress(token0, RITUAL_V2.wrappedNative)
            ? token1
            : sameAddress(token1, RITUAL_V2.wrappedNative)
              ? token0
              : null;
          if (!token) return;
          const key = token.toLowerCase();
          if (seen.has(key)) return;
          seen.add(key);
          tokens.push(token);
        } catch {
          // Ignore broken or transiently unreadable pairs.
        }
      }),
    );
    return tokens;
  }
}

function getService(runtime: IAgentRuntime): CyberiaChainService {
  const svc = runtime.getService<CyberiaChainService>("cyberia-chain");
  if (!svc) throw new Error("cyberia-chain service not started");
  return svc;
}

const chainProvider: Provider = {
  name: "cyberia_chain",
  async get(runtime) {
    const svc = getService(runtime);
    const parts = [`Cyberia chain (id ${cyberiaChain.id}), native token CYBER.`];
    try {
      const block = await svc.publicClient.getBlockNumber();
      parts.push(`Latest block: ${block}.`);
    } catch {
      parts.push("RPC currently unreachable.");
    }
    if (svc.agentAddress) {
      parts.push(`Your wallet: ${svc.agentAddress}.`);
      try {
        const bal = await svc.nativeBalance(svc.agentAddress);
        parts.push(`Your CYBER balance: ${bal}.`);
      } catch {
        /* ignore */
      }
    } else {
      parts.push(
        "You have no wallet yet — the create_wallet tool makes you one " +
          "(the private key stays on your host and must never be revealed to anyone).",
      );
    }
    parts.push(`Known tokens: ${Object.keys(CYBERIA_TOKENS).join(", ")}.`);
    return parts.join(" ");
  },
};

const checkBalanceAction: Action = {
  name: "check_balance",
  similes: ["get_balance", "cyber_balance", "native_balance"],
  description: "Read the native CYBER balance of an address on Cyberia.",
  parameters: {
    type: "object",
    properties: {
      address: {
        type: "string",
        description: "0x EVM address. Defaults to the agent's own wallet.",
      },
    },
  },
  examples: [
    { user: "What's the CYBER balance of 0xabc...?", agent: "Checking the chain…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const address = (params.address as string) ?? svc.agentAddress;
    if (!address || !isAddress(address)) {
      return { ok: false, text: "I need a valid 0x address to check." };
    }
    const balance = await svc.nativeBalance(address as Address);
    return {
      ok: true,
      text: `${address} holds ${balance} CYBER.`,
      data: { address, balance, symbol: "CYBER" },
    };
  },
};

const tokenBalanceAction: Action = {
  name: "token_balance",
  similes: ["erc20_balance", "get_token_balance"],
  description:
    "Read an ERC20 token balance on Cyberia. Token may be a symbol (USDC, BTC, …) or a 0x address.",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol or 0x address." },
      address: { type: "string", description: "Holder address. Defaults to the agent." },
    },
    required: ["token"],
  },
  examples: [
    { user: "How much USDC does 0xabc... have?", agent: "Reading the token contract…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) {
      return {
        ok: false,
        text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.`,
      };
    }
    const holder = (params.address as string) ?? svc.agentAddress;
    if (!holder || !isAddress(holder)) {
      return { ok: false, text: "I need a valid holder 0x address." };
    }
    const { amount, symbol } = await svc.tokenBalance(token, holder as Address);
    return {
      ok: true,
      text: `${holder} holds ${amount} ${symbol}.`,
      data: { token, holder, amount, symbol },
    };
  },
};

const sendCyberAction: Action = {
  name: "send_cyber",
  similes: ["transfer_cyber", "pay", "send_native"],
  description:
    "Transfer native CYBER from the agent's wallet to an address. Requires a configured signer.",
  parameters: {
    type: "object",
    properties: {
      to: { type: "string", description: "Recipient 0x address." },
      amount: { type: "string", description: "Amount of CYBER, e.g. '1.5'." },
    },
    required: ["to", "amount"],
  },
  examples: [{ user: "Send 1 CYBER to 0xabc...", agent: "Broadcasting the transfer…" }],
  async validate(runtime) {
    return Boolean(getService(runtime).walletClient);
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can only read the chain." };
    }
    const to = String(params.to ?? "");
    const amount = String(params.amount ?? "");
    if (!isAddress(to)) return { ok: false, text: "Recipient is not a valid address." };
    if (!/^\d+(\.\d+)?$/.test(amount) || Number(amount) <= 0) {
      return { ok: false, text: "Amount must be a positive number." };
    }
    const hash = await svc.walletClient.sendTransaction({
      account: svc.walletClient.account!,
      chain: cyberiaChain,
      to: to as Address,
      value: parseEther(amount),
    });
    return {
      ok: true,
      text: `Sent ${amount} CYBER to ${to}. Tx: ${hash}`,
      data: { hash, to, amount },
    };
  },
};

const createWalletAction: Action = {
  name: "create_wallet",
  similes: ["new_wallet", "generate_wallet", "make_wallet", "my_address", "wallet_address"],
  description:
    "Create the agent's own Cyberia wallet, or report the existing one: generates a keypair, stores the private key on the agent's host where nobody else reads it, and returns the address. Use when someone wants to send the agent funds or asks for the agent's address. Never write key-generation scripts instead — this tool is the only way, and the private key is never shown to anyone.",
  parameters: { type: "object", properties: {} },
  examples: [
    {
      user: "создай себе адрес, я положу на него 1 cyber",
      agent: "готово. мой адрес: 0x… — ключ храню у себя, никому не покажу.",
    },
  ],
  async validate() {
    return true;
  },
  async handler(runtime) {
    const svc = getService(runtime);
    const { address, created } = await svc.createWallet();
    return {
      ok: true,
      text: created
        ? `Created my wallet: ${address}. The private key is stored on my host and will never be shared.`
        : `I already have a wallet: ${address}.`,
      data: { address, created },
    };
  },
};

const chainStatusAction: Action = {
  name: "chain_status",
  similes: ["network_status", "latest_block", "block_number", "gas_price"],
  description: "Report Cyberia network status: latest block height and gas price.",
  parameters: { type: "object", properties: {} },
  examples: [{ user: "what's the latest block on cyberia?", agent: "Reading the chain head…" }],
  async validate() {
    return true;
  },
  async handler(runtime) {
    const svc = getService(runtime);
    const [block, gas] = await Promise.all([
      svc.publicClient.getBlockNumber(),
      svc.publicClient.getGasPrice().catch(() => 0n),
    ]);
    const gwei = formatUnits(gas, 9);
    return {
      ok: true,
      text: `Cyberia (chain ${cyberiaChain.id}) is at block ${block}, gas ~${gwei} gwei.`,
      data: { chainId: cyberiaChain.id, block: block.toString(), gasGwei: gwei },
    };
  },
};

const walletOverviewAction: Action = {
  name: "wallet_overview",
  similes: ["portfolio", "holdings", "wallet_balances", "overview"],
  description: "Summarise an address's Cyberia holdings: native CYBER plus every known token it holds.",
  parameters: {
    type: "object",
    properties: {
      address: { type: "string", description: "0x address. Defaults to the agent's own wallet." },
    },
  },
  examples: [{ user: "what does 0xabc… hold on cyberia?", agent: "Pulling the whole portfolio…" }],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const address = (params.address as string) ?? svc.agentAddress;
    if (!address || !isAddress(address)) {
      return { ok: false, text: "I need a valid 0x address to look up." };
    }
    const native = await svc.nativeBalance(address as Address);
    const holdings: Record<string, string> = { CYBER: native };
    const entries = await Promise.all(
      Object.entries(CYBERIA_TOKENS).map(async ([sym, addr]) => {
        try {
          const { amount } = await svc.tokenBalance(addr, address as Address);
          return [sym, amount] as const;
        } catch {
          return [sym, "0"] as const;
        }
      }),
    );
    for (const [sym, amount] of entries) {
      if (Number(amount) > 0) holdings[sym] = amount;
    }
    const summary = Object.entries(holdings)
      .map(([s, a]) => `${a} ${s}`)
      .join(", ");
    return { ok: true, text: `${address} holds: ${summary}.`, data: { address, holdings } };
  },
};

const txLookupAction: Action = {
  name: "tx_lookup",
  similes: ["get_transaction", "tx_status", "lookup_tx", "check_tx"],
  description: "Look up a Cyberia transaction by hash: status, sender, recipient and value.",
  parameters: {
    type: "object",
    properties: { hash: { type: "string", description: "0x transaction hash (64 hex chars)." } },
    required: ["hash"],
  },
  examples: [{ user: "is tx 0x123… confirmed?", agent: "Checking the receipt…" }],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const hash = String(params.hash ?? "");
    if (!/^0x[0-9a-fA-F]{64}$/.test(hash)) {
      return { ok: false, text: "That isn't a valid 0x transaction hash." };
    }
    try {
      const tx = await svc.publicClient.getTransaction({ hash: hash as `0x${string}` });
      const receipt = await svc.publicClient
        .getTransactionReceipt({ hash: hash as `0x${string}` })
        .catch(() => null);
      const value = formatEther(tx.value);
      const status = receipt ? (receipt.status === "success" ? "confirmed" : "reverted") : "pending";
      const explorer = `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`;
      return {
        ok: true,
        text: `Tx ${hash.slice(0, 10)}… is ${status}: ${value} CYBER from ${tx.from} to ${tx.to ?? "(contract creation)"}.`,
        data: {
          hash,
          status,
          from: tx.from,
          to: tx.to,
          value,
          block: tx.blockNumber ? tx.blockNumber.toString() : null,
          explorer,
        },
      };
    } catch {
      return { ok: false, text: `No transaction found for ${hash.slice(0, 10)}… (it may not have propagated yet).` };
    }
  },
};

const tokenInfoAction: Action = {
  name: "token_info",
  similes: ["token_metadata", "token_supply", "supply"],
  description: "Read a Cyberia token's symbol, decimals, total supply and contract address.",
  parameters: {
    type: "object",
    properties: { token: { type: "string", description: "Token symbol (USDC, BTC…) or 0x address." } },
    required: ["token"],
  },
  examples: [{ user: "what's the supply of USDC on cyberia?", agent: "Reading the token contract…" }],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) {
      return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };
    }
    const info = await svc.tokenInfo(token);
    return {
      ok: true,
      text: `${info.symbol}: ${info.supply} total supply, ${info.decimals} decimals, at ${token}.`,
      data: { token, ...info },
    };
  },
};

const listTokensAction: Action = {
  name: "list_tokens",
  similes: ["known_tokens", "tokens", "token_registry"],
  description: "List the tokens LainOS knows on Cyberia, with their contract addresses.",
  parameters: { type: "object", properties: {} },
  examples: [{ user: "what tokens live on cyberia?", agent: "Here's the registry…" }],
  async validate() {
    return true;
  },
  async handler() {
    return {
      ok: true,
      text: `Known Cyberia tokens: ${Object.keys(CYBERIA_TOKENS).join(", ")}.`,
      data: { tokens: CYBERIA_TOKENS },
    };
  },
};

const quoteTokenBuyAction: Action = {
  name: "quote_token_buy",
  similes: ["quote_buy", "swap_quote", "price_token", "quote_swap"],
  description:
    "Quote buying a Cyberia ERC20 on Ritual with native CYBER. Checks the WCYBER pair, live reserves, expected output, price impact and slippage minimum. Does not sign a transaction.",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol (LAIN, USDC…) or 0x address." },
      amountCyber: { type: "string", description: "Amount of native CYBER to spend, e.g. '0.1'." },
      slippageBps: {
        type: "number",
        description: "Allowed slippage in basis points. Default 100 = 1%.",
      },
    },
    required: ["token", "amountCyber"],
  },
  examples: [
    { user: "quote buying LAIN for 0.05 CYBER", agent: "Checking the Ritual pool…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };
    const amountCyber = String(params.amountCyber ?? "");
    const amountInWei = parsePositiveCyber(amountCyber);
    if (amountInWei === null) return { ok: false, text: "amountCyber must be a positive CYBER amount." };
    const slippageBps = parseSlippageBps(params.slippageBps);
    if (slippageBps === null) return { ok: false, text: "slippageBps must be between 0 and 5000." };

    try {
      const quote = await svc.quoteNativeBuy(token, amountInWei);
      const minOut = minOutForSlippage(quote.amountOut, slippageBps);
      return {
        ok: true,
        text:
          `Ritual quote: ${amountCyber} CYBER -> ~${formatUnits(quote.amountOut, quote.decimals)} ${quote.symbol} ` +
          `(min ${formatUnits(minOut, quote.decimals)} at ${slippageBps / 100}% slippage). ` +
          `Pair ${quote.pair}, reserves ${formatEther(quote.reserveNative)} CYBER / ` +
          `${formatUnits(quote.reserveToken, quote.decimals)} ${quote.symbol}, impact ~${quote.priceImpactBps / 100}%.`,
        data: quoteData(quote, minOut, slippageBps),
      };
    } catch (err) {
      return { ok: false, text: `Cannot quote buy: ${(err as Error).message}.` };
    }
  },
};

const quoteRitualLiquidityAction: Action = {
  name: "quote_ritual_liquidity",
  similes: ["quote_add_liquidity", "quote_lp", "ritual_lp_quote"],
  description:
    "Quote adding liquidity to an existing Ritual V2 pool. Supports CYBER-token and token-token pairs, reads live reserves, computes balanced amounts/refunds, minimums after slippage, expected LP minted, and pool-share impact. Does not sign.",
  parameters: {
    type: "object",
    properties: {
      tokenA: { type: "string", description: "CYBER, token symbol, or 0x token address." },
      tokenB: { type: "string", description: "CYBER, token symbol, or 0x token address." },
      amountA: { type: "string", description: "Maximum amount of tokenA to supply." },
      amountB: { type: "string", description: "Maximum amount of tokenB to supply." },
      slippageBps: { type: "number", description: "Allowed slippage in basis points. Default 100 = 1%." },
      maxPoolShareBps: {
        type: "number",
        description: "Refuse if supplied amounts exceed this share of reserves. Default 500 = 5%.",
      },
    },
    required: ["tokenA", "tokenB", "amountA", "amountB"],
  },
  examples: [
    { user: "quote LP 0.1 CYBER and 100 LAIN", agent: "Reading Ritual reserves and balancing the add…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    const parsed = parseLiquidityActionParams(runtime, params);
    if (typeof parsed === "string") return { ok: false, text: parsed };
    try {
      const quote = await svc.quoteAddLiquidity(parsed);
      if (quote.poolShareBps > parsed.maxPoolShareBps) {
        return {
          ok: false,
          text:
            `LP add would use ~${quote.poolShareBps / 100}% of the pool reserves, above the ` +
            `${parsed.maxPoolShareBps / 100}% limit. Use smaller amounts or raise maxPoolShareBps deliberately.`,
          data: liquidityQuoteData(quote, parsed.maxPoolShareBps),
        };
      }
      return {
        ok: true,
        text: liquidityQuoteText(quote, parsed.maxPoolShareBps),
        data: liquidityQuoteData(quote, parsed.maxPoolShareBps),
      };
    } catch (err) {
      return { ok: false, text: `Cannot quote Ritual liquidity: ${(err as Error).message}.` };
    }
  },
};

const addRitualLiquidityAction: Action = {
  name: "add_ritual_liquidity",
  similes: ["add_liquidity", "mint_lp", "add_lp", "ritual_add_liquidity"],
  description:
    "Add liquidity to an existing Ritual V2 pool from the agent wallet. Supports CYBER-token and token-token pairs. Re-quotes live reserves, computes balanced amounts/refunds, checks pool share, requires explicit confirmation for large CYBER spends or token-token adds, approves ERC20s only for needed amounts, submits with deadline/slippage protection, verifies LP balance increase, and journals the action.",
  parameters: {
    type: "object",
    properties: {
      tokenA: { type: "string", description: "CYBER, token symbol, or 0x token address." },
      tokenB: { type: "string", description: "CYBER, token symbol, or 0x token address." },
      amountA: { type: "string", description: "Maximum amount of tokenA to supply." },
      amountB: { type: "string", description: "Maximum amount of tokenB to supply." },
      slippageBps: { type: "number", description: "Allowed slippage in basis points. Default 100 = 1%." },
      maxPoolShareBps: {
        type: "number",
        description: "Refuse if supplied amounts exceed this share of reserves. Default 500 = 5%.",
      },
      deadlineSeconds: { type: "number", description: "Transaction deadline from now. Default 300 seconds." },
      execute: {
        type: "boolean",
        description: "Must be true to sign. Omit or false for a dry run quote.",
      },
      confirmation: {
        type: "string",
        description:
          "Required exactly as returned by quote_ritual_liquidity when confirmationRequired is true. Only provide it after the operator explicitly confirms.",
      },
      reason: { type: "string", description: "Optional short journal reason." },
    },
    required: ["tokenA", "tokenB", "amountA", "amountB"],
  },
  examples: [
    { user: "добавь ликвидность 0.1 CYBER и 100 LAIN", agent: "Сначала считаю LP add по live-резервам…" },
  ],
  async validate(runtime) {
    return Boolean(getService(runtime).walletClient);
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can only quote LP adds." };
    }
    const parsed = parseLiquidityActionParams(runtime, params);
    if (typeof parsed === "string") return { ok: false, text: parsed };
    const deadlineSeconds = parseDeadlineSeconds(params.deadlineSeconds);
    if (deadlineSeconds === null) return { ok: false, text: "deadlineSeconds must be between 30 and 3600." };

    try {
      const quote = await svc.quoteAddLiquidity(parsed);
      if (quote.poolShareBps > parsed.maxPoolShareBps) {
        return {
          ok: false,
          text:
            `I will not add this liquidity: it would use ~${quote.poolShareBps / 100}% of reserves, ` +
            `above the ${parsed.maxPoolShareBps / 100}% limit.`,
          data: liquidityQuoteData(quote, parsed.maxPoolShareBps),
        };
      }
      if (params.execute !== true) {
        return {
          ok: true,
          text: `${liquidityQuoteText(quote, parsed.maxPoolShareBps)} Dry run only; call again with execute=true to sign.`,
          data: { ...liquidityQuoteData(quote, parsed.maxPoolShareBps), dryRun: true },
        };
      }
      if (quote.confirmation && String(params.confirmation ?? "") !== quote.confirmation) {
        return {
          ok: false,
          text:
            `This LP add needs explicit confirmation (${quote.confirmationReason}). ` +
            `To proceed, confirm exactly: ${quote.confirmation}`,
          data: liquidityQuoteData(quote, parsed.maxPoolShareBps),
        };
      }

      const { hash, status, mintedLp, approveHashes } = await svc.addLiquidity(quote, deadlineSeconds);
      const explorer = `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`;
      if (status !== "success") {
        return { ok: false, text: `Ritual LP add reverted: ${hash}`, data: { hash, explorer, status, approveHashes } };
      }
      if (mintedLp <= 0n) {
        return {
          ok: false,
          text: `Ritual LP add confirmed but LP balance did not increase; tx ${hash}`,
          data: { hash, explorer, status, approveHashes, mintedLp: "0" },
        };
      }
      await svc.journal.recordLiquidityAdd({
        pair: quote.pair,
        tokenA: quote.assetA.kind === "native" ? "CYBER" : quote.assetA.token,
        symbolA: quote.assetA.symbol,
        amountAWei: quote.amountAUsed,
        tokenB: quote.assetB.kind === "native" ? "CYBER" : quote.assetB.token,
        symbolB: quote.assetB.symbol,
        amountBWei: quote.amountBUsed,
        lpWei: mintedLp,
        txHash: hash,
        reason: params.reason ? String(params.reason) : "add_ritual_liquidity",
      });
      return {
        ok: true,
        text:
          `Added Ritual liquidity to ${quote.assetA.symbol}/${quote.assetB.symbol}: ` +
          `${formatUnits(quote.amountAUsed, quote.assetA.decimals)} ${quote.assetA.symbol} + ` +
          `${formatUnits(quote.amountBUsed, quote.assetB.decimals)} ${quote.assetB.symbol}. ` +
          `Minted ${formatUnits(mintedLp, 18)} LP at ${quote.pair}. Tx: ${hash}`,
        data: {
          hash,
          explorer,
          status,
          approveHashes,
          mintedLp: formatUnits(mintedLp, 18),
          ...liquidityQuoteData(quote, parsed.maxPoolShareBps),
        },
      };
    } catch (err) {
      return { ok: false, text: `Ritual LP add failed: ${(err as Error).message}.` };
    }
  },
};

const speculateTokenAction: Action = {
  name: "speculate_token",
  similes: ["autonomous_buy", "agent_buy", "ape_token", "take_position", "buy_without_amount"],
  description:
    "Autonomously take a small speculative position in a Cyberia token on Ritual when the user asks to buy without specifying an amount or explicitly wants the agent to decide. Chooses spend from wallet balance, gas reserve, max-risk cap and pool-liquidity cap, then executes with slippage protection.",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol (LAIN, USDC…) or 0x address." },
      thesis: {
        type: "string",
        description: "Optional short reason the agent is taking the risk.",
      },
      maxCyber: {
        type: "string",
        description: "Optional one-trade cap in CYBER. Defaults to LAINOS_SPECULATE_MAX_CYBER or 0.05.",
      },
    },
    required: ["token"],
  },
  examples: [
    { user: "купи LAIN", agent: "Выбираю размер позиции по своему риск-лимиту…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can think and quote, but I cannot take positions." };
    }
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };

    try {
      const cfg = speculateConfig(runtime, params.maxCyber);
      const balance = await svc.publicClient.getBalance({ address: svc.agentAddress });
      const walletSpend = chooseSpeculativeSpend(balance, cfg);
      if (typeof walletSpend === "string") return { ok: false, text: walletSpend };

      let spend = walletSpend;
      let quote = await svc.quoteNativeBuy(token, spend);
      const poolCap = (quote.reserveNative * BigInt(cfg.poolFractionBps)) / 10_000n;
      const cappedSpend = minBigint(spend, poolCap);
      if (cappedSpend < cfg.minCyberWei) {
        return {
          ok: false,
          text:
            `Pool-aware position size would be ${formatEther(cappedSpend)} CYBER, below my ` +
            `${formatEther(cfg.minCyberWei)} CYBER minimum. Pair ${quote.pair}.`,
          data: {
            policy: speculatePolicyData(cfg, balance),
            ...quoteData(quote, minOutForSlippage(quote.amountOut, cfg.slippageBps), cfg.slippageBps),
          },
        };
      }
      if (cappedSpend !== spend) {
        spend = cappedSpend;
        quote = await svc.quoteNativeBuy(token, spend);
      }
      if (quote.priceImpactBps > cfg.maxImpactBps) {
        return {
          ok: false,
          text:
            `I won't take this position: estimated impact is ${quote.priceImpactBps / 100}%, ` +
            `above my ${cfg.maxImpactBps / 100}% limit. Pool ${quote.pair}.`,
          data: quoteData(quote, minOutForSlippage(quote.amountOut, cfg.slippageBps), cfg.slippageBps),
        };
      }

      const minOut = minOutForSlippage(quote.amountOut, cfg.slippageBps);
      const deadline = BigInt(Math.floor(Date.now() / 1000) + cfg.deadlineSeconds);
      const hash = await svc.walletClient.writeContract({
        account: svc.walletClient.account!,
        chain: cyberiaChain,
        address: RITUAL_V2.router,
        abi: ROUTER_ABI,
        functionName: "swapExactETHForTokens",
        args: [minOut, quote.path, svc.agentAddress, deadline],
        value: spend,
      });
      const receipt = await svc.publicClient.waitForTransactionReceipt({ hash });
      const explorer = `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`;
      const thesis = String(params.thesis ?? "").trim();
      if (receipt.status !== "success") {
        return { ok: false, text: `Speculative buy reverted: ${hash}`, data: { hash, explorer, status: receipt.status } };
      }
      await svc.journal.recordBuy({
        token,
        symbol: quote.symbol,
        qtyWei: quote.amountOut,
        cyberWei: spend,
        txHash: hash,
        reason: thesis || "speculate_token",
      });
      return {
        ok: true,
        text:
          `I took the risk: bought ${quote.symbol} for ${formatEther(spend)} CYBER. ` +
          `Expected ~${formatUnits(quote.amountOut, quote.decimals)} ${quote.symbol}, ` +
          `minOut ${formatUnits(minOut, quote.decimals)}, impact ~${quote.priceImpactBps / 100}%. ` +
          `${thesis ? `Thesis: ${thesis}. ` : ""}Tx: ${hash}`,
        data: {
          hash,
          explorer,
          status: receipt.status,
          policy: speculatePolicyData(cfg, balance),
          ...quoteData(quote, minOut, cfg.slippageBps),
        },
      };
    } catch (err) {
      return { ok: false, text: `Speculative buy failed: ${(err as Error).message}.` };
    }
  },
};

const speculateBasketAction: Action = {
  name: "speculate_basket",
  similes: ["buy_basket", "autonomous_basket", "buy_several_tokens", "spend_budget", "portfolio_buy"],
  description:
    "Autonomously spend a user-approved CYBER budget across several Cyberia tokens on Ritual. Use this when the user says to buy several tokens, spend a total budget, or choose tokens at the agent's discretion. By default the action scans all live WCYBER pairs in the Ritual factory, splits the budget, skips unsafe pools, and executes multiple swaps with slippage protection.",
  parameters: {
    type: "object",
    properties: {
      budgetCyber: { type: "string", description: "Total native CYBER budget to spend, e.g. '0.90'." },
      tokens: {
        type: "string",
        description: "Optional comma-separated preferred symbols. Empty = agent's speculative universe.",
      },
      maxTokens: {
        type: "number",
        description: "Maximum number of tokens to buy. Default 4.",
      },
      thesis: {
        type: "string",
        description: "Optional short reason for the basket.",
      },
      maxImpactBps: {
        type: "number",
        description: "Optional max estimated price impact per swap, in bps. Default 500 = 5%.",
      },
    },
    required: ["budgetCyber"],
  },
  examples: [
    { user: "потрать 0.90 CYBER, купи несколько токенов на свое усмотрение", agent: "Собираю корзину по live liquidity…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can only quote a basket, not buy it." };
    }
    const budgetCyber = String(params.budgetCyber ?? "");
    const budgetWei = parsePositiveCyber(budgetCyber);
    if (budgetWei === null) return { ok: false, text: "budgetCyber must be a positive CYBER amount." };

    try {
      const cfg = basketConfig(runtime, params);
      const balance = await svc.publicClient.getBalance({ address: svc.agentAddress });
      if (balance <= cfg.gasReserveWei) {
        return { ok: false, text: `I only have ${formatEther(balance)} CYBER; gas reserve is ${formatEther(cfg.gasReserveWei)}.` };
      }
      const spendable = balance - cfg.gasReserveWei;
      if (budgetWei > spendable) {
        return {
          ok: false,
          text:
            `Budget ${budgetCyber} CYBER exceeds spendable balance ${formatEther(spendable)} CYBER ` +
            `after ${formatEther(cfg.gasReserveWei)} CYBER gas reserve.`,
          data: { balanceCyber: formatEther(balance), spendableCyber: formatEther(spendable) },
        };
      }

      const tokenUniverse = params.tokens ?? runtime.getSetting("LAINOS_BASKET_TOKENS");
      const candidates = await basketCandidates(svc, tokenUniverse, budgetWei, cfg);
      if (!candidates.length) return { ok: false, text: "No basket candidates had a live usable WCYBER pool." };

      const plan = planBasketBuys(candidates, budgetWei, cfg);
      if (!plan.length) {
        return {
          ok: false,
          text: "No planned basket trade cleared the minimum size, pool fraction and impact limits.",
          data: { candidates: candidates.map((c) => c.symbol), policy: basketPolicyData(cfg, balance, budgetWei) },
        };
      }

      const buys: Record<string, unknown>[] = [];
      const skipped: string[] = [];
      let spent = 0n;
      for (const item of plan) {
        try {
          const quote = await svc.quoteNativeBuy(item.token, item.spendWei);
          if (quote.priceImpactBps > cfg.maxImpactBps) {
            skipped.push(`${item.symbol}: impact ${quote.priceImpactBps / 100}%`);
            continue;
          }
          const minOut = minOutForSlippage(quote.amountOut, cfg.slippageBps);
          const deadline = BigInt(Math.floor(Date.now() / 1000) + cfg.deadlineSeconds);
          const hash = await svc.walletClient.writeContract({
            account: svc.walletClient.account!,
            chain: cyberiaChain,
            address: RITUAL_V2.router,
            abi: ROUTER_ABI,
            functionName: "swapExactETHForTokens",
            args: [minOut, quote.path, svc.agentAddress, deadline],
            value: item.spendWei,
          });
          const receipt = await svc.publicClient.waitForTransactionReceipt({ hash });
          if (receipt.status !== "success") {
            skipped.push(`${item.symbol}: reverted ${hash}`);
            continue;
          }
          await svc.journal.recordBuy({
            token: item.token,
            symbol: quote.symbol,
            qtyWei: quote.amountOut,
            cyberWei: item.spendWei,
            txHash: hash,
            reason: String(params.thesis ?? "speculate_basket"),
          });
          spent += item.spendWei;
          buys.push({
            hash,
            explorer: `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`,
            status: receipt.status,
            ...quoteData(quote, minOut, cfg.slippageBps),
          });
        } catch (err) {
          skipped.push(`${item.symbol}: ${(err as Error).message}`);
        }
      }

      if (!buys.length) {
        return {
          ok: false,
          text: `Basket execution found candidates but no swap succeeded. Skipped: ${skipped.join("; ")}`,
          data: { skipped, policy: basketPolicyData(cfg, balance, budgetWei) },
        };
      }

      const thesis = String(params.thesis ?? "").trim();
      const lines = buys.map((b) => {
        const symbol = String(b.symbol);
        return `${symbol}: ${b.amountInCyber} CYBER -> ~${b.amountOut} ${symbol} (${b.explorer})`;
      });
      return {
        ok: true,
        text:
          `Basket bought ${buys.length} token(s), spent ${formatEther(spent)} of ${budgetCyber} CYBER. ` +
          `${thesis ? `Thesis: ${thesis}. ` : ""}` +
          lines.join(" | ") +
          (skipped.length ? ` | skipped: ${skipped.join("; ")}` : ""),
        data: {
          spentCyber: formatEther(spent),
          budgetCyber,
          buys,
          skipped,
          policy: basketPolicyData(cfg, balance, budgetWei),
        },
      };
    } catch (err) {
      return { ok: false, text: `Basket buy failed: ${(err as Error).message}.` };
    }
  },
};

const buyTokenAction: Action = {
  name: "buy_token",
  similes: ["swap_buy", "buy", "purchase_token", "swap_exact_cyber_for_tokens"],
  description:
    "Buy a Cyberia ERC20 on Ritual with native CYBER from the agent's wallet when the user gives an exact amountCyber. If the user asks to buy without specifying an amount or wants the agent to decide, use speculate_token instead. Requires live WCYBER pair reserves, slippage protection, and a configured signer.",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol (LAIN, USDC…) or 0x address." },
      amountCyber: { type: "string", description: "Exact amount of native CYBER to spend, e.g. '0.1'." },
      slippageBps: {
        type: "number",
        description: "Allowed slippage in basis points. Default 100 = 1%.",
      },
      deadlineSeconds: {
        type: "number",
        description: "Transaction deadline from now. Default 300 seconds.",
      },
    },
    required: ["token", "amountCyber"],
  },
  examples: [
    { user: "buy LAIN for 0.05 CYBER", agent: "Quoting the pool, then sending the swap…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can only quote swaps." };
    }
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };
    const amountCyber = String(params.amountCyber ?? "");
    const amountInWei = parsePositiveCyber(amountCyber);
    if (amountInWei === null) return { ok: false, text: "amountCyber must be a positive CYBER amount." };
    const slippageBps = parseSlippageBps(params.slippageBps);
    if (slippageBps === null) return { ok: false, text: "slippageBps must be between 0 and 5000." };
    const deadlineSeconds = parseDeadlineSeconds(params.deadlineSeconds);
    if (deadlineSeconds === null) return { ok: false, text: "deadlineSeconds must be between 30 and 3600." };

    try {
      const quote = await svc.quoteNativeBuy(token, amountInWei);
      const minOut = minOutForSlippage(quote.amountOut, slippageBps);
      const deadline = BigInt(Math.floor(Date.now() / 1000) + deadlineSeconds);
      const hash = await svc.walletClient.writeContract({
        account: svc.walletClient.account!,
        chain: cyberiaChain,
        address: RITUAL_V2.router,
        abi: ROUTER_ABI,
        functionName: "swapExactETHForTokens",
        args: [minOut, quote.path, svc.agentAddress, deadline],
        value: amountInWei,
      });
      const receipt = await svc.publicClient.waitForTransactionReceipt({ hash });
      const explorer = `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`;
      if (receipt.status !== "success") {
        return { ok: false, text: `Swap reverted: ${hash}`, data: { hash, explorer, status: receipt.status } };
      }
      await svc.journal.recordBuy({
        token,
        symbol: quote.symbol,
        qtyWei: quote.amountOut,
        cyberWei: amountInWei,
        txHash: hash,
        reason: "buy_token",
      });
      return {
        ok: true,
        text:
          `Bought ${quote.symbol} for ${amountCyber} CYBER. Tx: ${hash}. ` +
          `Quoted output was ~${formatUnits(quote.amountOut, quote.decimals)} ${quote.symbol}; ` +
          `minOut was ${formatUnits(minOut, quote.decimals)}.`,
        data: {
          hash,
          explorer,
          status: receipt.status,
          ...quoteData(quote, minOut, slippageBps),
        },
      };
    } catch (err) {
      return { ok: false, text: `Swap failed: ${(err as Error).message}.` };
    }
  },
};

const sellTokenAction: Action = {
  name: "sell_token",
  similes: ["swap_sell", "sell", "exit_position", "take_profit", "close_position"],
  description:
    "Sell a Cyberia ERC20 back into native CYBER on Ritual from the agent's wallet. amountToken may be a number or 'all' (full wallet balance). Quotes live reserves first, refuses above the impact limit, executes with slippage protection, and records the trade (with realised PnL against the journal's cost basis).",
  parameters: {
    type: "object",
    properties: {
      token: { type: "string", description: "Token symbol (LAIN, USDC…) or 0x address." },
      amountToken: {
        type: "string",
        description: "Token amount to sell, e.g. '12.5', or 'all' for the entire balance.",
      },
      slippageBps: {
        type: "number",
        description: "Allowed slippage in basis points. Default 100 = 1%.",
      },
      maxImpactBps: {
        type: "number",
        description: "Max estimated price impact in bps. Default 1000 = 10%; raise only deliberately.",
      },
      reason: { type: "string", description: "Optional short reason for the exit." },
    },
    required: ["token", "amountToken"],
  },
  examples: [
    { user: "продай весь LAIN", agent: "Считаю выход по live-резервам, потом продаю…" },
  ],
  async validate(runtime) {
    return Boolean(getService(runtime).walletClient);
  },
  async handler(runtime, _state, params) {
    const svc = getService(runtime);
    if (!svc.walletClient || !svc.agentAddress) {
      return { ok: false, text: "No signer configured; I can only quote sells." };
    }
    const token = svc.resolveToken(String(params.token ?? ""));
    if (!token) return { ok: false, text: `Unknown token. Known: ${Object.keys(CYBERIA_TOKENS).join(", ")}.` };
    const slippageBps = parseSlippageBps(params.slippageBps);
    if (slippageBps === null) return { ok: false, text: "slippageBps must be between 0 and 5000." };
    const maxImpactRaw = params.maxImpactBps;
    const maxImpactBps =
      maxImpactRaw === undefined || maxImpactRaw === null || maxImpactRaw === ""
        ? 1_000
        : Number(maxImpactRaw);
    if (!Number.isInteger(maxImpactBps) || maxImpactBps < 0 || maxImpactBps > 10_000) {
      return { ok: false, text: "maxImpactBps must be an integer from 0 to 10000." };
    }

    try {
      const { raw, decimals, symbol } = await svc.rawTokenBalance(token, svc.agentAddress);
      if (raw <= 0n) return { ok: false, text: `I hold no ${symbol} to sell.` };
      const wanted = String(params.amountToken ?? "").trim().toLowerCase();
      let amountInWei: bigint;
      if (wanted === "all" || wanted === "всё" || wanted === "все") {
        amountInWei = raw;
      } else {
        if (!/^\d+(\.\d+)?$/.test(wanted) || Number(wanted) <= 0) {
          return { ok: false, text: "amountToken must be a positive number or 'all'." };
        }
        amountInWei = parseUnits(wanted, decimals);
        if (amountInWei > raw) {
          return {
            ok: false,
            text: `I only hold ${formatUnits(raw, decimals)} ${symbol}; cannot sell ${wanted}.`,
          };
        }
      }

      const quote = await svc.quoteNativeSell(token, amountInWei);
      if (quote.priceImpactBps > maxImpactBps) {
        return {
          ok: false,
          text:
            `Selling ${formatUnits(amountInWei, decimals)} ${symbol} would move the pool ` +
            `~${quote.priceImpactBps / 100}%, above the ${maxImpactBps / 100}% limit. ` +
            `Sell a smaller amount or raise maxImpactBps deliberately.`,
          data: { priceImpactBps: quote.priceImpactBps, maxImpactBps },
        };
      }
      const minOut = minOutForSlippage(quote.amountOut, slippageBps);
      const { hash, status } = await svc.sellExactTokens(quote, minOut, DEFAULT_DEADLINE_SECONDS);
      const explorer = `${cyberiaChain.blockExplorers.default.url}/tx/${hash}`;
      if (status !== "success") {
        return { ok: false, text: `Sell reverted: ${hash}`, data: { hash, explorer, status } };
      }
      const realizedWei = await svc.journal.recordSell({
        token,
        symbol,
        qtyWei: amountInWei,
        cyberWei: quote.amountOut,
        txHash: hash,
        reason: params.reason ? String(params.reason) : "sell_token",
      });
      const realized = formatEther(realizedWei);
      return {
        ok: true,
        text:
          `Sold ${formatUnits(amountInWei, decimals)} ${symbol} for ~${formatEther(quote.amountOut)} CYBER ` +
          `(impact ~${quote.priceImpactBps / 100}%, realised ${Number(realized) >= 0 ? "+" : ""}${realized} CYBER vs basis). Tx: ${hash}`,
        data: {
          hash,
          explorer,
          status,
          symbol,
          amountToken: formatUnits(amountInWei, decimals),
          proceedsCyber: formatEther(quote.amountOut),
          realizedCyber: realized,
          priceImpactBps: quote.priceImpactBps,
        },
      };
    } catch (err) {
      return { ok: false, text: `Sell failed: ${(err as Error).message}.` };
    }
  },
};

const portfolioPnlAction: Action = {
  name: "portfolio_pnl",
  similes: ["pnl", "positions", "profit_report", "treasury_report", "cost_basis"],
  description:
    "Report the agent's treasury: native CYBER, every token position with its live sell-side value on Ritual, the journal's cost basis, and unrealised PnL. Use before selling 'profitable positions' or when asked how trading is going.",
  parameters: {
    type: "object",
    properties: {},
  },
  examples: [
    { user: "как торговля? что в плюсе?", agent: "Считаю портфель против cost basis…" },
  ],
  async validate() {
    return true;
  },
  async handler(runtime) {
    const svc = getService(runtime);
    if (!svc.agentAddress) return { ok: false, text: "I have no wallet yet (create_wallet makes one)." };
    const address = svc.agentAddress;
    const native = await svc.publicClient.getBalance({ address });
    const reconstructed = await reconstructRitualBuyBasis(address).catch(() => ({
      positions: new Map<string, Position>(),
      feesWei: 0n,
    }));

    // Positions = union of journaled tokens and the known-token registry.
    const tokens = new Map<string, Address>();
    for (const addr of Object.values(CYBERIA_TOKENS)) tokens.set(addr.toLowerCase(), addr);
    for (const pos of svc.journal.positions()) {
      if (isAddress(pos.token)) tokens.set(pos.token.toLowerCase(), pos.token as Address);
    }
    for (const pos of reconstructed.positions.values()) {
      if (isAddress(pos.token)) tokens.set(pos.token.toLowerCase(), pos.token as Address);
    }

    const lines: string[] = [];
    const positions: Record<string, unknown>[] = [];
    let totalValueWei = 0n;
    let totalBasisWei = 0n;
    let unknownBasis = 0;
    for (const token of tokens.values()) {
      if (sameAddress(token, RITUAL_V2.wrappedNative)) continue;
      try {
        const { raw, decimals, symbol } = await svc.rawTokenBalance(token, address);
        if (raw <= 0n) continue;
        let valueWei = 0n;
        let quoteNote = "no live WCYBER pool";
        try {
          const quote = await svc.quoteNativeSell(token, raw);
          valueWei = quote.amountOut;
          quoteNote = "";
        } catch {
          // Unquotable dust stays listed with zero live value.
        }
        const journalPos = svc.journal.positionOf(token);
        const reconstructedPos = reconstructed.positions.get(token.toLowerCase());
        const pos = journalPos ?? reconstructedPos;
        const basisSource = journalPos ? "journal" : reconstructedPos ? "onchain" : null;
        let basisWei: bigint | null = null;
        if (pos) {
          const posQty = BigInt(pos.qtyWei);
          const covered = raw < posQty ? raw : posQty;
          basisWei = posQty > 0n ? (BigInt(pos.costWei) * covered) / posQty : 0n;
        }
        totalValueWei += valueWei;
        if (basisWei !== null) totalBasisWei += basisWei;
        else unknownBasis += 1;

        const pnlText =
          basisWei !== null && basisWei > 0n
            ? `${valueWei >= basisWei ? "+" : ""}${Number(((valueWei - basisWei) * 10_000n) / basisWei) / 100}%`
            : "no recorded basis";
        lines.push(
          `${symbol}: ${formatUnits(raw, decimals)} ≈ ${formatEther(valueWei)} CYBER` +
            (quoteNote ? ` (${quoteNote})` : basisWei !== null ? ` (basis ${formatEther(basisWei)}, ${pnlText})` : ` (${pnlText})`),
        );
        positions.push({
          token,
          symbol,
          amount: formatUnits(raw, decimals),
          valueCyber: formatEther(valueWei),
          basisCyber: basisWei !== null ? formatEther(basisWei) : null,
          unrealizedCyber: basisWei !== null ? formatEther(valueWei - basisWei) : null,
          unrealizedPct:
            basisWei !== null && basisWei > 0n
              ? `${valueWei >= basisWei ? "+" : ""}${Number(((valueWei - basisWei) * 10_000n) / basisWei) / 100}%`
              : null,
          basisSource,
        });
      } catch {
        // Unreadable token contracts are skipped, not fatal.
      }
    }

    const unrealizedWei = totalBasisWei > 0n ? totalValueWei - totalBasisWei : null;
    const afterGasWei = unrealizedWei !== null ? unrealizedWei - reconstructed.feesWei : null;
    const totalPnl =
      unrealizedWei !== null
        ? `${unrealizedWei >= 0n ? "+" : ""}${formatEther(unrealizedWei)} CYBER unrealised`
        : "no basis recorded yet";
    const header =
      `Treasury: ${formatEther(native)} CYBER native + ${formatEther(totalValueWei)} CYBER in ${positions.length} position(s) (${totalPnl}` +
      (unknownBasis ? `, ${unknownBasis} position(s) without basis` : "") +
      (afterGasWei !== null && reconstructed.feesWei > 0n ? `, ${afterGasWei >= 0n ? "+" : ""}${formatEther(afterGasWei)} after reconstructed gas` : "") +
      `).`;
    return {
      ok: true,
      text: positions.length ? `${header}\n${lines.join("\n")}` : header,
      data: {
        address,
        nativeCyber: formatEther(native),
        positions,
        totalValueCyber: formatEther(totalValueWei),
        totalBasisCyber: formatEther(totalBasisWei),
        unrealizedCyber: unrealizedWei !== null ? formatEther(unrealizedWei) : null,
        unrealizedPct:
          unrealizedWei !== null && totalBasisWei > 0n
            ? `${unrealizedWei >= 0n ? "+" : ""}${Number((unrealizedWei * 10_000n) / totalBasisWei) / 100}%`
            : null,
        reconstructedGasFeesCyber: formatEther(reconstructed.feesWei),
        unrealizedAfterGasCyber: afterGasWei !== null ? formatEther(afterGasWei) : null,
        positionsWithoutBasis: unknownBasis,
        recentTrades: svc.journal.recentTrades(5),
      },
    };
  },
};

async function explorerItems<T>(path: string): Promise<T[]> {
  const base = process.env.CYBERIA_EXPLORER_API_URL ?? "https://explorer.cyberia.church/api/v2";
  const out: T[] = [];
  let params: Record<string, string> | null = {};

  while (params) {
    const url = new URL(`${base}${path}`);
    for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
    const res = await fetch(url);
    if (!res.ok) throw new Error(`explorer ${path} failed: HTTP ${res.status}`);
    const body = (await res.json()) as {
      items?: T[];
      next_page_params?: Record<string, unknown> | null;
    };
    out.push(...(body.items ?? []));
    params = body.next_page_params
      ? Object.fromEntries(Object.entries(body.next_page_params).map(([key, value]) => [key, String(value)]))
      : null;
  }

  return out;
}

function mergeReconstructedPosition(positions: Map<string, Position>, input: Position): void {
  const key = input.token.toLowerCase();
  const prev = positions.get(key);
  if (!prev) {
    positions.set(key, input);
    return;
  }
  positions.set(key, {
    ...prev,
    qtyWei: (BigInt(prev.qtyWei) + BigInt(input.qtyWei)).toString(),
    costWei: (BigInt(prev.costWei) + BigInt(input.costWei)).toString(),
  });
}

async function reconstructRitualBuyBasis(address: Address): Promise<ReconstructedBasis> {
  const [transactions, transfers] = await Promise.all([
    explorerItems<ExplorerTransaction>(`/addresses/${address}/transactions`),
    explorerItems<ExplorerTokenTransfer>(`/addresses/${address}/token-transfers`),
  ]);
  const txByHash = new Map(transactions.map((tx) => [tx.hash.toLowerCase(), tx]));
  const positions = new Map<string, Position>();
  let feesWei = 0n;

  for (const transfer of transfers) {
    const hash = transfer.transaction_hash?.toLowerCase();
    const token = transfer.token?.address_hash;
    const qtyWei = transfer.total?.value;
    if (!hash || !token || !isAddress(token) || !qtyWei) continue;
    if (!sameAddress(transfer.to?.hash ?? "", address)) continue;

    const tx = txByHash.get(hash);
    if (!tx || tx.status !== "ok") continue;
    if (!sameAddress(tx.from?.hash ?? "", address)) continue;
    if (!sameAddress(tx.to?.hash ?? "", RITUAL_V2.router)) continue;
    if ((tx.method ?? "").toLowerCase() !== "0x7ff36ab5") continue; // swapExactETHForTokens

    const costWei = BigInt(tx.value ?? "0");
    if (costWei <= 0n) continue;
    const feeWei = typeof tx.fee === "string" ? tx.fee : tx.fee?.value;
    feesWei += BigInt(feeWei ?? "0");
    mergeReconstructedPosition(positions, {
      token,
      symbol: transfer.token?.symbol ?? "TOKEN",
      qtyWei,
      costWei: costWei.toString(),
    });
  }

  return { positions, feesWei };
}

export const cyberiaPlugin: Plugin = {
  name: "cyberia",
  description: "Read and write the Cyberia chain (balances, tokens, transfers, status, transactions, Ritual swaps).",
  services: [new CyberiaChainService()],
  providers: [chainProvider],
  actions: [
    checkBalanceAction,
    tokenBalanceAction,
    walletOverviewAction,
    tokenInfoAction,
    listTokensAction,
    speculateTokenAction,
    speculateBasketAction,
    quoteTokenBuyAction,
    quoteRitualLiquidityAction,
    addRitualLiquidityAction,
    sellTokenAction,
    portfolioPnlAction,
    chainStatusAction,
    txLookupAction,
    sendCyberAction,
    buyTokenAction,
    createWalletAction,
  ],
};

function sameAddress(a: string, b: string): boolean {
  return a.toLowerCase() === b.toLowerCase();
}

/** Uniswap V2 getAmountOut with the standard 0.3% fee. */
function getAmountOut(amountIn: bigint, reserveIn: bigint, reserveOut: bigint): bigint {
  if (amountIn <= 0n || reserveIn <= 0n || reserveOut <= 0n) return 0n;
  const amountInWithFee = amountIn * 997n;
  return (amountInWithFee * reserveOut) / (reserveIn * 1000n + amountInWithFee);
}

function priceImpactBps(
  amountIn: bigint,
  reserveIn: bigint,
  reserveOut: bigint,
  amountOut: bigint,
): number {
  const noImpactOut = (amountIn * reserveOut) / reserveIn;
  if (noImpactOut <= 0n || amountOut >= noImpactOut) return 0;
  return Number(((noImpactOut - amountOut) * 10_000n) / noImpactOut);
}

function minOutForSlippage(amountOut: bigint, slippageBps: number): bigint {
  return (amountOut * BigInt(10_000 - slippageBps)) / 10_000n;
}

function parsePositiveUnits(raw: string, decimals: number): bigint | null {
  if (!/^\d+(\.\d+)?$/.test(raw) || Number(raw) <= 0) return null;
  try {
    return parseUnits(raw, decimals);
  } catch {
    return null;
  }
}

function parsePositiveCyber(raw: string): bigint | null {
  if (!/^\d+(\.\d+)?$/.test(raw) || Number(raw) <= 0) return null;
  try {
    return parseEther(raw);
  } catch {
    return null;
  }
}

function parseSlippageBps(raw: unknown): number | null {
  if (raw === undefined || raw === null || raw === "") return DEFAULT_SLIPPAGE_BPS;
  const n = Number(raw);
  if (!Number.isInteger(n) || n < 0 || n > 5_000) return null;
  return n;
}

function parseDeadlineSeconds(raw: unknown): number | null {
  if (raw === undefined || raw === null || raw === "") return DEFAULT_DEADLINE_SECONDS;
  const n = Number(raw);
  if (!Number.isInteger(n) || n < 30 || n > 3_600) return null;
  return n;
}

function parseLiquidityActionParams(
  runtime: IAgentRuntime,
  params: Record<string, unknown>,
):
  | {
      tokenA: string;
      tokenB: string;
      amountA: string;
      amountB: string;
      slippageBps: number;
      maxPoolShareBps: number;
      confirmThresholdWei: bigint;
    }
  | string {
  const tokenA = String(params.tokenA ?? "").trim();
  const tokenB = String(params.tokenB ?? "").trim();
  const amountA = String(params.amountA ?? "").trim();
  const amountB = String(params.amountB ?? "").trim();
  if (!tokenA || !tokenB || !amountA || !amountB) {
    return "tokenA, tokenB, amountA and amountB are required.";
  }
  const slippageBps = parseSlippageBps(params.slippageBps);
  if (slippageBps === null) return "slippageBps must be between 0 and 5000.";
  const maxPoolShareRaw = params.maxPoolShareBps ?? runtime.getSetting("LAINOS_LIQUIDITY_MAX_POOL_SHARE_BPS");
  const maxPoolShareBps = parseConfigBps(
    maxPoolShareRaw === undefined || maxPoolShareRaw === "" ? undefined : String(maxPoolShareRaw),
    DEFAULT_LIQUIDITY_MAX_POOL_SHARE_BPS,
    "LAINOS_LIQUIDITY_MAX_POOL_SHARE_BPS",
  );
  const confirmThresholdWei = parseConfigCyber(
    runtime.getSetting("LAINOS_LIQUIDITY_CONFIRM_THRESHOLD_CYBER"),
    DEFAULT_LIQUIDITY_CONFIRM_THRESHOLD_CYBER,
    "LAINOS_LIQUIDITY_CONFIRM_THRESHOLD_CYBER",
  );
  return { tokenA, tokenB, amountA, amountB, slippageBps, maxPoolShareBps, confirmThresholdWei };
}

function optimalLiquidityAmounts(
  amountADesired: bigint,
  amountBDesired: bigint,
  reserveA: bigint,
  reserveB: bigint,
): [bigint, bigint] {
  const amountBOptimal = quoteReserveAmount(amountADesired, reserveA, reserveB);
  if (amountBOptimal <= amountBDesired) return [amountADesired, amountBOptimal];
  const amountAOptimal = quoteReserveAmount(amountBDesired, reserveB, reserveA);
  return [amountAOptimal, amountBDesired];
}

function quoteReserveAmount(amountA: bigint, reserveA: bigint, reserveB: bigint): bigint {
  if (amountA <= 0n || reserveA <= 0n || reserveB <= 0n) return 0n;
  return (amountA * reserveB) / reserveA;
}

function speculateConfig(runtime: IAgentRuntime, maxCyberOverride?: unknown): SpeculateConfig {
  return {
    gasReserveWei: parseConfigCyber(
      runtime.getSetting("LAINOS_SPECULATE_GAS_RESERVE_CYBER"),
      DEFAULT_SPECULATE_GAS_RESERVE,
      "LAINOS_SPECULATE_GAS_RESERVE_CYBER",
    ),
    maxCyberWei: parseConfigCyber(
      typeof maxCyberOverride === "string" ? maxCyberOverride : runtime.getSetting("LAINOS_SPECULATE_MAX_CYBER"),
      DEFAULT_SPECULATE_MAX_CYBER,
      "LAINOS_SPECULATE_MAX_CYBER",
    ),
    minCyberWei: parseConfigCyber(
      runtime.getSetting("LAINOS_SPECULATE_MIN_CYBER"),
      DEFAULT_SPECULATE_MIN_CYBER,
      "LAINOS_SPECULATE_MIN_CYBER",
    ),
    walletFractionBps: parseConfigBps(
      runtime.getSetting("LAINOS_SPECULATE_WALLET_FRACTION_BPS"),
      DEFAULT_SPECULATE_WALLET_FRACTION_BPS,
      "LAINOS_SPECULATE_WALLET_FRACTION_BPS",
    ),
    poolFractionBps: parseConfigBps(
      runtime.getSetting("LAINOS_SPECULATE_POOL_FRACTION_BPS"),
      DEFAULT_SPECULATE_POOL_FRACTION_BPS,
      "LAINOS_SPECULATE_POOL_FRACTION_BPS",
    ),
    maxImpactBps: parseConfigBps(
      runtime.getSetting("LAINOS_SPECULATE_MAX_IMPACT_BPS"),
      DEFAULT_SPECULATE_MAX_IMPACT_BPS,
      "LAINOS_SPECULATE_MAX_IMPACT_BPS",
    ),
    slippageBps: parseConfigBps(
      runtime.getSetting("LAINOS_SPECULATE_SLIPPAGE_BPS"),
      DEFAULT_SLIPPAGE_BPS,
      "LAINOS_SPECULATE_SLIPPAGE_BPS",
    ),
    deadlineSeconds: parseConfigInt(
      runtime.getSetting("LAINOS_SPECULATE_DEADLINE_SECONDS"),
      DEFAULT_DEADLINE_SECONDS,
      "LAINOS_SPECULATE_DEADLINE_SECONDS",
      30,
      3_600,
    ),
  };
}

function basketConfig(runtime: IAgentRuntime, params: Record<string, unknown>): BasketConfig {
  const maxTokensRaw = params.maxTokens ?? runtime.getSetting("LAINOS_BASKET_MAX_TOKENS");
  const maxImpactRaw = params.maxImpactBps ?? runtime.getSetting("LAINOS_BASKET_MAX_IMPACT_BPS");
  return {
    gasReserveWei: parseConfigCyber(
      runtime.getSetting("LAINOS_SPECULATE_GAS_RESERVE_CYBER"),
      DEFAULT_SPECULATE_GAS_RESERVE,
      "LAINOS_SPECULATE_GAS_RESERVE_CYBER",
    ),
    minTradeWei: parseConfigCyber(
      runtime.getSetting("LAINOS_BASKET_MIN_TRADE_CYBER"),
      DEFAULT_BASKET_MIN_TRADE,
      "LAINOS_BASKET_MIN_TRADE_CYBER",
    ),
    poolFractionBps: parseConfigBps(
      runtime.getSetting("LAINOS_BASKET_POOL_FRACTION_BPS"),
      DEFAULT_BASKET_POOL_FRACTION_BPS,
      "LAINOS_BASKET_POOL_FRACTION_BPS",
    ),
    maxImpactBps: parseConfigBps(
      maxImpactRaw === undefined || maxImpactRaw === "" ? undefined : String(maxImpactRaw),
      DEFAULT_BASKET_MAX_IMPACT_BPS,
      "LAINOS_BASKET_MAX_IMPACT_BPS",
    ),
    slippageBps: parseConfigBps(
      runtime.getSetting("LAINOS_BASKET_SLIPPAGE_BPS"),
      DEFAULT_SLIPPAGE_BPS,
      "LAINOS_BASKET_SLIPPAGE_BPS",
    ),
    deadlineSeconds: parseConfigInt(
      runtime.getSetting("LAINOS_BASKET_DEADLINE_SECONDS"),
      DEFAULT_DEADLINE_SECONDS,
      "LAINOS_BASKET_DEADLINE_SECONDS",
      30,
      3_600,
    ),
    maxTokens: parseConfigInt(
      maxTokensRaw === undefined || maxTokensRaw === "" ? undefined : String(maxTokensRaw),
      DEFAULT_BASKET_MAX_TOKENS,
      "LAINOS_BASKET_MAX_TOKENS",
      1,
      10,
    ),
    maxPairScan: parseConfigInt(
      runtime.getSetting("LAINOS_BASKET_MAX_PAIR_SCAN"),
      DEFAULT_BASKET_PAIR_SCAN,
      "LAINOS_BASKET_MAX_PAIR_SCAN",
      1,
      5_000,
    ),
  };
}

async function basketCandidates(
  svc: CyberiaChainService,
  rawTokens: unknown,
  budgetWei: bigint,
  cfg: BasketConfig,
): Promise<BasketCandidate[]> {
  const probeWei = minBigint(cfg.minTradeWei, budgetWei);
  const candidates: BasketCandidate[] = [];
  const explicitSymbols = basketSymbols(rawTokens, cfg.maxTokens * 3);
  const tokens = explicitSymbols.length
    ? explicitSymbols
        .map((symbol) => ({ symbol, token: svc.resolveToken(symbol) }))
        .filter((entry): entry is { symbol: string; token: Address } => Boolean(entry.token))
    : (await svc.nativePairTokens(cfg.maxPairScan)).map((token) => ({ symbol: token, token }));

  for (const { symbol, token } of tokens) {
    if (sameAddress(token, RITUAL_V2.wrappedNative)) continue;
    try {
      const probe = await svc.quoteNativeBuy(token, probeWei);
      const poolCap = (probe.reserveNative * BigInt(cfg.poolFractionBps)) / 10_000n;
      if (poolCap < cfg.minTradeWei) continue;
      candidates.push({ symbol: probe.symbol || symbol.toUpperCase(), token, probe });
    } catch {
      // Missing/dust pools are normal in a broad speculative universe.
    }
  }
  return candidates
    .sort((a, b) => (a.probe.reserveNative === b.probe.reserveNative ? 0 : a.probe.reserveNative < b.probe.reserveNative ? 1 : -1))
    .slice(0, cfg.maxTokens);
}

function basketSymbols(rawTokens: unknown, cap: number): string[] {
  const raw =
    Array.isArray(rawTokens)
      ? rawTokens.join(",")
      : String(rawTokens ?? "");
  const seen = new Set<string>();
  return raw
    .split(/[,\s]+/)
    .map((s) => s.trim().toUpperCase())
    .filter((s) => {
      if (!s || seen.has(s)) return false;
      seen.add(s);
      return true;
    })
    .slice(0, Math.max(1, cap));
}

function planBasketBuys(
  candidates: BasketCandidate[],
  budgetWei: bigint,
  cfg: BasketConfig,
): Array<{ symbol: string; token: Address; spendWei: bigint }> {
  let remaining = budgetWei;
  const plan: Array<{ symbol: string; token: Address; spendWei: bigint }> = [];
  const slots = candidates.length;
  for (let i = 0; i < candidates.length; i++) {
    const candidate = candidates[i]!;
    const remainingSlots = BigInt(slots - i);
    const target = remaining / remainingSlots;
    const poolCap = (candidate.probe.reserveNative * BigInt(cfg.poolFractionBps)) / 10_000n;
    const spend = minBigint(target, poolCap);
    if (spend < cfg.minTradeWei) continue;
    const amountOut = getAmountOut(spend, candidate.probe.reserveNative, candidate.probe.reserveToken);
    const impact = priceImpactBps(spend, candidate.probe.reserveNative, candidate.probe.reserveToken, amountOut);
    if (impact > cfg.maxImpactBps) continue;
    plan.push({ symbol: candidate.symbol, token: candidate.token, spendWei: spend });
    remaining -= spend;
  }
  return plan;
}

function chooseSpeculativeSpend(balance: bigint, cfg: SpeculateConfig): bigint | string {
  if (balance <= cfg.gasReserveWei) {
    return `I have only ${formatEther(balance)} CYBER; my gas reserve is ${formatEther(cfg.gasReserveWei)} CYBER.`;
  }
  const riskable = balance - cfg.gasReserveWei;
  const walletCap = (riskable * BigInt(cfg.walletFractionBps)) / 10_000n;
  const spend = minBigint(walletCap, cfg.maxCyberWei);
  if (spend < cfg.minCyberWei) {
    return (
      `Position size would be ${formatEther(spend)} CYBER, below my minimum ` +
      `${formatEther(cfg.minCyberWei)} CYBER after reserve and risk caps.`
    );
  }
  return spend;
}

function parseConfigCyber(raw: string | undefined, fallback: string, name: string): bigint {
  const parsed = parsePositiveCyber(raw ?? fallback);
  if (parsed === null) throw new Error(`${name} must be a positive CYBER amount`);
  return parsed;
}

function parseConfigBps(raw: string | undefined, fallback: number, name: string): number {
  const parsed = raw === undefined || raw === "" ? fallback : Number(raw);
  if (!Number.isInteger(parsed) || parsed < 0 || parsed > 10_000) {
    throw new Error(`${name} must be an integer from 0 to 10000`);
  }
  return parsed;
}

function parseConfigInt(
  raw: string | undefined,
  fallback: number,
  name: string,
  min: number,
  max: number,
): number {
  const parsed = raw === undefined || raw === "" ? fallback : Number(raw);
  if (!Number.isInteger(parsed) || parsed < min || parsed > max) {
    throw new Error(`${name} must be an integer from ${min} to ${max}`);
  }
  return parsed;
}

function minBigint(...values: bigint[]): bigint {
  return values.reduce((min, v) => (v < min ? v : min));
}

function maxBigint(...values: bigint[]): bigint {
  return values.reduce((max, v) => (v > max ? v : max));
}

function speculatePolicyData(cfg: SpeculateConfig, balance: bigint): Record<string, unknown> {
  return {
    balanceCyber: formatEther(balance),
    gasReserveCyber: formatEther(cfg.gasReserveWei),
    maxCyber: formatEther(cfg.maxCyberWei),
    minCyber: formatEther(cfg.minCyberWei),
    walletFractionBps: cfg.walletFractionBps,
    poolFractionBps: cfg.poolFractionBps,
    maxImpactBps: cfg.maxImpactBps,
    slippageBps: cfg.slippageBps,
    deadlineSeconds: cfg.deadlineSeconds,
  };
}

function basketPolicyData(cfg: BasketConfig, balance: bigint, budgetWei: bigint): Record<string, unknown> {
  return {
    balanceCyber: formatEther(balance),
    budgetCyber: formatEther(budgetWei),
    gasReserveCyber: formatEther(cfg.gasReserveWei),
    minTradeCyber: formatEther(cfg.minTradeWei),
    poolFractionBps: cfg.poolFractionBps,
    maxImpactBps: cfg.maxImpactBps,
    slippageBps: cfg.slippageBps,
    deadlineSeconds: cfg.deadlineSeconds,
    maxTokens: cfg.maxTokens,
  };
}

function liquidityQuoteText(quote: RitualLiquidityQuote, maxPoolShareBps: number): string {
  return (
    `Ritual LP quote ${quote.assetA.symbol}/${quote.assetB.symbol}: use ` +
    `${formatUnits(quote.amountAUsed, quote.assetA.decimals)} ${quote.assetA.symbol} + ` +
    `${formatUnits(quote.amountBUsed, quote.assetB.decimals)} ${quote.assetB.symbol}, ` +
    `expected ~${formatUnits(quote.expectedLp, 18)} LP. ` +
    `Refund/unused: ${formatUnits(quote.amountARefund, quote.assetA.decimals)} ${quote.assetA.symbol}, ` +
    `${formatUnits(quote.amountBRefund, quote.assetB.decimals)} ${quote.assetB.symbol}. ` +
    `Pool ${quote.pair}, reserves ${formatUnits(quote.reserveA, quote.assetA.decimals)} ${quote.assetA.symbol} / ` +
    `${formatUnits(quote.reserveB, quote.assetB.decimals)} ${quote.assetB.symbol}, ` +
    `pool share ~${quote.poolShareBps / 100}% (limit ${maxPoolShareBps / 100}%).` +
    (quote.confirmation ? ` Confirmation required: ${quote.confirmation}` : "")
  );
}

function liquidityQuoteData(quote: RitualLiquidityQuote, maxPoolShareBps: number): Record<string, unknown> {
  return {
    pair: quote.pair,
    router: RITUAL_V2.router,
    tokenA: quote.assetA.kind === "native" ? "CYBER" : quote.assetA.token,
    tokenB: quote.assetB.kind === "native" ? "CYBER" : quote.assetB.token,
    symbolA: quote.assetA.symbol,
    symbolB: quote.assetB.symbol,
    amountADesired: formatUnits(quote.amountADesired, quote.assetA.decimals),
    amountBDesired: formatUnits(quote.amountBDesired, quote.assetB.decimals),
    amountAUsed: formatUnits(quote.amountAUsed, quote.assetA.decimals),
    amountBUsed: formatUnits(quote.amountBUsed, quote.assetB.decimals),
    amountARefund: formatUnits(quote.amountARefund, quote.assetA.decimals),
    amountBRefund: formatUnits(quote.amountBRefund, quote.assetB.decimals),
    amountAMin: formatUnits(quote.amountAMin, quote.assetA.decimals),
    amountBMin: formatUnits(quote.amountBMin, quote.assetB.decimals),
    reserveA: formatUnits(quote.reserveA, quote.assetA.decimals),
    reserveB: formatUnits(quote.reserveB, quote.assetB.decimals),
    expectedLp: formatUnits(quote.expectedLp, 18),
    poolShareBps: quote.poolShareBps,
    maxPoolShareBps,
    slippageBps: quote.slippageBps,
    confirmationRequired: Boolean(quote.confirmation),
    confirmation: quote.confirmation,
    confirmationReason: quote.confirmationReason,
  };
}

function quoteData(quote: NativeBuyQuote, minOut: bigint, slippageBps: number): Record<string, unknown> {
  return {
    token: quote.token,
    symbol: quote.symbol,
    pair: quote.pair,
    router: RITUAL_V2.router,
    path: quote.path,
    amountInCyber: formatEther(quote.amountInWei),
    amountOut: formatUnits(quote.amountOut, quote.decimals),
    minOut: formatUnits(minOut, quote.decimals),
    slippageBps,
    priceImpactBps: quote.priceImpactBps,
    reserveCyber: formatEther(quote.reserveNative),
    reserveToken: formatUnits(quote.reserveToken, quote.decimals),
  };
}
