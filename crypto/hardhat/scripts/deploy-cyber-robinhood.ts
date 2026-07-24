/**
 * Deploy the Cyber (bridged native CYBER) token on Robinhood Chain (4663).
 *
 * No premint: the bridge relayer (contract owner) mint()s on every verified
 * Cyberia-side deposit and burnFrom()s deposits coming back, so the supply
 * here always equals the native CYBER locked on the relayer EOA on Cyberia.
 *
 * If OLD_WRAPPER is set (a previous relayer-owned deployment), its relayer
 * balance is burned so no abandoned CYBER supply floats on the explorer.
 *
 * Usage:
 *   npx hardhat compile
 *   npx tsx scripts/deploy-cyber-robinhood.ts
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
const DEPLOYMENT_FILE = "./deployments/robinhood-cyber.json";
const OLD_WRAPPER = process.env.OLD_WRAPPER as `0x${string}` | undefined;

const chain = {
  ...mainnet,
  id: 4663,
  name: "Robinhood Chain",
  nativeCurrency: { name: "Ethereum", symbol: "ETH", decimals: 18 },
};

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const ERC20_BURN_ABI = [
  { type: "function", name: "balanceOf", stateMutability: "view",
    inputs: [{ name: "", type: "address" }], outputs: [{ type: "uint256" }] },
  { type: "function", name: "burn", stateMutability: "nonpayable",
    inputs: [{ name: "amount", type: "uint256" }], outputs: [] },
] as const satisfies Abi;

async function main() {
  const artifact = JSON.parse(
    fs.readFileSync("./artifacts/contracts/Cyber.sol/Cyber.json", "utf8"),
  );

  console.log("Deploying Cyber (bridged CYBER) on Robinhood Chain (4663)...");
  console.log("  Deployer / owner:", account.address);

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
  console.log("  Cyber:", address, `(tx ${hash})`);

  if (OLD_WRAPPER) {
    const stale = (await publicClient.readContract({
      address: OLD_WRAPPER, abi: ERC20_BURN_ABI, functionName: "balanceOf",
      args: [account.address],
    })) as bigint;

    if (stale > 0n) {
      const burnHash = await walletClient.writeContract({
        address: OLD_WRAPPER, abi: ERC20_BURN_ABI, functionName: "burn", args: [stale],
      });
      const burnReceipt = await publicClient.waitForTransactionReceipt({ hash: burnHash });
      if (burnReceipt.status !== "success") {
        throw new Error(`Old wrapper burn reverted (${burnHash})`);
      }
      console.log(`  Old wrapper ${OLD_WRAPPER}: burned ${stale} (tx ${burnHash})`);
    }
  }

  const [symbol, name] = await Promise.all([
    publicClient.readContract({ address, abi: artifact.abi as Abi, functionName: "symbol" }),
    publicClient.readContract({ address, abi: artifact.abi as Abi, functionName: "name" }),
  ]);

  const record = {
    chainId: 4663,
    chainName: "Robinhood Chain",
    rpc: RPC_URL,
    deployer: account.address,
    Cyber: address,
    name,
    symbol,
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
