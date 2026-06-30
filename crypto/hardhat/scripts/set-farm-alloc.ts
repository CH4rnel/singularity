/**
 * Sets the allocPoint of a MasterChef farm pool on Cyberia. Pools cannot be
 * removed from the chef, so "deleting" a farm means zeroing its allocPoint:
 * the pool stops earning ASH (totalAllocPoint drops accordingly) while stakers
 * keep their LP and can still withdraw.
 *
 * Defaults to pid 7 (SOL/CYBER.sol LP) -> 0. Override with env vars.
 *
 * Usage:
 *   FARM_PID=7 FARM_ALLOC=0 npx hardhat run scripts/set-farm-alloc.ts --network cyberia
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

const PID = BigInt(process.env.FARM_PID ?? "7");
const ALLOC = BigInt(process.env.FARM_ALLOC ?? "0");

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
  console.log("=== Set farm allocPoint ===");
  console.log("MasterChef:", MASTERCHEF_ADDRESS);
  console.log("Caller:    ", account.address);
  console.log("RPC:       ", RPC_URL);
  console.log("pid:       ", PID.toString());
  console.log("newAlloc:  ", ALLOC.toString());

  const owner = await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "owner",
  });
  if ((owner as string).toLowerCase() !== account.address.toLowerCase()) {
    throw new Error(`Caller ${account.address} is not the owner (${owner})`);
  }

  const poolLength = (await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "poolLength",
  })) as bigint;
  if (PID >= poolLength) {
    throw new Error(`pid ${PID} out of range (poolLength is ${poolLength})`);
  }

  const before = (await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "poolInfo",
    args: [PID],
  })) as readonly [string, bigint, bigint, bigint];
  const totalBefore = (await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "totalAllocPoint",
  })) as bigint;
  console.log("\nCurrent pool:");
  console.log("  lpToken:    ", before[0]);
  console.log("  allocPoint: ", before[1].toString());
  console.log("  totalAlloc: ", totalBefore.toString());

  if (before[1] === ALLOC) {
    console.log("\nallocPoint already at target value; nothing to do.");
    return;
  }

  console.log("\n[set] withUpdate: true");
  const hash = await walletClient.writeContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "set",
    args: [PID, ALLOC, true],
  });
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    throw new Error(`Transaction reverted (tx: ${hash})`);
  }
  console.log("  gas used:", receipt.gasUsed.toString());

  const after = (await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "poolInfo",
    args: [PID],
  })) as readonly [string, bigint, bigint, bigint];
  const totalAfter = (await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "totalAllocPoint",
  })) as bigint;
  console.log("\n=== Done ===");
  console.log("allocPoint now: ", after[1].toString());
  console.log("totalAllocPoint:", totalAfter.toString());
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
