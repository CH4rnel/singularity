/**
 * Deploys a TEST instance of Launchpad on Cyberia with minLiquidity = 1 CYBER.sol.
 *
 * Usage:
 *   npx hardhat run scripts/deploy-launchpad-test.ts --network cyberia
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

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const CYBER_SOL = "0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA" as `0x${string}`;
const ROUTER = "0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62" as `0x${string}`;
const MIN_LIQUIDITY = 1n * 10n ** 18n; // 1 CYBER.sol — TEST

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
    path.resolve("./artifacts/contracts/Launchpad.sol/Launchpad.json"),
    "utf8",
  ),
) as { abi: Abi; bytecode: Hex };

async function main() {
  console.log("=== Deploy Launchpad (TEST) ===");
  console.log("Deployer:    ", account.address);
  console.log("RPC:         ", RPC_URL);
  console.log("CYBER.sol:   ", CYBER_SOL);
  console.log("Router:      ", ROUTER);
  console.log("minLiquidity:", MIN_LIQUIDITY.toString(), "(1 * 1e18)");

  const hash = await walletClient.deployContract({
    abi: artifact.abi,
    bytecode: artifact.bytecode,
    args: [CYBER_SOL, ROUTER, MIN_LIQUIDITY],
  });
  console.log("\ndeploy tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success" || !receipt.contractAddress) {
    throw new Error("Deploy reverted");
  }

  const address = receipt.contractAddress;
  console.log("Launchpad:   ", address);
  console.log("gas used:    ", receipt.gasUsed.toString());

  const onchainMin = await publicClient.readContract({
    address,
    abi: artifact.abi,
    functionName: "minLiquidity",
  });
  console.log("On-chain minLiquidity():", onchainMin);

  const out = {
    chainId: 49406,
    rpc: RPC_URL,
    Launchpad: address,
    cyberSol: CYBER_SOL,
    router: ROUTER,
    minLiquidity: MIN_LIQUIDITY.toString(),
    deployer: account.address,
    deployTx: hash,
    timestamp: new Date().toISOString(),
    note: "TEST instance — minLiquidity = 1 CYBER.sol",
  };
  const outPath = path.resolve("./deployments/cyberia-launchpad-test.json");
  fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
  console.log("\nWrote:", outPath);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
