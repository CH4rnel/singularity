/**
 * Deploys CyberiaGasStation — the tank of CYBER that pays wallet users' fees.
 *
 * The deployer (DEPLOYER_PK, the bridge relayer EOA) becomes the owner and the
 * first operator. The backend gets its *own* operator key afterwards:
 *
 *   npx tsx scripts/gas-station.ts operator <station> <sponsor-address> true
 *
 * That separation is the point of the contract. The sponsor key sits on a
 * web-facing server and may only pull one drip at a time, under the cooldown
 * and the daily cap; the owner key can change policy and drain the tank and
 * does not have to live there.
 *
 * Uses ethers rather than viem, like the other Cyberia deploy scripts: the
 * polygon-edge JSON-RPC rejects part of viem's request pipeline.
 *
 * Usage:
 *   npx hardhat compile && npx tsx scripts/deploy-gas-station.ts [fundCyber]
 *
 * env:
 *   DEPLOYER_PK                    — owner EOA
 *   CYBERIA_RPC_URL                — defaults to https://rpc.cyberia.church
 *   GAS_STATION_DEPLOY_GAS_PRICE_WEI — override when the node's quote misbehaves
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
    "./artifacts/contracts/CyberiaGasStation.sol/CyberiaGasStation.json",
    "utf8",
  ),
);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const CHAIN_ID = Number(process.env.EVM_CHAIN_ID ?? 49406);
const network = new ethers.Network("cyberia", CHAIN_ID);
const provider = new ethers.JsonRpcProvider(RPC_URL, network, {
  staticNetwork: network,
});
const wallet = new ethers.Wallet(pk, provider);

/** Initial tank, in CYBER. A station with no coin in it cannot sponsor anything. */
const fundCyber = process.argv[2] ?? "0";

async function main() {
  console.log("Deploying CyberiaGasStation...");
  console.log("  Deployer / owner:", wallet.address);
  console.log("  RPC:", RPC_URL);
  console.log("  Initial tank:", fundCyber, "CYBER");

  const factory = new ethers.ContractFactory(
    artifact.abi,
    artifact.bytecode,
    wallet,
  );

  // polygon-edge rejects eth_estimateGas for deployments (to: null), so the
  // limit is stated. The pool floors the gas price around 1.5 gwei; the node's
  // own quote is used unless it is overridden.
  const contract = await factory.deploy(wallet.address, {
    gasLimit: 1_200_000,
    value: ethers.parseEther(fundCyber),
    ...(process.env.GAS_STATION_DEPLOY_GAS_PRICE_WEI
      ? { gasPrice: BigInt(process.env.GAS_STATION_DEPLOY_GAS_PRICE_WEI) }
      : {}),
  });

  const tx = contract.deploymentTransaction();
  console.log("Transaction hash:", tx?.hash);

  await contract.waitForDeployment();
  const address = await contract.getAddress();
  const station = new ethers.Contract(address, artifact.abi, provider);
  const [tank, drip, ceiling, wait, cap] = await station.summary();

  console.log("\nCyberiaGasStation deployed at:", address);
  console.log("  tank:    ", ethers.formatEther(tank), "CYBER");
  console.log("  drip:    ", ethers.formatEther(drip), "CYBER per claim");
  console.log("  ceiling: ", ethers.formatEther(ceiling), "CYBER");
  console.log("  cooldown:", Number(wait) / 3600, "hours");
  console.log("  daily cap:", ethers.formatEther(cap), "CYBER");

  const file = path.join("deployments", "cyberia-gas-station.json");
  fs.writeFileSync(
    file,
    `${JSON.stringify(
      {
        chainId: CHAIN_ID,
        rpc: RPC_URL,
        _doc: "Gas station sponsoring wallet users' fees on Cyberia. Policy lives on-chain; who qualifies is decided by the Laravel backend (GasSponsorService).",
        address,
        owner: wallet.address,
        deployedAt: new Date().toISOString(),
      },
      null,
      2,
    )}\n`,
  );

  console.log("\nWrote", file);
  console.log("\nNext:");
  console.log(
    "  1. npx tsx scripts/gas-station.ts operator",
    address,
    "<sponsor-address> true",
  );
  console.log("  2. Set WALLET_GAS_STATION_ADDRESS in backend/laravel/.env");
  console.log("  3. Fund the tank: npx tsx scripts/gas-station.ts fund", address, "<cyber>");
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
