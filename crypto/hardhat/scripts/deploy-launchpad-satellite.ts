/**
 * Deploys LaunchpadNative on a satellite chain so the launchpad UI can offer
 * it as a launch target next to Cyberia. Same contract as
 * `deploy-launchpad-native.ts`, but the chain and the QuickSwap router it
 * pairs against come from the environment instead of being hardcoded, and no
 * pre-existing tokens are registered.
 *
 * Usage (Robinhood Chain, router from deployments/robinhood-dex.json):
 *   LAUNCHPAD_CHAIN_ID=4663 \
 *   LAUNCHPAD_RPC_URL=https://rpc.mainnet.chain.robinhood.com \
 *   LAUNCHPAD_ROUTER=0xb0ac30907c04b61f1482e62ea66ef4562a690917 \
 *   LAUNCHPAD_MIN_LIQUIDITY=0.01 \
 *   LAUNCHPAD_LABEL=robinhood \
 *   npx hardhat run scripts/deploy-launchpad-satellite.ts --network cyberia
 *
 * Afterwards add the printed address to
 * `backend/laravel/resources/js/lib/launchpadChains.ts` (and the chain id to
 * `backend/laravel/config/launchpad.php`) to light the network up in the UI.
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  parseEther,
  type Abi,
  type Hex,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

const CHAIN_ID = Number(process.env.LAUNCHPAD_CHAIN_ID);
const RPC_URL = process.env.LAUNCHPAD_RPC_URL;
const ROUTER = process.env.LAUNCHPAD_ROUTER as `0x${string}` | undefined;
const LABEL = process.env.LAUNCHPAD_LABEL || `chain-${CHAIN_ID}`;
const MIN_LIQUIDITY = parseEther(process.env.LAUNCHPAD_MIN_LIQUIDITY || "0.01");

if (!CHAIN_ID || !RPC_URL || !ROUTER) {
  throw new Error(
    "Set LAUNCHPAD_CHAIN_ID, LAUNCHPAD_RPC_URL and LAUNCHPAD_ROUTER (see the header of this script)",
  );
}

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const chain = { ...mainnet, id: CHAIN_ID, name: LABEL };

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const artifact = JSON.parse(
  fs.readFileSync(
    path.resolve("./artifacts/contracts/LaunchpadNative.sol/LaunchpadNative.json"),
    "utf8",
  ),
) as { abi: Abi; bytecode: Hex };

async function main() {
  console.log("=== Deploy LaunchpadNative (satellite) ===");
  console.log("Deployer:    ", account.address);
  console.log("Chain:       ", LABEL, `(${CHAIN_ID})`);
  console.log("RPC:         ", RPC_URL);
  console.log("Router:      ", ROUTER);
  console.log("minLiquidity:", MIN_LIQUIDITY.toString());

  const hash = await walletClient.deployContract({
    abi: artifact.abi,
    bytecode: artifact.bytecode,
    args: [ROUTER, MIN_LIQUIDITY],
  });
  console.log("\ndeploy tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success" || !receipt.contractAddress) {
    throw new Error("Deploy reverted");
  }

  const address = receipt.contractAddress;
  console.log("LaunchpadNative:", address);

  // Sanity-check the immutables read back from the chain.
  const [onchainWrapped, onchainRouter, onchainMin, factory] = await Promise.all([
    publicClient.readContract({ address, abi: artifact.abi, functionName: "wcyber" }),
    publicClient.readContract({ address, abi: artifact.abi, functionName: "router" }),
    publicClient.readContract({ address, abi: artifact.abi, functionName: "minLiquidity" }),
    publicClient.readContract({ address, abi: artifact.abi, functionName: "factory" }),
  ]);
  console.log("\nOn-chain readback:");
  console.log("  wcyber():      ", onchainWrapped);
  console.log("  router():      ", onchainRouter);
  console.log("  factory():     ", factory);
  console.log("  minLiquidity():", onchainMin);

  const out = {
    chainId: CHAIN_ID,
    rpc: RPC_URL,
    LaunchpadNative: address,
    router: ROUTER,
    factory,
    wrappedNative: onchainWrapped,
    minLiquidity: MIN_LIQUIDITY.toString(),
    deployer: account.address,
    deployTx: hash,
    timestamp: new Date().toISOString(),
  };
  const outPath = path.resolve(`./deployments/${LABEL}-launchpad-native.json`);
  fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
  console.log("\nWrote:", outPath);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
