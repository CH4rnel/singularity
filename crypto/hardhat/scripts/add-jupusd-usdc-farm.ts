/**
 * Adds the JupUSD/USDC LP farm pool (pid 22) to the MasterChef on Cyberia,
 * with allocPoint 100 — the same weight every active farm has.
 *
 * The QuickSwap V2 pair is created on the factory first if it does not exist
 * yet (a farm can point at an empty pair; stakers appear once liquidity is
 * added).
 *
 * Usage:
 *   npx hardhat run scripts/add-jupusd-usdc-farm.ts --network cyberia
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  parseAbi,
  zeroAddress,
  type Abi,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

const MASTERCHEF_ADDRESS =
  (process.env.MASTERCHEF_ADDRESS as `0x${string}` | undefined) ??
  "0xd540DEa828567160FFDe5e792ca359aDD1f6B03D";
const FACTORY_ADDRESS = "0xB0aC30907c04b61F1482e62eA66eF4562a690917" as const;
const JUPUSD_ADDRESS = "0x03EB2fb8473C0370c8F6463efEE5f5Cf4EC011c7" as const;
const USDC_ADDRESS = "0xdc25597B19799010047F17e9591EFE08EFd40077" as const;
const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

/** First free pid at the time of writing. Guards against pid drift. */
const EXPECTED_PID = 22;
/** Same weight as every other active farm — equal ASH share. */
const ALLOC_POINT = 100n;

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

const FACTORY_ABI = parseAbi([
  "function getPair(address tokenA, address tokenB) view returns (address)",
  "function createPair(address tokenA, address tokenB) returns (address)",
]);

const MASTERCHEF_ABI = JSON.parse(
  fs.readFileSync(
    path.resolve("./artifacts/contracts/MasterChef.sol/MasterChef.json"),
    "utf8",
  ),
).abi as Abi;

async function getPair(): Promise<`0x${string}`> {
  return (await publicClient.readContract({
    address: FACTORY_ADDRESS,
    abi: FACTORY_ABI,
    functionName: "getPair",
    args: [JUPUSD_ADDRESS, USDC_ADDRESS],
  })) as `0x${string}`;
}

async function main() {
  console.log("=== Add JupUSD/USDC LP farm pool ===");
  console.log("MasterChef:", MASTERCHEF_ADDRESS);
  console.log("Factory:   ", FACTORY_ADDRESS);
  console.log("Caller:    ", account.address);
  console.log("RPC:       ", RPC_URL);

  const owner = await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "owner",
  });
  if ((owner as string).toLowerCase() !== account.address.toLowerCase()) {
    throw new Error(`Caller ${account.address} is not the owner (${owner})`);
  }

  // Create the pair if it does not exist yet.
  let pair = await getPair();
  if (pair === zeroAddress) {
    console.log("\n[createPair] JupUSD/USDC pair does not exist — creating...");
    const hash = await walletClient.writeContract({
      address: FACTORY_ADDRESS,
      abi: FACTORY_ABI,
      functionName: "createPair",
      args: [JUPUSD_ADDRESS, USDC_ADDRESS],
    });
    console.log("  tx:", hash);
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    if (receipt.status !== "success") {
      throw new Error(`createPair reverted (tx: ${hash})`);
    }
    pair = await getPair();
    if (pair === zeroAddress) throw new Error("Pair still zero after createPair");
  }
  console.log("\nJupUSD/USDC pair:", pair);

  const poolLengthBefore = await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "poolLength",
  });
  console.log("Current pool count:", poolLengthBefore);
  const nextPid = Number(poolLengthBefore as bigint);
  if (nextPid !== EXPECTED_PID) {
    throw new Error(
      `Expected next pid ${EXPECTED_PID}, but poolLength is ${nextPid}. Aborting to avoid pid drift.`,
    );
  }

  console.log(`\n[add] JupUSD/USDC LP (pid ${EXPECTED_PID})`);
  console.log("  lpToken:    ", pair);
  console.log("  allocPoint: ", ALLOC_POINT.toString());
  console.log("  withUpdate: ", true);

  const hash = await walletClient.writeContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "add",
    args: [ALLOC_POINT, pair, true],
  });
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    throw new Error(`Transaction reverted (tx: ${hash})`);
  }
  console.log("  gas used:", receipt.gasUsed.toString());

  const poolLengthAfter = await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "poolLength",
  });
  const totalAlloc = await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "totalAllocPoint",
  });
  console.log("\n=== Done ===");
  console.log("Pool count now: ", poolLengthAfter);
  console.log("totalAllocPoint:", totalAlloc);
  console.log(
    `\nAdd pid ${EXPECTED_PID} with lpToken ${pair} to constants/ritualFarms.ts in the ritual frontend.`,
  );
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
