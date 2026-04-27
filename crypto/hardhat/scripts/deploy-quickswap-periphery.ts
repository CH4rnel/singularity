/**
 * Deploy supporting contracts for the QuickSwap V2 stack on Cyberia:
 *   1. Multicall3       — canonical MakerDAO multicall (required by interface-v2)
 *   2. WCYBER           — wrapped native (WETH9 pattern for CYBER)
 *   3. UniswapV2Router02 — standard Uniswap V2 Router (bound to our Factory + WCYBER)
 *
 * Output is merged into deployments/cyberia-quickswap.json.
 *
 * Note: UniswapV2Router02 uses UniswapV2Library.pairFor which has the init code
 * hash baked into bytecode. We pre-edited that hash in
 *   contracts/quickswap-periphery/libraries/UniswapV2Library.sol:24
 * to 0x927cde…094580 (the Cyberia pair init code hash matching our Factory).
 *
 * Usage:
 *   npx hardhat compile
 *   npx tsx scripts/deploy-quickswap-periphery.ts
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  parseAbi,
  type Abi,
  type Hex,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

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

function loadArtifact(p: string) {
  const art = JSON.parse(fs.readFileSync(path.resolve(p), "utf8"));
  return {
    abi: art.abi as Abi,
    bytecode: art.bytecode as Hex,
    contractName: art.contractName as string,
  };
}

async function deploy(artifactPath: string, args: unknown[] = []) {
  const a = loadArtifact(artifactPath);
  console.log(`  deploying ${a.contractName}…`);
  const hash = await walletClient.deployContract({
    abi: a.abi,
    bytecode: a.bytecode,
    args,
  });
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    throw new Error(`${a.contractName} deployment reverted (gas ${receipt.gasUsed})`);
  }
  if (!receipt.contractAddress) throw new Error(`${a.contractName}: no contract address`);
  const code = await publicClient.getCode({ address: receipt.contractAddress });
  if (!code || code === "0x") throw new Error(`${a.contractName}: no code`);
  console.log("  address:", receipt.contractAddress);
  console.log("  gas used:", receipt.gasUsed);
  return { address: receipt.contractAddress, abi: a.abi };
}

async function main() {
  console.log("=== QuickSwap periphery deployment on Cyberia (49406) ===");
  console.log("RPC:", RPC_URL);
  const bal = await publicClient.getBalance({ address: account.address });
  console.log(`Deployer …${account.address.slice(-6)}  balance: ${(Number(bal) / 1e18).toFixed(6)} CYBER`);
  if (bal === 0n) throw new Error("Deployer has zero balance");
  console.log("");

  // Load existing deployment record
  const outFile = path.resolve("./deployments/cyberia-quickswap.json");
  if (!fs.existsSync(outFile)) {
    throw new Error(
      "cyberia-quickswap.json not found — run deploy-quickswap.ts first (ASH + Factory).",
    );
  }
  const summary = JSON.parse(fs.readFileSync(outFile, "utf8"));
  const FACTORY = summary.UniswapV2Factory as `0x${string}`;
  console.log("Existing Factory:", FACTORY);
  console.log("Existing ASH:    ", summary.ASH);
  console.log("");

  // ---- 1. Multicall3 ----
  console.log("1. Deploying Multicall3…");
  const multicall = await deploy("./artifacts/contracts/Multicall3.sol/Multicall3.json", []);
  // sanity: call getBlockNumber
  const mc3Bn = await publicClient.readContract({
    address: multicall.address,
    abi: parseAbi(["function getBlockNumber() view returns (uint256)"]),
    functionName: "getBlockNumber",
  });
  console.log("  getBlockNumber ->", mc3Bn);
  console.log("");

  // ---- 2. WCYBER ----
  console.log("2. Deploying WCYBER…");
  const wcyber = await deploy("./artifacts/contracts/WCYBER.sol/WCYBER.json", []);
  const wcyberName = await publicClient.readContract({
    address: wcyber.address,
    abi: parseAbi(["function name() view returns (string)", "function symbol() view returns (string)", "function decimals() view returns (uint8)"]),
    functionName: "name",
  });
  const wcyberSym = await publicClient.readContract({
    address: wcyber.address,
    abi: parseAbi(["function symbol() view returns (string)"]),
    functionName: "symbol",
  });
  console.log(`  name: ${wcyberName}  symbol: ${wcyberSym}`);
  console.log("");

  // ---- 3. UniswapV2Router02 ----
  console.log("3. Deploying UniswapV2Router02…");
  console.log("   factory:", FACTORY);
  console.log("   WETH:   ", wcyber.address);
  const router = await deploy(
    "./artifacts/contracts/quickswap-periphery/UniswapV2Router02.sol/UniswapV2Router02.json",
    [FACTORY, wcyber.address],
  );
  // sanity: factory() and WETH()
  const [factoryCheck, wethCheck] = await Promise.all([
    publicClient.readContract({
      address: router.address,
      abi: parseAbi(["function factory() view returns (address)"]),
      functionName: "factory",
    }),
    publicClient.readContract({
      address: router.address,
      abi: parseAbi(["function WETH() view returns (address)"]),
      functionName: "WETH",
    }),
  ]);
  console.log(`  router.factory() -> ${factoryCheck}  (match: ${factoryCheck.toLowerCase() === FACTORY.toLowerCase()})`);
  console.log(`  router.WETH()    -> ${wethCheck}  (match: ${wethCheck.toLowerCase() === wcyber.address.toLowerCase()})`);
  console.log("");

  // ---- Update summary ----
  summary.Multicall3 = multicall.address;
  summary.WCYBER = wcyber.address;
  summary.UniswapV2Router02 = router.address;
  summary.periphery_timestamp = new Date().toISOString();
  fs.writeFileSync(outFile, JSON.stringify(summary, null, 2));

  console.log("═══════════════════════════════════════════════════════════════");
  console.log("                   PERIPHERY DEPLOYMENT DONE                  ");
  console.log("═══════════════════════════════════════════════════════════════");
  console.log(JSON.stringify(summary, null, 2));
  console.log(`\nUpdated ${outFile}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
