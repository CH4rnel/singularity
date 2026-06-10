/**
 * Deploy fresh Ash token + MasterChef and wire them together for daily emission
 * of 437 ASH split between LP-staking and ASH-staking pools.
 *
 * Sequence:
 *   1. Deploy Ash (premint 1 ASH to deployer for initial liquidity).
 *   2. Deploy MasterChef(rewardToken=Ash, rewardPerBlock=437/86400 ASH, startBlock=now+10).
 *   3. ash.setMinter(masterChef) — emission flows exclusively through chef.
 *   4. Add ASH/WCYBER LP pool (allocPoint=80) if WCYBER pair address is provided.
 *   5. Add ASH solo pool (allocPoint=20) — staking ASH itself.
 *
 * Block time on Cyberia (polygon-edge) is ~1 s, so rewardPerBlock = rewardPerSecond.
 * 437 ASH / 86400 s ≈ 5_057_870_370_370_370 wei/block (≈436.99999999999994 ASH/day,
 * floor-division error ~5.7e-14 ASH/day — negligible).
 *
 * Env (.env):
 *   DEPLOYER_PK           — required
 *   CYBERIA_RPC_URL       — default https://rpc.cyberia.church
 *   ASH_PREMINT_RECIPIENT — default = deployer
 *   ASH_WCYBER_LP         — optional, address of existing ASH/WCYBER pair to add as pool
 *   START_BLOCK_OFFSET    — optional, blocks after current to start emission (default 10)
 *   LP_ALLOC              — alloc points for LP pool (default 80)
 *   ASH_ALLOC             — alloc points for ASH solo pool (default 20)
 *
 * Usage:
 *   npx hardhat compile
 *   npx tsx scripts/deploy-ash-and-chef.ts
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
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

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const ASH_PREMINT_RECIPIENT =
  (process.env.ASH_PREMINT_RECIPIENT as `0x${string}` | undefined) ?? account.address;
const ASH_WCYBER_LP = process.env.ASH_WCYBER_LP as `0x${string}` | undefined;
const START_BLOCK_OFFSET = BigInt(process.env.START_BLOCK_OFFSET ?? "10");
const LP_ALLOC = BigInt(process.env.LP_ALLOC ?? "80");
const ASH_ALLOC = BigInt(process.env.ASH_ALLOC ?? "20");

// 437 ASH/day ÷ 86400 blocks/day ≈ 5_057_870_370_370_370 wei/block (block ≈1 s).
const REWARD_PER_BLOCK = (437n * 10n ** 18n) / 86_400n;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function loadArtifact(p: string) {
  const artifact = JSON.parse(fs.readFileSync(path.resolve(p), "utf8"));
  return {
    abi: artifact.abi as Abi,
    bytecode: artifact.bytecode as Hex,
    contractName: artifact.contractName as string,
  };
}

async function deploy(artifactPath: string, args: unknown[] = []) {
  const art = loadArtifact(artifactPath);
  console.log(`  deploying ${art.contractName}…`);
  const hash = await walletClient.deployContract({
    abi: art.abi,
    bytecode: art.bytecode,
    args,
  });
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    throw new Error(`${art.contractName} deployment reverted (tx ${hash})`);
  }
  if (!receipt.contractAddress) {
    throw new Error(`${art.contractName} deployment has no address`);
  }
  console.log("  address:", receipt.contractAddress);
  console.log("  gas used:", receipt.gasUsed.toString());
  return { address: receipt.contractAddress, abi: art.abi };
}

async function send(
  address: `0x${string}`,
  abi: Abi,
  functionName: string,
  args: unknown[],
) {
  const hash = await walletClient.writeContract({ address, abi, functionName, args });
  console.log(`  ${functionName} tx:`, hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    throw new Error(`${functionName} reverted (tx ${hash})`);
  }
  return receipt;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  console.log("=== Deploy Ash + MasterChef on Cyberia (chainId 49406) ===");
  console.log("RPC:        ", RPC_URL);
  console.log("Deployer:   ", account.address);
  console.log("Premint to: ", ASH_PREMINT_RECIPIENT);
  console.log("RewardPerBlock:", REWARD_PER_BLOCK.toString(), "wei");
  console.log(
    "≈",
    Number(REWARD_PER_BLOCK) / 1e18,
    "ASH/block × 86400 blocks/day =",
    (Number(REWARD_PER_BLOCK) * 86400) / 1e18,
    "ASH/day",
  );

  const balance = await publicClient.getBalance({ address: account.address });
  console.log("Balance:", balance.toString(), "wei");
  if (balance === 0n) throw new Error("Deployer has zero balance");
  console.log("");

  // ---- 1. Ash ----
  console.log("1. Deploying Ash token…");
  const ash = await deploy(
    "./artifacts/contracts/Ash.sol/Ash.json",
    [ASH_PREMINT_RECIPIENT],
  );
  console.log("");

  // ---- 2. MasterChef ----
  console.log("2. Deploying MasterChef…");
  const blockNumber = await publicClient.getBlockNumber();
  const startBlock = blockNumber + START_BLOCK_OFFSET;
  console.log("   currentBlock:", blockNumber.toString());
  console.log("   startBlock:  ", startBlock.toString());
  const chef = await deploy(
    "./artifacts/contracts/MasterChef.sol/MasterChef.json",
    [ash.address, REWARD_PER_BLOCK, startBlock],
  );
  console.log("");

  // ---- 3. ash.setMinter(chef) ----
  console.log("3. Setting MasterChef as ASH minter…");
  await send(ash.address, ash.abi, "setMinter", [chef.address]);
  console.log("");

  // ---- 4. Add LP pool (if available) ----
  let lpPid: number | null = null;
  if (ASH_WCYBER_LP) {
    console.log(`4. Adding ASH/WCYBER LP pool (allocPoint=${LP_ALLOC})…`);
    await send(chef.address, chef.abi, "add", [LP_ALLOC, ASH_WCYBER_LP, false]);
    lpPid = 0;
    console.log("   pid:", lpPid);
  } else {
    console.log("4. Skipping LP pool (ASH_WCYBER_LP not set)");
  }
  console.log("");

  // ---- 5. Add ASH solo pool ----
  console.log(`5. Adding ASH solo pool (allocPoint=${ASH_ALLOC})…`);
  await send(chef.address, chef.abi, "add", [ASH_ALLOC, ash.address, false]);
  const ashPid = lpPid === null ? 0 : 1;
  console.log("   pid:", ashPid);
  console.log("");

  // ---- Summary ----
  const summary = {
    chainId: 49406,
    chainName: "Cyberia",
    rpc: RPC_URL,
    deployer: account.address,
    Ash: ash.address,
    MasterChef: chef.address,
    rewardPerBlock: REWARD_PER_BLOCK.toString(),
    rewardPerDayApprox: (Number(REWARD_PER_BLOCK) * 86400) / 1e18,
    startBlock: startBlock.toString(),
    pools: {
      lp: ASH_WCYBER_LP
        ? { pid: lpPid, lpToken: ASH_WCYBER_LP, allocPoint: LP_ALLOC.toString() }
        : null,
      ashSolo: { pid: ashPid, lpToken: ash.address, allocPoint: ASH_ALLOC.toString() },
    },
    totalAllocPoint: (
      (ASH_WCYBER_LP ? LP_ALLOC : 0n) + ASH_ALLOC
    ).toString(),
    timestamp: new Date().toISOString(),
  };

  console.log("═══════════════════════════════════════════════════════════════");
  console.log("                 ASH EMISSION DEPLOYMENT SUMMARY               ");
  console.log("═══════════════════════════════════════════════════════════════");
  console.log(JSON.stringify(summary, null, 2));
  console.log("═══════════════════════════════════════════════════════════════");

  const outDir = path.resolve("./deployments");
  fs.mkdirSync(outDir, { recursive: true });
  const outFile = path.join(outDir, "cyberia-ash-emission.json");
  fs.writeFileSync(outFile, JSON.stringify(summary, null, 2));
  console.log("\nSaved to", outFile);

  console.log("\nNext steps:");
  console.log("  • Create ASH/WCYBER pair via UniswapV2Factory using your 1 ASH premint");
  console.log("  • Provide initial liquidity (1 ASH + some CYBER)");
  console.log("  • Run again with ASH_WCYBER_LP=<pair> if you didn't supply it now");
  console.log("  • Tune emission via chef.setRewardPerBlock / chef.set(pid, allocPoint, true)");
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
