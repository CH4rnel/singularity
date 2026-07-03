/**
 * Deploys PredictionMarket (parimutuel YES/NO markets in native CYBER) on Cyberia.
 *
 * Usage:
 *   npx hardhat compile
 *   npx hardhat run scripts/deploy-predictions.ts --network cyberia
 *
 * Then set VITE_PREDICTIONS_CONTRACT to the printed address in the Laravel
 * app's env and rebuild the frontend. Markets are created/resolved with
 * scripts/predictions-admin.ts (the deployer is the oracle/owner).
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  type Abi,
  type Hex,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

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

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

function loadArtifact(p: string): { abi: Abi; bytecode: Hex } {
  return JSON.parse(fs.readFileSync(path.resolve(p), "utf8")) as {
    abi: Abi;
    bytecode: Hex;
  };
}

async function main() {
  console.log("=== Deploy PredictionMarket ===");
  console.log("Deployer:", account.address);
  console.log("RPC:     ", RPC_URL);

  const art = loadArtifact(
    "./artifacts/contracts/PredictionMarket.sol/PredictionMarket.json",
  );
  const hash = await walletClient.deployContract({
    abi: art.abi,
    bytecode: art.bytecode,
    args: [account.address],
  });
  console.log("PredictionMarket deploy tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success" || !receipt.contractAddress) {
    throw new Error("PredictionMarket deploy reverted");
  }
  console.log("PredictionMarket:", receipt.contractAddress);
  console.log("gas used:", receipt.gasUsed.toString());

  const out = {
    chainId: 49406,
    rpc: RPC_URL,
    PredictionMarket: receipt.contractAddress,
    oracle: account.address,
    feeBps: 200,
    createFeeCyber: 1,
    resolveWindowDays: 30,
    deployer: account.address,
    deployTx: hash,
    timestamp: new Date().toISOString(),
  };
  const outPath = path.resolve("./deployments/cyberia-predictions.json");
  fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
  console.log("\nWrote:", outPath);
  console.log(
    "\nSet VITE_PREDICTIONS_CONTRACT=" +
      receipt.contractAddress +
      " in the Laravel env and rebuild.",
  );
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
