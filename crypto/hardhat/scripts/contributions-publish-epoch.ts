/**
 * Publishes one epoch root to CyberiaContributorRewards — the moment rewards
 * become claimable.
 *
 * The root and total come from `php artisan contributions:epoch build`, which
 * prints this exact command. The contract refuses a root it cannot pay in
 * full, so fund it first; publishing is once per epoch and cannot be undone.
 * Afterwards record it on the Laravel side:
 *
 *   php artisan contributions:epoch published <EPOCH> <tx-hash>
 *
 * Usage:
 *   EPOCH=1 ROOT=0x… TOTAL=<raw units> npx tsx scripts/contributions-publish-epoch.ts
 *
 * env:
 *   DEPLOYER_PK (the contract owner), CONTRIBUTIONS_CONTRACT_ADDRESS or
 *   deployments/cyberia-contributor-rewards.json, CYBERIA_RPC_URL
 */
import "dotenv/config";
import { ethers } from "ethers";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");
const { EPOCH, ROOT, TOTAL } = process.env;
if (!EPOCH || !ROOT || !TOTAL) throw new Error("EPOCH, ROOT and TOTAL are required");
if (!/^0x[0-9a-fA-F]{64}$/.test(ROOT)) throw new Error("ROOT is not a 32-byte hash");

const address =
  process.env.CONTRIBUTIONS_CONTRACT_ADDRESS ??
  JSON.parse(fs.readFileSync("deployments/cyberia-contributor-rewards.json", "utf8")).address;

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const abi = JSON.parse(
  fs.readFileSync(
    "./artifacts/contracts/CyberiaContributorRewards.sol/CyberiaContributorRewards.json",
    "utf8",
  ),
).abi;

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const CHAIN_ID = Number(process.env.EVM_CHAIN_ID ?? 49406);
const network = new ethers.Network("cyberia", CHAIN_ID);
const provider = new ethers.JsonRpcProvider(RPC_URL, network, { staticNetwork: network });
const wallet = new ethers.Wallet(pk, provider);
const rewards = new ethers.Contract(address, abi, wallet);

async function main() {
  const total = BigInt(TOTAL!);
  const surplus: bigint = await rewards.surplus();
  console.log(`Epoch ${EPOCH} → ${address}`);
  console.log("  root: ", ROOT);
  console.log("  total:", total.toString(), "raw");
  console.log("  surplus available:", surplus.toString(), "raw");
  if (surplus < total) {
    throw new Error(`Underfunded by ${(total - surplus).toString()} raw units — fund the contract first.`);
  }

  const tx = await rewards.publish(BigInt(EPOCH!), ROOT, total, { gasLimit: 200_000 });
  console.log("Transaction hash:", tx.hash);
  await tx.wait();
  console.log(`\nPublished. Now: php artisan contributions:epoch published ${EPOCH} ${tx.hash}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
