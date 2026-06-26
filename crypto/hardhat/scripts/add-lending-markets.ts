import "dotenv/config";
import { createWalletClient, createPublicClient, http, formatUnits } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

// Batch-add lending markets (Compound-style) for a list of tokens. Idempotent:
// reads getAllMarkets() and skips any underlying already listed, so it is safe
// to re-run. Pattern mirrors add-rub-market.ts / add-ash-market.ts.
//
// Pricing: QuickswapPriceOracle quotes everything in WCYBER (its quoteToken).
//   - Token with a live (token, WCYBER) pool → priced from that pool, no fallback.
//   - Token without one → we set an admin fallback. For the RUB-family tokens
//     that only trade against RUB, the fallback is derived from the on-chain
//     (token, RUB) pool times the live RUB fallback, so the cluster stays
//     consistent with the existing RUB market.
//   - SOL is intentionally skipped: its only (SOL, WCYBER) pool is 1-wei dust,
//     so the spot oracle would misprice it ~190x and a fallback can't override a
//     non-empty pool. List it only after a real SOL/WCYBER pool is seeded.

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};
const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";
const wallet = createWalletClient({ chain, transport: http(RPC_URL), account });
const pub = createPublicClient({ chain, transport: http(RPC_URL) });

// Current (v2) deployment, from deployments/cyberia-lending.json.
const COMPTROLLER = "0xe66aa9842dc74F1c10ede19cA20Ece6E08F1CC88" as const;
const RATE_MODEL = "0xb8Bbc65916ABd83B0264E886a24A57836D2bF42f" as const;
const ORACLE = "0x8fEA279fb70D3D1B20a0E50cbC649c83C41Dc4D1" as const;
const FACTORY = "0xB0aC30907c04b61F1482e62eA66eF4562a690917" as const;
const WCYBER = "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9" as const;
const RUB = "0x3cE7d8E486E16baD2Fb1487Fe1da4dC33237d923" as const;

const RESERVE_FACTOR = 10n ** 17n; // 10%
const COLLATERAL_FACTOR = 5n * 10n ** 17n; // 50%, uniform

// DRY_RUN=1 prints the resolved plan (price source + computed fallbacks) and
// broadcasts nothing — a free pre-flight before spending gas.
const DRY_RUN = process.env.DRY_RUN === "1";

// Tokens to list. SOL is deliberately excluded (see header); RUB is already
// listed and will be skipped by the on-chain check.
const TOKENS: { symbol: string; address: `0x${string}` }[] = [
  { symbol: "USDC", address: "0xdc25597B19799010047F17e9591EFE08EFd40077" },
  { symbol: "USDT", address: "0x94845aF24a3E431593A2b941b2b31836dE45185D" },
  { symbol: "BTC", address: "0x9332081f308BC978fe259237850fA253131b46Fa" },
  { symbol: "LTC", address: "0x001AFD19C9d890b0cf0fcd6D654f9BFe4f264F14" },
  { symbol: "SILVER", address: "0xAd9dfef9D671aFCF29Dbdd7Df360E7cA8D5ac40b" },
  { symbol: "TRUR", address: "0x6D056e56f5D90ed5680f0335D80E112799a735C8" },
  { symbol: "TGLD", address: "0xE2A45069C3e7897CAB592bEd389764e6eCf3b8a5" },
  { symbol: "TMOS", address: "0x3352254390526624a140B06E7D2dDA8BA85a9E89" },
  { symbol: "TOFZ", address: "0x46A6f512885De25AaefBf5A5F842ba378700Fe22" },
  { symbol: "GOLD", address: "0x38297140d60B48f746aD83D851b852Fd23eF9871" },
  { symbol: "XMR", address: "0xe2E8D51C18d6e0FDDbb9Ff4BF63235D688dd00Ae" },
  { symbol: "TRX", address: "0x60617237bC60f73c0393c7a6d7352e16DF20472a" },
  { symbol: "KRSQ", address: "0x4945419ccEEF0Dc70B054700DE2750A056B03eE3" },
  { symbol: "YTN", address: "0x3a5820Be90c3fB9c5F3Fb47a4859544193B0f8C6" },
];

