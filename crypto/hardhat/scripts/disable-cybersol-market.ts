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
const CYBERSOL_MARKET = "0x6AdD4E2Cf9Aab6D66B611a64C73270f18323C709" as const;

async function main() {
  const abi = JSON.parse(
    fs.readFileSync(
      path.resolve("./artifacts/contracts/lending/LendingComptroller.sol/LendingComptroller.json"),
      "utf8",
    ),
  ).abi;

  const hash = await w.writeContract({
    address: COMPTROLLER,
    abi,
    functionName: "setCollateralFactor",
    args: [CYBERSOL_MARKET, 0n],
    gas: 3_000_000n,
  } as any);
  console.log("tx:", hash);
  const r = await p.waitForTransactionReceipt({ hash });
  console.log("status:", r.status);
}

main().catch((e) => { console.error(e); process.exit(1); });
