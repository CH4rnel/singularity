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

// Existing deployment, from deployments/cyberia-lending.json.
const COMPTROLLER = "0xF1d1c79C535dBCf7F68784B3bCb91d9030A970e1" as const;
const RATE_MODEL = "0xb8Bbc65916ABd83B0264E886a24A57836D2bF42f" as const;
const ORACLE = "0x8eA212E3D8Ea63738B3eCaD0dA5c1683f711F4af" as const;
const OLD_ASH_MARKET = "0x663a7ED665D97c2dC3B43947bC842Ee60d6fb43D" as const;

// Real ASH that users hold.
const ASH = "0x992Fca0a89DD95afb17751f6CC233Adb9B089df5" as const;

const ARTIFACTS = {
  Comptroller: "./artifacts/contracts/lending/LendingComptroller.sol/LendingComptroller.json",
  Oracle: "./artifacts/contracts/lending/SimplePriceOracle.sol/SimplePriceOracle.json",
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

  const ashDecimals = (await publicClient.readContract({
    address: ASH,
    abi: ERC20_ABI,
    functionName: "decimals",
  })) as number;
  console.log("ASH decimals:", ashDecimals);

  // 1. Set oracle price for new ASH at $0.10 — adjust later via setUnderlyingPrice.
  console.log("\nSetting oracle price for new ASH…");
  await send(ORACLE, oracle.abi, "setUnderlyingPrice", [ASH, 10n ** 17n]);

  // 2. Deploy LendingMarket for the real ASH.
  console.log("\nDeploying LendingMarket(ASH) at", ASH, "…");
  const deployHash = await walletClient.deployContract({
    abi: marketArtifact.abi,
    bytecode: marketArtifact.bytecode,
    args: [
      ASH,
      COMPTROLLER,
      RATE_MODEL,
      2n * 10n ** 17n, // reserveFactor 20%
      "Cyberia Lend ASH",
      "clASH",
      ashDecimals,
      account.address,
    ],
    gas: 10_000_000n,
  } as any);
  const receipt = await publicClient.waitForTransactionReceipt({ hash: deployHash });
  if (!receipt.contractAddress) throw new Error("No contract address");
  const newMarket = receipt.contractAddress as `0x${string}`;
  console.log("  market:", newMarket);

  // 3. Support + collateral factor 40%.
  console.log("\nListing new market in comptroller…");
  await send(COMPTROLLER, comptroller.abi, "supportMarket", [newMarket]);
  await send(COMPTROLLER, comptroller.abi, "setCollateralFactor", [newMarket, 40n * 10n ** 16n]);

  // 4. Disable the wrong ASH market (CF=0 so nobody can use it as collateral).
  console.log("\nZeroing CF on the old ASH market", OLD_ASH_MARKET, "…");
  await send(COMPTROLLER, comptroller.abi, "setCollateralFactor", [OLD_ASH_MARKET, 0n]);

  // 5. Patch the deployment JSON.
  const outFile = path.resolve("./deployments/cyberia-lending.json");
  const raw = JSON.parse(fs.readFileSync(outFile, "utf8"));
  raw.markets.ASH_legacy = raw.markets.ASH;
  raw.markets.ASH = { address: newMarket, underlying: ASH };
  raw.timestamp = new Date().toISOString();
  fs.writeFileSync(outFile, JSON.stringify(raw, null, 2));
  console.log("\nUpdated", outFile);
}

main().catch((e) => { console.error(e); process.exit(1); });
