/**
 * Deploy the WrappedCyber (bridged native CYBER) wrapper on Robinhood Chain
 * (4663) and premint the pooled bridge-payout inventory to the relayer.
 *
 * The wrapper is what evm_to_robinhood pays out (plain ERC20 transfer from
 * relayer inventory) and what robinhood_to_evm accepts as a deposit back to
 * the relayer EOA. Owner = relayer, so inventory can be restocked with
 * mint() at any time.
 *
 * Usage:
 *   npx hardhat compile
 *   npx tsx scripts/deploy-cyber-robinhood.ts
 */

import "dotenv/config";
import { createWalletClient, createPublicClient, http, parseEther, type Abi, type Hex } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");

const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const RPC_URL = process.env.ROBINHOOD_RPC_URL ?? "https://rpc.mainnet.chain.robinhood.com";
const DEPLOYMENT_FILE = "./deployments/robinhood-cyber.json";
// Pooled payout inventory preminted to the relayer. Only leaves the EOA when
// a user has deposited the same amount of native CYBER on Cyberia.
const INVENTORY = parseEther(process.env.CYBER_RH_INVENTORY ?? "1000000");

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

async function main() {
  const artifact = JSON.parse(
    fs.readFileSync("./artifacts/contracts/WrappedCyber.sol/WrappedCyber.json", "utf8"),
  );

  console.log("Deploying WrappedCyber on Robinhood Chain (4663)...");
  console.log("  Deployer / owner / inventory holder:", account.address);

  const hash = await walletClient.deployContract({
    abi: artifact.abi as Abi,
    bytecode: artifact.bytecode as Hex,
    args: [account.address],
  });
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success" || !receipt.contractAddress) {
    throw new Error(`Deployment reverted (${hash})`);
  }
  const address = receipt.contractAddress;
  console.log("  WrappedCyber:", address, `(tx ${hash})`);

  const mintHash = await walletClient.writeContract({
    address,
    abi: artifact.abi as Abi,
    functionName: "mint",
    args: [account.address, INVENTORY],
  });
  const mintReceipt = await publicClient.waitForTransactionReceipt({ hash: mintHash });
  if (mintReceipt.status !== "success") {
    throw new Error(`Inventory mint reverted (${mintHash})`);
  }
  console.log(`  Inventory minted to relayer (tx ${mintHash})`);

  const [symbol, supply] = await Promise.all([
    publicClient.readContract({ address, abi: artifact.abi as Abi, functionName: "symbol" }),
    publicClient.readContract({ address, abi: artifact.abi as Abi, functionName: "totalSupply" }),
  ]);

  const record = {
    chainId: 4663,
    chainName: "Robinhood Chain",
    rpc: RPC_URL,
    deployer: account.address,
    WrappedCyber: address,
    symbol,
    inventory: (supply as bigint).toString(),
    timestamp: new Date().toISOString(),
  };
  fs.writeFileSync(DEPLOYMENT_FILE, JSON.stringify(record, null, 2) + "\n");
  console.log(`\nDeployment recorded in ${DEPLOYMENT_FILE}`);
  console.log(JSON.stringify(record, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
