/**
 * Deploys CyberiaProfile (on-chain nicknames + achievements) to Cyberia.
 *
 * The deployer (DEPLOYER_PK = the bridge relayer EOA) stays the owner: the
 * Laravel backend awards achievements and sets nicknames on behalf of
 * web2-onboarded users through scripts/profile-admin.ts.
 *
 * Uses ethers (same stack as the relay scripts) because the polygon-edge
 * JSON-RPC rejects part of viem's request pipeline.
 *
 * Usage:
 *   npx hardhat compile && npx tsx scripts/deploy-profile.ts
 */
import "dotenv/config";
import { ethers } from "ethers";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set");

const pk = DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`;
const artifact = JSON.parse(
  fs.readFileSync(
    "./artifacts/contracts/CyberiaProfile.sol/CyberiaProfile.json",
    "utf8",
  ),
);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const network = new ethers.Network("cyberia", 49406);
const provider = new ethers.JsonRpcProvider(RPC_URL, network, {
  staticNetwork: network,
});
const wallet = new ethers.Wallet(pk, provider);

async function main() {
  console.log("Deploying CyberiaProfile...");
  console.log("  Deployer / owner:", wallet.address);
  console.log("  RPC:", RPC_URL);

  const factory = new ethers.ContractFactory(
    artifact.abi,
    artifact.bytecode,
    wallet,
  );

  // polygon-edge rejects eth_estimateGas for deployments (to: null) — pass
  // an explicit gas limit instead of letting ethers estimate. Gas price
  // comes from the node (the pool enforces a ~1.5 gwei floor); override
  // with PROFILE_DEPLOY_GAS_PRICE_WEI only if that quote misbehaves.
  const contract = await factory.deploy({
    gasLimit: 980_000,
    ...(process.env.PROFILE_DEPLOY_GAS_PRICE_WEI
      ? { gasPrice: BigInt(process.env.PROFILE_DEPLOY_GAS_PRICE_WEI) }
      : {}),
  });
  const tx = contract.deploymentTransaction();
  console.log("Transaction hash:", tx?.hash);

  await contract.waitForDeployment();
  const address = await contract.getAddress();

  console.log("CyberiaProfile deployed at:", address);
  console.log(
    "\nSet CYBERIA_PROFILE_ADDRESS in backend/laravel/.env (or the",
  );
  console.log(
    "config/services.php 'profile.contract_address' default) to this address.",
  );
}

main().catch((e) => {
  console.error(e?.message ?? e);
  process.exit(1);
});
