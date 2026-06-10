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

const OLD_COMPTROLLER = "0xF1d1c79C535dBCf7F68784B3bCb91d9030A970e1" as const;
const ORACLE = "0x8fEA279fb70D3D1B20a0E50cbC649c83C41Dc4D1" as const; // QuickswapPriceOracle

const ARTIFACTS = {
  Comptroller: "./artifacts/contracts/lending/LendingComptroller.sol/LendingComptroller.json",
  Market: "./artifacts/contracts/lending/LendingMarket.sol/LendingMarket.json",
};

const MANTISSA = 10n ** 18n;

// Active + legacy markets. CF=0 markets are kept listed so users with positions
// can still repay/withdraw via the new comptroller.
const MARKETS: { symbol: string; address: `0x${string}`; cf: bigint }[] = [
  { symbol: "WCYBER",        address: "0x5ea7cFE8971cCbD521F0f9db6Da7E019dBe2Ab8d", cf: 75n * 10n ** 16n },
  { symbol: "ASH",           address: "0x6956c6feb62448ED059CC73BB3f461D97537D808", cf: 40n * 10n ** 16n },
  { symbol: "CYBER",         address: "0x6AdD4E2Cf9Aab6D66B611a64C73270f18323C709", cf: 60n * 10n ** 16n },
  { symbol: "ASH_legacy",    address: "0x663a7ED665D97c2dC3B43947bC842Ee60d6fb43D", cf: 0n },
  { symbol: "CYBER_legacy",  address: "0xF697D741BF663878571d9F36E0FE3E100A7Faa52", cf: 0n },
];

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

  const compArt = loadArtifact(ARTIFACTS.Comptroller);
  const marketArt = loadArtifact(ARTIFACTS.Market);

  // 1. Deploy new comptroller with the seize-math fix.
  console.log("\nDeploying new LendingComptroller…");
  const deployHash = await w.deployContract({
    abi: compArt.abi,
    bytecode: compArt.bytecode,
    args: [],
    gas: 10_000_000n,
  } as any);
  const deployReceipt = await p.waitForTransactionReceipt({ hash: deployHash });
  if (!deployReceipt.contractAddress) throw new Error("No contract address");
  const newComp = deployReceipt.contractAddress as `0x${string}`;
  console.log("  new comptroller:", newComp);

  // 2. Wire params.
  console.log("\nWiring oracle / closeFactor / liquidationIncentive…");
  await send(newComp, compArt.abi, "setPriceOracle", [ORACLE]);
  await send(newComp, compArt.abi, "setCloseFactor", [5n * 10n ** 17n]);
  await send(newComp, compArt.abi, "setLiquidationIncentive", [108n * 10n ** 16n]);

  // 3. List all markets and re-apply collateral factors.
  for (const m of MARKETS) {
    console.log(`\n--- ${m.symbol} (${m.address}) ---`);

    // Before listing, the market must point at the new comptroller (the
    // `supportMarket` admin check calls `market.comptroller()` and requires it
    // equals `address(this)`). Flip the pointer first.
    console.log("  market.setComptroller(new)…");
    await send(m.address, marketArt.abi, "setComptroller", [newComp]);

    console.log("  comptroller.supportMarket…");
    await send(newComp, compArt.abi, "supportMarket", [m.address]);

    if (m.cf > 0n) {
      console.log(`  comptroller.setCollateralFactor(${m.cf})…`);
      await send(newComp, compArt.abi, "setCollateralFactor", [m.address, m.cf]);
    }
  }

  // 4. Patch deployment JSON.
  const outFile = path.resolve("./deployments/cyberia-lending.json");
  const raw = JSON.parse(fs.readFileSync(outFile, "utf8"));
  raw.LendingComptroller_legacy = raw.LendingComptroller;
  raw.LendingComptroller = newComp;
  raw.note = "Comptroller v2 with fixed liquidateCalculateSeizeShares (1e18 scale bug). Users must enterMarkets again for collateral to be counted.";
  raw.timestamp = new Date().toISOString();
  fs.writeFileSync(outFile, JSON.stringify(raw, null, 2));
  console.log("\nUpdated", outFile);

  console.log("\n──────────────────");
  console.log("Set in backend/laravel/.env:");
  console.log(`VITE_LENDING_COMPTROLLER=${newComp}`);
}

main().catch((e) => { console.error(e); process.exit(1); });
