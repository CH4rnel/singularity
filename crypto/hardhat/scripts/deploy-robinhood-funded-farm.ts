/**
 * Deploy the satellite-chain farm rail on Robinhood Chain (4663):
 *   1. AshBridged   — relayer-owned bridged ASH (no premint), 18 dec.
 *   2. FundedFarm   — MasterChef-compatible farm that PAYS FROM BALANCE (no
 *                     mint), rewardToken = bridged ASH, rewardPerBlock 0 until
 *                     the keeper funds it and sets the chain's share.
 *
 * This replaces the mis-deployed standalone MasterChef (0x78272…, emission
 * already zeroed) and its junk local ASH (0x176C70…). Reward pools are added
 * by scripts/add-robinhood-funded-pools.ts.
 *
 * Usage: npx tsx scripts/deploy-robinhood-funded-farm.ts
 */

import "dotenv/config";
import { createWalletClient, createPublicClient, http, type Abi, type Hex } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const RPC_URL = process.env.ROBINHOOD_RPC_URL ?? "https://rpc.mainnet.chain.robinhood.com";
const DEPLOYMENT_FILE = "./deployments/robinhood-funded-farm.json";

const chain = {
  ...mainnet,
  id: 4663,
  name: "Robinhood Chain",
  nativeCurrency: { name: "Ethereum", symbol: "ETH", decimals: 18 },
};
const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

if (fs.existsSync(DEPLOYMENT_FILE) && process.env.FORCE !== "true") {
  throw new Error(`${DEPLOYMENT_FILE} already exists — set FORCE=true to redeploy`);
}

async function deploy(label: string, artifactPath: string, args: unknown[]): Promise<`0x${string}`> {
  const artifact = JSON.parse(fs.readFileSync(artifactPath, "utf8"));
  console.log(`Deploying ${label}...`);
  const hash = await walletClient.deployContract({
    abi: artifact.abi as Abi,
    bytecode: artifact.bytecode as Hex,
    args,
  });
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success" || !receipt.contractAddress) {
    throw new Error(`${label} deployment reverted (${hash})`);
  }
  console.log(`  ${label}: ${receipt.contractAddress} (tx ${hash})`);
  return receipt.contractAddress;
}

async function main() {
  console.log("=== Robinhood funded-farm rail (4663) ===");
  console.log("Deployer / owner:", account.address);

  const ash = await deploy(
    "AshBridged",
    "./artifacts/contracts/AshBridged.sol/AshBridged.json",
    [account.address],
  );
  // rewardPerBlock 0, startBlock 0 — the keeper funds it and sets the rate.
  const farm = await deploy(
    "FundedFarm",
    "./artifacts/contracts/FundedFarm.sol/FundedFarm.json",
    [ash, 0n, 0n],
  );

  const record = {
    chainId: 4663,
    chainName: "Robinhood Chain",
    rpc: RPC_URL,
    deployer: account.address,
    AshBridged: ash,
    FundedFarm: farm,
    retired: {
      standaloneMasterChef: "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9",
      junkAsh: "0x176C70dD7CF17056596D8c4C7E2b1f2537df978F",
    },
    timestamp: new Date().toISOString(),
  };
  fs.writeFileSync(DEPLOYMENT_FILE, JSON.stringify(record, null, 2) + "\n");
  console.log(`\nRecorded in ${DEPLOYMENT_FILE}`);
  console.log(JSON.stringify(record, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
