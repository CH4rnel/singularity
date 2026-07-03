/**
 * Adds the CYBER/SOL LP farm pool (pid 23) to the MasterChef on Cyberia,
 * with allocPoint 100 — the same weight every active farm has.
 *
 * The QuickSwap V2 pair (WCYBER/SOL) already exists on the factory:
 * 0x15cB7289af5293ca4BeFB368Bde50D441B95b1E6.
 *
 * Usage:
 *   npx hardhat run scripts/add-cyber-sol-farm.ts --network cyberia
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  type Abi,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

const MASTERCHEF_ADDRESS =
  (process.env.MASTERCHEF_ADDRESS as `0x${string}` | undefined) ??
  "0xd540DEa828567160FFDe5e792ca359aDD1f6B03D";
const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

/** First free pid at the time of writing. Guards against pid drift. */
const EXPECTED_PID = 23;

const NEW_POOL = {
  label: `CYBER/SOL LP (pid ${EXPECTED_PID})`,
  /** WCYBER/SOL pair on the QuickSwap V2 factory. */
  lpToken: "0x15cB7289af5293ca4BeFB368Bde50D441B95b1E6" as `0x${string}`,
  /** Same weight as every other active farm — equal ASH share. */
  allocPoint: 100n,
};

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

const MASTERCHEF_ABI = JSON.parse(
  fs.readFileSync(
    path.resolve("./artifacts/contracts/MasterChef.sol/MasterChef.json"),
    "utf8",
  ),
).abi as Abi;

async function main() {
  console.log("=== Add CYBER/SOL LP farm pool ===");
  console.log("MasterChef:", MASTERCHEF_ADDRESS);
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

  const code = await publicClient.getBytecode({ address: NEW_POOL.lpToken });
  if (!code || code === "0x") {
    throw new Error(`No contract at ${NEW_POOL.lpToken}; is the pair created?`);
  }

  const poolLengthBefore = await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "poolLength",
  });
  console.log("\nCurrent pool count:", poolLengthBefore);
  const nextPid = Number(poolLengthBefore as bigint);
  if (nextPid !== EXPECTED_PID) {
    throw new Error(
      `Expected next pid ${EXPECTED_PID}, but poolLength is ${nextPid}. Aborting to avoid pid drift.`,
    );
  }

  console.log(`\n[add] ${NEW_POOL.label}`);
  console.log("  lpToken:    ", NEW_POOL.lpToken);
  console.log("  allocPoint: ", NEW_POOL.allocPoint.toString());
  console.log("  withUpdate: ", true);

  const hash = await walletClient.writeContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "add",
    args: [NEW_POOL.allocPoint, NEW_POOL.lpToken, true],
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
    `\nAdd pid ${EXPECTED_PID} with lpToken ${NEW_POOL.lpToken} to constants/ritualFarms.ts in the ritual frontend.`,
  );
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
