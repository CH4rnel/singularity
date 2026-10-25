/**
 * Deploys LainStakingVault on Robinhood Chain and opens its three locked
 * tiers next to the flexible one the constructor creates:
 *
 *   tier 0 — no lock,  ×1.00
 *   tier 1 — 7 days,   ×1.25
 *   tier 2 — 30 days,  ×1.60
 *   tier 3 — 90 days,  ×2.00
 *
 * The stream is started separately, by the owner, once the rewards are in:
 *
 *   1. transfer the reward LAIN to the vault (a plain ERC-20 transfer)
 *   2. vault.schedule(182 days)  — streams everything in the reserve evenly
 *
 * Nothing here moves LAIN: deploying and funding are two decisions.
 *
 * Usage:
 *   npx hardhat compile && npx tsx scripts/deploy-lain-staking.ts
 *
 * env:
 *   DEPLOYER_PK          — pays for the deploy
 *   LAIN_STAKING_OWNER   — owner of the vault; defaults to the deployer
 *   ROBINHOOD_RPC_URL    — defaults to https://rpc.mainnet.chain.robinhood.com
 */
import "dotenv/config";
import { ethers } from "ethers";
import * as fs from "fs";

const LAIN = "0x2b79A071a75dd40f1aca68146d7978204eFD156A";
const DAY = 24 * 60 * 60;
const TIERS: Array<[number, number]> = [
  [7 * DAY, 12_500],
  [30 * DAY, 16_000],
  [90 * DAY, 20_000],
];

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const artifact = JSON.parse(
  fs.readFileSync(
    "./artifacts/contracts/LainStakingVault.sol/LainStakingVault.json",
    "utf8",
  ),
);

const RPC_URL =
  process.env.ROBINHOOD_RPC_URL ?? "https://rpc.mainnet.chain.robinhood.com";
const network = new ethers.Network("robinhood", 4663);
const provider = new ethers.JsonRpcProvider(RPC_URL, network, {
  staticNetwork: network,
});
const wallet = new ethers.Wallet(pk, provider);

async function main() {
  const owner = process.env.LAIN_STAKING_OWNER ?? wallet.address;
  console.log("Deploying LainStakingVault on Robinhood Chain...");
  console.log("  Deployer:", wallet.address);
  console.log("  Owner:   ", owner);

  // The deployer adds the tiers, so it owns the vault until they are in.
  const factory = new ethers.ContractFactory(artifact.abi, artifact.bytecode, wallet);
  const vault = await factory.deploy(LAIN, wallet.address);
  await vault.waitForDeployment();
  const address = await vault.getAddress();
  console.log("  Vault:   ", address);

  const v = vault as unknown as ethers.Contract;
  for (const [lock, boost] of TIERS) {
    const tx = await v.addTier(lock, boost);
    await tx.wait();
    console.log(`  Tier ${lock / DAY}d ×${boost / 10_000}: ${tx.hash}`);
  }

  if (owner.toLowerCase() !== wallet.address.toLowerCase()) {
    const tx = await v.transferOwnership(owner);
    await tx.wait();
    console.log("  Ownership →", owner, tx.hash);
  }

  console.log("\nNext: send the reward LAIN to", address, "and call schedule(182 days).");
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
