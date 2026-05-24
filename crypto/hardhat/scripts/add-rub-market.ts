import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

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
const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

// Current (v2) deployment, from deployments/cyberia-lending.json.
const COMPTROLLER = "0xe66aa9842dc74F1c10ede19cA20Ece6E08F1CC88" as const;
const RATE_MODEL = "0xb8Bbc65916ABd83B0264E886a24A57836D2bF42f" as const;
const ORACLE = "0x8fEA279fb70D3D1B20a0E50cbC649c83C41Dc4D1" as const; // QuickswapPriceOracle

// RUB token (2 decimals), from deployments/cyberia-tokens.json.
const RUB = "0x3cE7d8E486E16baD2Fb1487Fe1da4dC33237d923" as const;

// RUB has no Quickswap pool, so the oracle uses its admin fallback price.
// fallbackPrice is USD-per-whole-token, mantissa 1e18 (decimals handled in-oracle).
// ~80 RUB/USD => 1 RUB ≈ $0.0125 => 0.0125 * 1e18.
const RUB_USD_FALLBACK = 125n * 10n ** 14n; // 1.25e16

const RESERVE_FACTOR = 10n ** 17n; // 10%
const COLLATERAL_FACTOR = 50n * 10n ** 16n; // 50%

const ARTIFACTS = {
  Comptroller: "./artifacts/contracts/lending/LendingComptroller.sol/LendingComptroller.json",
  Oracle: "./artifacts/contracts/lending/QuickswapPriceOracle.sol/QuickswapPriceOracle.json",
  Market: "./artifacts/contracts/lending/LendingMarket.sol/LendingMarket.json",
};

const ERC20_ABI = [
  { type: "function", name: "decimals", stateMutability: "view", inputs: [], outputs: [{ type: "uint8" }] },
] as const;

function loadArtifact(p: string) {
  const raw = fs.readFileSync(path.resolve(p), "utf8");
  return JSON.parse(raw) as { abi: any[]; bytecode: `0x${string}` };
}

async function send(to: `0x${string}`, abi: any[], fn: string, args: unknown[]) {
  const hash = await walletClient.writeContract({ address: to, abi, functionName: fn, args, gas: 3_000_000n } as any);
  const r = await publicClient.waitForTransactionReceipt({ hash });
  if (r.status !== "success") throw new Error(`${fn} failed: ${hash}`);
  console.log(`  ${fn} tx: ${hash}`);
}

async function main() {
  console.log("Deployer:", account.address);

  const comptroller = loadArtifact(ARTIFACTS.Comptroller);
  const oracle = loadArtifact(ARTIFACTS.Oracle);
  const marketArtifact = loadArtifact(ARTIFACTS.Market);

  const rubDecimals = (await publicClient.readContract({
    address: RUB,
    abi: ERC20_ABI,
    functionName: "decimals",
  })) as number;
  console.log("RUB decimals:", rubDecimals);

  // 1. Seed the oracle fallback price (no Quickswap pool for RUB).
  console.log("\nSetting oracle fallback price for RUB…");
  await send(ORACLE, oracle.abi, "setFallbackPrice", [RUB, RUB_USD_FALLBACK]);

  // 2. Deploy LendingMarket for RUB.
  console.log("\nDeploying LendingMarket(RUB) at", RUB, "…");
  const deployHash = await walletClient.deployContract({
    abi: marketArtifact.abi,
    bytecode: marketArtifact.bytecode,
    args: [
      RUB,
      COMPTROLLER,
      RATE_MODEL,
      RESERVE_FACTOR,
      "Cyberia Lend RUB",
      "clRUB",
      rubDecimals,
      account.address,
    ],
    gas: 10_000_000n,
  } as any);
  const receipt = await publicClient.waitForTransactionReceipt({ hash: deployHash });
  if (!receipt.contractAddress) throw new Error("No contract address");
  const newMarket = receipt.contractAddress as `0x${string}`;
  console.log("  market:", newMarket);

  // 3. List in comptroller + set collateral factor.
  console.log("\nListing new market in comptroller…");
  await send(COMPTROLLER, comptroller.abi, "supportMarket", [newMarket]);
  await send(COMPTROLLER, comptroller.abi, "setCollateralFactor", [newMarket, COLLATERAL_FACTOR]);

  // 4. Patch the deployment JSON.
  const outFile = path.resolve("./deployments/cyberia-lending.json");
  const raw = JSON.parse(fs.readFileSync(outFile, "utf8"));
  raw.markets.RUB = { address: newMarket, underlying: RUB };
  raw.timestamp = new Date().toISOString();
  fs.writeFileSync(outFile, JSON.stringify(raw, null, 2));
  console.log("\nUpdated", outFile);
}

main().catch((e) => { console.error(e); process.exit(1); });
