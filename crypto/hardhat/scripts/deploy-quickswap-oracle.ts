import "dotenv/config";
import { createWalletClient, createPublicClient, http } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

const pk = (process.env.DEPLOYER_PK!.startsWith("0x") ? process.env.DEPLOYER_PK! : "0x" + process.env.DEPLOYER_PK!) as `0x${string}`;
const account = privateKeyToAccount(pk);
const chain = { ...mainnet, id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 } };
const RPC = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";
const w = createWalletClient({ chain, transport: http(RPC), account });
const p = createPublicClient({ chain, transport: http(RPC) });

const COMPTROLLER = "0xF1d1c79C535dBCf7F68784B3bCb91d9030A970e1" as const;
const QUICKSWAP_FACTORY = "0xB0aC30907c04b61F1482e62eA66eF4562a690917" as const;
const WCYBER = "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9" as const;

const ARTIFACTS = {
  Comptroller: "./artifacts/contracts/lending/LendingComptroller.sol/LendingComptroller.json",
  Oracle: "./artifacts/contracts/lending/QuickswapPriceOracle.sol/QuickswapPriceOracle.json",
};

function loadArtifact(p: string) {
  const raw = fs.readFileSync(path.resolve(p), "utf8");
  return JSON.parse(raw) as { abi: any[]; bytecode: `0x${string}` };
}

async function send(to: `0x${string}`, abi: any[], fn: string, args: unknown[]) {
  const hash = await w.writeContract({ address: to, abi, functionName: fn, args, gas: 3_000_000n } as any);
  const r = await p.waitForTransactionReceipt({ hash });
  if (r.status !== "success") throw new Error(`${fn} failed: ${hash}`);
  console.log(`  ${fn} tx: ${hash}`);
}

async function main() {
  console.log("Deployer:", account.address);

  const oracleArt = loadArtifact(ARTIFACTS.Oracle);
  const comptrollerArt = loadArtifact(ARTIFACTS.Comptroller);

  console.log("\nDeploying QuickswapPriceOracle…");
  const hash = await w.deployContract({
    abi: oracleArt.abi,
    bytecode: oracleArt.bytecode,
    args: [QUICKSWAP_FACTORY, WCYBER],
    gas: 5_000_000n,
  } as any);
  console.log(`  tx: ${hash}`);
  const receipt = await p.waitForTransactionReceipt({ hash });
  if (!receipt.contractAddress) throw new Error("No contract address");
  const oracle = receipt.contractAddress as `0x${string}`;
  console.log("  oracle:", oracle);

  console.log("\nPointing comptroller at the new oracle…");
  await send(COMPTROLLER, comptrollerArt.abi, "setPriceOracle", [oracle]);

  // Sanity check: read prices for each listed market.
  console.log("\nResolved prices (Compound-normalized, scaled by 10^(36 - decimals)):");
  const deployment = JSON.parse(
    fs.readFileSync(path.resolve("./deployments/cyberia-lending.json"), "utf8"),
  );
  for (const [symbol, info] of Object.entries(deployment.markets) as [string, { address: `0x${string}` }][]) {
    const result = await p.readContract({
      address: oracle,
      abi: oracleArt.abi,
      functionName: "getUnderlyingPrice",
      args: [info.address],
    });
    console.log(`  ${symbol}: ${result}`);
  }

  deployment.QuickswapPriceOracle = oracle;
  deployment.SimplePriceOracle_legacy = deployment.SimplePriceOracle;
  delete deployment.SimplePriceOracle;
  deployment.timestamp = new Date().toISOString();
  fs.writeFileSync(
    path.resolve("./deployments/cyberia-lending.json"),
    JSON.stringify(deployment, null, 2),
  );
  console.log("\nDeployments updated.");
}

main().catch((e) => { console.error(e); process.exit(1); });
