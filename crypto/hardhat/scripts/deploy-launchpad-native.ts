/**
 * Deploys LaunchpadNative (fair launches paid in native CYBER, min 10) on
 * Cyberia and registers the pre-existing LAIN and MINE tokens with their
 * WCYBER pairs so the launchpad UI lists them.
 *
 * Usage:
 *   npx hardhat run scripts/deploy-launchpad-native.ts --network cyberia
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

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const ROUTER = "0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62" as `0x${string}`;
const MIN_LIQUIDITY = parseEther("10"); // 10 native CYBER

// Pre-existing tokens to list, with their canonical WCYBER pairs
// (deployments/cyberia-ash-emission.json tokenFarmPools).
const REGISTER: { label: string; token: `0x${string}`; pair: `0x${string}` }[] = [
  {
    label: "LAIN",
    token: "0x05cd1AFd5b2DF3CCA6cEAb80CbC21168ec981E8B",
    pair: "0x9298d13f57D1e5bD14C443144b500aaa210a1175",
  },
  {
    label: "MINE",
    token: "0xD8c1f812ADd03ccdE8D3c7F86FeAD181980CD7Ec",
    pair: "0xF18bA050eFF63B2be2D244A423691D44BDDeF60d",
  },
];

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

const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const artifact = JSON.parse(
  fs.readFileSync(
    path.resolve("./artifacts/contracts/LaunchpadNative.sol/LaunchpadNative.json"),
    "utf8",
  ),
) as { abi: Abi; bytecode: Hex };

async function main() {
  console.log("=== Deploy LaunchpadNative ===");
  console.log("Deployer:    ", account.address);
  console.log("RPC:         ", RPC_URL);
  console.log("Router:      ", ROUTER);
  console.log("minLiquidity:", MIN_LIQUIDITY.toString(), "(10 native CYBER)");

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
  console.log("gas used:       ", receipt.gasUsed.toString());

  // Sanity-check immutables.
  const onchainWcyber = await publicClient.readContract({
    address,
    abi: artifact.abi,
    functionName: "wcyber",
  });
  const onchainRouter = await publicClient.readContract({
    address,
    abi: artifact.abi,
    functionName: "router",
  });
  const onchainMin = await publicClient.readContract({
    address,
    abi: artifact.abi,
    functionName: "minLiquidity",
  });
  console.log("\nOn-chain readback:");
  console.log("  wcyber():      ", onchainWcyber);
  console.log("  router():      ", onchainRouter);
  console.log("  minLiquidity():", onchainMin);

  const registered: Record<string, { token: string; pair: string; tx: string }> = {};
  for (const r of REGISTER) {
    const txHash = await walletClient.writeContract({
      address,
      abi: artifact.abi,
      functionName: "registerToken",
      args: [r.token, r.pair],
    });
    const rr = await publicClient.waitForTransactionReceipt({ hash: txHash });
    if (rr.status !== "success") throw new Error(`registerToken(${r.label}) reverted`);
    console.log(`registered ${r.label}: token=${r.token} pair=${r.pair} tx=${txHash}`);
    registered[r.label] = { token: r.token, pair: r.pair, tx: txHash };
  }

  const out = {
    chainId: 49406,
    rpc: RPC_URL,
    LaunchpadNative: address,
    router: ROUTER,
    wcyber: onchainWcyber,
    minLiquidity: MIN_LIQUIDITY.toString(),
    registered,
    deployer: account.address,
    deployTx: hash,
    timestamp: new Date().toISOString(),
  };
  const outPath = path.resolve("./deployments/cyberia-launchpad-native.json");
  fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
  console.log("\nWrote:", outPath);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