const ARTIFACTS = {
  Comptroller: "./artifacts/contracts/lending/LendingComptroller.sol/LendingComptroller.json",
  Oracle: "./artifacts/contracts/lending/QuickswapPriceOracle.sol/QuickswapPriceOracle.json",
  Market: "./artifacts/contracts/lending/LendingMarket.sol/LendingMarket.json",
};

const ERC20 = [
  { type: "function", name: "decimals", stateMutability: "view", inputs: [], outputs: [{ type: "uint8" }] },
] as const;
const FACTORY_ABI = [
  { type: "function", name: "getPair", stateMutability: "view", inputs: [{ type: "address" }, { type: "address" }], outputs: [{ type: "address" }] },
] as const;
const PAIR_ABI = [
  { type: "function", name: "token0", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
  { type: "function", name: "getReserves", stateMutability: "view", inputs: [], outputs: [{ type: "uint112" }, { type: "uint112" }, { type: "uint32" }] },
] as const;
const COMPTROLLER_ABI = [
  { type: "function", name: "getAllMarkets", stateMutability: "view", inputs: [], outputs: [{ type: "address[]" }] },
] as const;
const MARKET_ABI = [
  { type: "function", name: "underlying", stateMutability: "view", inputs: [], outputs: [{ type: "address" }] },
] as const;
const ORACLE_READ = [
  { type: "function", name: "fallbackPrice", stateMutability: "view", inputs: [{ type: "address" }], outputs: [{ type: "uint256" }] },
] as const;

const ZERO = "0x0000000000000000000000000000000000000000";
const eq = (a: string, b: string) => a.toLowerCase() === b.toLowerCase();

function loadArtifact(p: string) {
  return JSON.parse(fs.readFileSync(path.resolve(p), "utf8")) as { abi: any[]; bytecode: `0x${string}` };
}
async function send(to: `0x${string}`, abi: any[], fn: string, args: unknown[]) {
  const hash = await wallet.writeContract({ address: to, abi, functionName: fn, args, gas: 3_000_000n } as any);
  const r = await pub.waitForTransactionReceipt({ hash });
  if (r.status !== "success") throw new Error(`${fn} failed: ${hash}`);
  console.log(`    ${fn} tx: ${hash}`);
}
const dec = (a: `0x${string}`) =>
  pub.readContract({ address: a, abi: ERC20, functionName: "decimals" }) as Promise<number>;

// Does a live (token, WCYBER) pool exist with non-zero reserves on both sides?
async function hasWcyberPool(token: `0x${string}`): Promise<boolean> {
  const pair = (await pub.readContract({ address: FACTORY, abi: FACTORY_ABI, functionName: "getPair", args: [token, WCYBER] })) as string;
  if (eq(pair, ZERO)) return false;
  const r = (await pub.readContract({ address: pair as `0x${string}`, abi: PAIR_ABI, functionName: "getReserves" })) as [bigint, bigint, number];
  return r[0] > 0n && r[1] > 0n;
}

// Fallback price for a RUB-family token: (token-in-RUB from its pool) × the live
// RUB fallback. Returns mantissa 1e18 (USD-per-token in the oracle's convention).
async function fallbackViaRub(token: `0x${string}`, tokenDecimals: number): Promise<bigint> {
  const pair = (await pub.readContract({ address: FACTORY, abi: FACTORY_ABI, functionName: "getPair", args: [token, RUB] })) as string;
  if (eq(pair, ZERO)) throw new Error(`no (token, RUB) pool to derive a fallback for ${token}`);

  const [t0, reserves] = await Promise.all([
    pub.readContract({ address: pair as `0x${string}`, abi: PAIR_ABI, functionName: "token0" }) as Promise<string>,
    pub.readContract({ address: pair as `0x${string}`, abi: PAIR_ABI, functionName: "getReserves" }) as Promise<[bigint, bigint, number]>,
  ]);
  const [rTok, rRub] = eq(t0, token) ? [reserves[0], reserves[1]] : [reserves[1], reserves[0]];
  if (rTok <= 0n || rRub <= 0n) throw new Error(`empty (token, RUB) pool for ${token}`);

  const rubDecimals = await dec(RUB);
  const rubFallback = (await pub.readContract({ address: ORACLE, abi: ORACLE_READ, functionName: "fallbackPrice", args: [RUB] })) as bigint;
  if (rubFallback <= 0n) throw new Error("RUB has no fallback price to anchor the family");

  // priceInRub (whole RUB per whole token) × rubFallback (USD-mantissa per RUB).
  const priceInRub = Number(formatUnits(rRub, rubDecimals)) / Number(formatUnits(rTok, tokenDecimals));
  return BigInt(Math.round(priceInRub * Number(rubFallback)));
}

async function main() {
  console.log("Deployer:", account.address);
  console.log("RPC:", RPC_URL);

  const comptroller = loadArtifact(ARTIFACTS.Comptroller);
  const oracle = loadArtifact(ARTIFACTS.Oracle);
  const marketArtifact = loadArtifact(ARTIFACTS.Market);

  // Existing markets → underlying set (idempotency).
  const existing = (await pub.readContract({ address: COMPTROLLER, abi: COMPTROLLER_ABI, functionName: "getAllMarkets" })) as `0x${string}`[];
  const listed = new Set<string>();
  for (const m of existing) {
    const u = (await pub.readContract({ address: m, abi: MARKET_ABI, functionName: "underlying" })) as string;
    listed.add(u.toLowerCase());
  }

  const outFile = path.resolve("./deployments/cyberia-lending.json");
  const deployment = JSON.parse(fs.readFileSync(outFile, "utf8"));
  const added: string[] = [];
  const skipped: string[] = [];

  for (const { symbol, address } of TOKENS) {
    if (listed.has(address.toLowerCase())) {
      console.log(`\n${symbol}: already listed — skip.`);
      skipped.push(symbol);
      continue;
    }

    console.log(`\n=== ${symbol} (${address}) ===`);
    const decimals = await dec(address);

    // Price source: live WCYBER pool, else a derived fallback.
    if (!(await hasWcyberPool(address))) {
      const fb = await fallbackViaRub(address, decimals);
      console.log(`  no WCYBER pool → fallback ${formatUnits(fb, 18)} (USD-mantissa/token)`);
      if (!DRY_RUN) await send(ORACLE, oracle.abi, "setFallbackPrice", [address, fb]);
    } else {
      console.log("  priced from its WCYBER pool (no fallback needed)");
    }

    if (DRY_RUN) {
      console.log("  [dry-run] would deploy market + supportMarket + setCollateralFactor(50%)");
      added.push(symbol);
      continue;
    }

    console.log("  deploying LendingMarket…");
    const deployHash = await wallet.deployContract({
      abi: marketArtifact.abi,
      bytecode: marketArtifact.bytecode,
      args: [address, COMPTROLLER, RATE_MODEL, RESERVE_FACTOR, `Cyberia Lend ${symbol}`, `cl${symbol}`, decimals, account.address],
      gas: 10_000_000n,
    } as any);
    const receipt = await pub.waitForTransactionReceipt({ hash: deployHash });
    if (!receipt.contractAddress) throw new Error(`${symbol}: no contract address`);
    const market = receipt.contractAddress as `0x${string}`;
    console.log("  market:", market);

    await send(COMPTROLLER, comptroller.abi, "supportMarket", [market]);
    await send(COMPTROLLER, comptroller.abi, "setCollateralFactor", [market, COLLATERAL_FACTOR]);

    deployment.markets[symbol] = { address: market, underlying: address };
    deployment.timestamp = new Date().toISOString();
    fs.writeFileSync(outFile, JSON.stringify(deployment, null, 2));
    added.push(symbol);
    console.log(`  ${symbol} listed (CF 50%) and saved.`);
  }

  console.log(`\nDone. Added: ${added.join(", ") || "none"}. Skipped: ${skipped.join(", ") || "none"}.`);
}

main().catch((e) => { console.error(e); process.exit(1); });
