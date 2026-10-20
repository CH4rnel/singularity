/**
 * Deploys CyberiaContributorRewards — the claim contract of the open-source
 * reward engine (backend/laravel, config/contributions.php).
 *
 * One asset per deployment, because what contributors are paid in is still a
 * decision for the project: `native` for CYBER, or an ERC-20 address on
 * Cyberia. The claim window is fixed here for good — after it, the owner may
 * return what nobody claimed to the pool; 0 means rewards never expire.
 *
 * The deployer becomes the owner, i.e. the key that publishes epoch roots.
 * The intended next owner is the DAO (transferOwnership + acceptOwnership).
 *
 * Usage:
 *   npx hardhat compile && npx tsx scripts/deploy-contributor-rewards.ts <native|0xToken> [claimWindowDays]
 *
 * env:
 *   DEPLOYER_PK, CYBERIA_RPC_URL (defaults to https://rpc.cyberia.church)
 */
import "dotenv/config";
import { ethers } from "ethers";
import * as fs from "fs";
import * as path from "path";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const artifact = JSON.parse(
  fs.readFileSync(
    "./artifacts/contracts/CyberiaContributorRewards.sol/CyberiaContributorRewards.json",
    "utf8",
  ),
);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const CHAIN_ID = Number(process.env.EVM_CHAIN_ID ?? 49406);
const network = new ethers.Network("cyberia", CHAIN_ID);
const provider = new ethers.JsonRpcProvider(RPC_URL, network, { staticNetwork: network });
const wallet = new ethers.Wallet(pk, provider);

const assetArg = process.argv[2] ?? "native";
const asset = assetArg === "native" ? ethers.ZeroAddress : ethers.getAddress(assetArg);
const windowDays = Number(process.argv[3] ?? 365);

async function main() {
  console.log("Deploying CyberiaContributorRewards...");
  console.log("  Owner:", wallet.address);
  console.log("  Asset:", asset === ethers.ZeroAddress ? "native CYBER" : asset);
  console.log("  Claim window:", windowDays === 0 ? "never expires" : `${windowDays} days`);

  const factory = new ethers.ContractFactory(artifact.abi, artifact.bytecode, wallet);
  // polygon-edge rejects eth_estimateGas for deployments, so the limit is stated.
  const contract = await factory.deploy(asset, BigInt(windowDays) * 86400n, { gasLimit: 1_500_000 });
  console.log("Transaction hash:", contract.deploymentTransaction()?.hash);
  await contract.waitForDeployment();
  const address = await contract.getAddress();

  const file = path.join("deployments", "cyberia-contributor-rewards.json");
  fs.writeFileSync(
    file,
    `${JSON.stringify(
      {
        chainId: CHAIN_ID,
        _doc: "Claim contract of the open-source reward engine. Roots are built by `php artisan contributions:epoch build` and published with scripts/contributions-publish-epoch.ts.",
        address,
        asset,
        claimWindowDays: windowDays,
        owner: wallet.address,
        deployedAt: new Date().toISOString(),
      },
      null,
      2,
    )}\n`,
  );

  console.log("\nCyberiaContributorRewards deployed at:", address);
  console.log("Wrote", file);
  console.log("\nNext:");
  console.log("  1. Set CONTRIBUTIONS_CONTRACT_ADDRESS and CONTRIBUTIONS_POOL_ASSET in backend/laravel/.env");
  console.log("  2. Fund it:", asset === ethers.ZeroAddress ? "send CYBER to the address" : "transfer the token to the address");
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
