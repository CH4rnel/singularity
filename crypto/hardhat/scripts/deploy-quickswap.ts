/**
 * Deploy QuickSwap core contracts on Cyberia (chainId 49406):
 *   1. ASH token      (ERC-20, renamed from QuickEthereum/QUICK)
 *   2. UniswapV2Factory (feeToSetter = deployer)
 *   3. Optional: create ASH / WCYBER pair (if WCYBER_ADDRESS is set)
 *
 * INIT_CODE_HASH of UniswapV2Pair is printed after deployment — you need it
 * for the interface-v2 / v2-sdk constants.
 *
 * Usage (from hardhat dir):
 *   npx hardhat compile
 *   npx tsx scripts/deploy-quickswap.ts
 *
 * Env vars (.env):
 *   DEPLOYER_PK          — deployer private key (required)
 *   WCYBER_ADDRESS       — optional, if set a ASH/WCYBER pair is created
 *   ASH_MINTER           — optional, default = deployer
 *   ASH_PREMINT_RECIPIENT— optional, default = deployer
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  keccak256,
  type Abi,
  type Hex,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

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

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const walletClient = createWalletClient({
  chain,
  transport: http(RPC_URL),
  account,
});

const publicClient = createPublicClient({
  chain,
  transport: http(RPC_URL),
});

const WCYBER_ADDRESS = process.env.WCYBER_ADDRESS as `0x${string}` | undefined;
const ASH_MINTER = (process.env.ASH_MINTER as `0x${string}` | undefined) ?? account.address;
const ASH_PREMINT_RECIPIENT =
  (process.env.ASH_PREMINT_RECIPIENT as `0x${string}` | undefined) ?? account.address;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function loadArtifact(artifactPath: string) {
  const full = path.resolve(artifactPath);
  const raw = fs.readFileSync(full, "utf8");
  const artifact = JSON.parse(raw);
  return {
    abi: artifact.abi as Abi,
    bytecode: artifact.bytecode as Hex,
    contractName: artifact.contractName as string,
  };
}

async function deployContract(artifactPath: string, args: unknown[] = []) {
  const artifact = loadArtifact(artifactPath);
  console.log(`  deploying ${artifact.contractName}…`);
  const hash = await walletClient.deployContract({
    abi: artifact.abi,
    bytecode: artifact.bytecode,
    args,
  });
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    throw new Error(
      `${artifact.contractName} deployment reverted (tx ${hash}, gas used ${receipt.gasUsed})`,
    );
  }
  if (!receipt.contractAddress) {
    throw new Error(`${artifact.contractName} deployment has no contract address`);
  }
  // sanity check: deployed code must be non-empty
  const code = await publicClient.getCode({ address: receipt.contractAddress });
  if (!code || code === "0x") {
    throw new Error(
      `${artifact.contractName} deployed to ${receipt.contractAddress} but has no code`,
    );
  }
  console.log("  address:", receipt.contractAddress);
  console.log("  gas used:", receipt.gasUsed.toString());
  return { address: receipt.contractAddress, abi: artifact.abi, bytecode: artifact.bytecode };
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  console.log("=== QuickSwap deployment on Cyberia (chainId 49406) ===");
  console.log("RPC:", RPC_URL);
  console.log("Deployer:", account.address);

  const balance = await publicClient.getBalance({ address: account.address });
  console.log("Balance:", balance.toString(), "wei (", Number(balance) / 1e18, "CYBER)");
  if (balance === 0n) {
    throw new Error("Deployer has zero balance on Cyberia");
  }
  console.log("");

  // ---- 1. Deploy ASH token ----
  console.log("1. Deploying ASH token…");
  const latestBlock = await publicClient.getBlock({ blockTag: "latest" });
  // Constructor requires mintingAllowedAfter >= block.timestamp (checked at mining time).
  // Add a buffer so the tx is still valid once it lands in a later block.
  const mintingAllowedAfter = latestBlock.timestamp + 300n; // 5 min buffer
  console.log("   premint recipient:", ASH_PREMINT_RECIPIENT);
  console.log("   minter:           ", ASH_MINTER);
  console.log("   mintingAllowedAfter:", mintingAllowedAfter.toString());

  const ash = await deployContract(
    "./artifacts/contracts/quickswap/ASH.sol/ASH.json",
    [ASH_PREMINT_RECIPIENT, ASH_MINTER, mintingAllowedAfter],
  );
  console.log("");

  // ---- 2. Deploy UniswapV2Factory ----
  console.log("2. Deploying UniswapV2Factory…");
  console.log("   feeToSetter:", account.address);
  const factory = await deployContract(
    "./artifacts/contracts/quickswap/UniswapV2Factory.sol/UniswapV2Factory.json",
    [account.address],
  );
  console.log("");

  // ---- 3. INIT_CODE_HASH of UniswapV2Pair ----
  console.log("3. Computing UniswapV2Pair INIT_CODE_HASH…");
  const pairArtifact = loadArtifact(
    "./artifacts/contracts/quickswap/UniswapV2Pair.sol/UniswapV2Pair.json",
  );
  const INIT_CODE_HASH = keccak256(pairArtifact.bytecode);
  console.log("   INIT_CODE_HASH:", INIT_CODE_HASH);
  console.log("");

  // ---- 4. Optional: create ASH / WCYBER pair ----
  let pairAddress: `0x${string}` | null = null;
  if (WCYBER_ADDRESS) {
    console.log("4. Creating initial pair ASH / WCYBER…");
    const hash = await walletClient.writeContract({
      address: factory.address,
      abi: factory.abi,
      functionName: "createPair",
      args: [ash.address, WCYBER_ADDRESS],
    });
    console.log("   tx:", hash);
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    pairAddress = (await publicClient.readContract({
      address: factory.address,
      abi: factory.abi,
      functionName: "getPair",
      args: [ash.address, WCYBER_ADDRESS],
    })) as `0x${string}`;
    console.log("   pair:", pairAddress);
    console.log("   gas used:", receipt.gasUsed.toString());
    console.log("");
  } else {
    console.log("4. Skipping initial pair (WCYBER_ADDRESS not set)");
    console.log("");
  }

  // ---- Summary ----
  const summary = {
    chainId: 49406,
    chainName: "Cyberia",
    rpc: RPC_URL,
    deployer: account.address,
    ASH: ash.address,
    UniswapV2Factory: factory.address,
    feeToSetter: account.address,
    INIT_CODE_HASH,
    ashMinter: ASH_MINTER,
    ashPremintRecipient: ASH_PREMINT_RECIPIENT,
    initialPair: pairAddress,
    timestamp: new Date().toISOString(),
  };

  console.log("═══════════════════════════════════════════════════════════════");
  console.log("                      DEPLOYMENT SUMMARY                      ");
  console.log("═══════════════════════════════════════════════════════════════");
  console.log(JSON.stringify(summary, null, 2));
  console.log("═══════════════════════════════════════════════════════════════");

  // Persist summary
  const outDir = path.resolve("./deployments");
  fs.mkdirSync(outDir, { recursive: true });
  const outFile = path.join(outDir, "cyberia-quickswap.json");
  fs.writeFileSync(outFile, JSON.stringify(summary, null, 2));
  console.log(`\nSaved to ${outFile}`);

  console.log("\nNext steps:");
  console.log("  • Use INIT_CODE_HASH in interface-v2 SDK constants (e.g., @uniswap/sdk fork)");
  console.log("  • Add Factory address to interface-v2 chain config");
  console.log("  • If needed, create ASH/WCYBER pair by setting WCYBER_ADDRESS and re-running");
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
