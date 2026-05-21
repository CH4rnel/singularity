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
const ORACLE = "0x8eA212E3D8Ea63738B3eCaD0dA5c1683f711F4af" as const;

// Real CYBER.sol per the user. Market for it was deployed as "CYBERSOL".
const REAL_CYBER_UNDERLYING = "0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA" as const;
const REAL_CYBER_MARKET = "0x6AdD4E2Cf9Aab6D66B611a64C73270f18323C709" as const;
// What I incorrectly deployed as CYBER (CyberToken.sol, the bridge wrapper).
const WRONG_CYBER_MARKET = "0xF697D741BF663878571d9F36E0FE3E100A7Faa52" as const;

const MANTISSA = 10n ** 18n;

async function send(to: `0x${string}`, abi: any[], fn: string, args: unknown[]) {
  const hash = await w.writeContract({ address: to, abi, functionName: fn, args, gas: 3_000_000n } as any);
  const r = await p.waitForTransactionReceipt({ hash });
  if (r.status !== "success") throw new Error(`${fn} failed: ${hash}`);
  console.log(`  ${fn} tx: ${hash}`);
}

async function main() {
  const comptroller = JSON.parse(
    fs.readFileSync(path.resolve("./artifacts/contracts/lending/LendingComptroller.sol/LendingComptroller.json"), "utf8"),
  ).abi;
  const oracle = JSON.parse(
    fs.readFileSync(path.resolve("./artifacts/contracts/lending/SimplePriceOracle.sol/SimplePriceOracle.json"), "utf8"),
  ).abi;

  console.log("Re-pricing real CYBER underlying to $1 …");
  await send(ORACLE, oracle, "setUnderlyingPrice", [REAL_CYBER_UNDERLYING, MANTISSA]);

  console.log("\nRe-enabling real CYBER market at CF 60% …");
  await send(COMPTROLLER, comptroller, "setCollateralFactor", [REAL_CYBER_MARKET, 60n * 10n ** 16n]);

  console.log("\nDisabling wrong (CyberToken) market …");
  await send(COMPTROLLER, comptroller, "setCollateralFactor", [WRONG_CYBER_MARKET, 0n]);

  // Patch the deployments file.
  const outFile = path.resolve("./deployments/cyberia-lending.json");
  const raw = JSON.parse(fs.readFileSync(outFile, "utf8"));
  raw.markets.CYBER_legacy = raw.markets.CYBER;
  raw.markets.CYBER = { address: REAL_CYBER_MARKET, underlying: REAL_CYBER_UNDERLYING };
  delete raw.markets.CYBERSOL_legacy;
  raw.timestamp = new Date().toISOString();
  fs.writeFileSync(outFile, JSON.stringify(raw, null, 2));
  console.log("\nUpdated", outFile);
}

main().catch((e) => { console.error(e); process.exit(1); });
