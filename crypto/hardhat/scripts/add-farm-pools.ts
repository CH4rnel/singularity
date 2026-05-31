/**
 * Adds 5 new staking pools to the MasterChef on Cyberia (chainId 49406).
 *
 * Pools are appended in order — resulting pids must match ritualFarms.ts:
 *   pid 2  CYBER.sol        0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA
 *   pid 3  CYBER.sol/CYBER  0x7D8e23e33c6680D5C45CA2deb8A85CcA0fe283F4
 *   pid 4  USDT/USDC        0x4491A41C7D75c15cEbC7a321e392fcD57ADeABe8
 *   pid 5  CYBER/USDT       0x07b935a3Ba330Cb3Bd56B43F1032b57d3Ae0e04f
 *   pid 6  CYBER/USDC       0x79B039b5E146E878683039D9387E212afc9FFC85
 *
 * Usage:
 *   npx hardhat run scripts/add-farm-pools.ts --network cyberia
 *
 * Env vars (in .env):
 *   DEPLOYER_PK        — owner private key
 *   CYBERIA_RPC_URL    — optional, defaults to https://rpc.cyberia.church
 *   MASTERCHEF_ADDRESS — optional override (default: on-chain address from ritualFarms)
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

// ── config ────────────────────────────────────────────────────────────────────

const MASTERCHEF_ADDRESS =
  (process.env.MASTERCHEF_ADDRESS as `0x${string}` | undefined) ??
  "0xd540DEa828567160FFDe5e792ca359aDD1f6B03D";

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");
const pk = (
  DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`
) as `0x${string}`;
const account = privateKeyToAccount(pk);

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const walletClient = createWalletClient({
  chain,
  transport: http(RPC_URL),
  account,
});
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

// ── pools to add ──────────────────────────────────────────────────────────────

const NEW_POOLS: { label: string; lpToken: `0x${string}`; allocPoint: bigint }[] = [
  {
    label: "CYBER.sol (pid 2)",
    lpToken: "0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA",
    allocPoint: 100n,
  },
  {
    label: "CYBER.sol/CYBER LP (pid 3)",
    lpToken: "0x7D8e23e33c6680D5C45CA2deb8A85CcA0fe283F4",
    allocPoint: 100n,
  },
  {
    label: "USDT/USDC LP (pid 4)",
    lpToken: "0x4491A41C7D75c15cEbC7a321e392fcD57ADeABe8",
    allocPoint: 100n,
  },
  {
    label: "CYBER/USDT LP (pid 5)",
    lpToken: "0x07b935a3Ba330Cb3Bd56B43F1032b57d3Ae0e04f",
    allocPoint: 100n,
  },
  {
    label: "CYBER/USDC LP (pid 6)",
    lpToken: "0x79B039b5E146E878683039D9387E212afc9FFC85",
    allocPoint: 100n,
  },
];

// ── ABI (only what we need) ───────────────────────────────────────────────────

const MASTERCHEF_ABI = JSON.parse(
  fs.readFileSync(
    path.resolve("./artifacts/contracts/MasterChef.sol/MasterChef.json"),
    "utf8",
  ),
).abi as Abi;

// ── main ──────────────────────────────────────────────────────────────────────

async function main() {
  console.log("=== Add farm pools to MasterChef ===");
  console.log("MasterChef:", MASTERCHEF_ADDRESS);
  console.log("Caller:    ", account.address);
  console.log("RPC:       ", RPC_URL);

  // Verify caller is owner.
  const owner = await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "owner",
  });
  if ((owner as string).toLowerCase() !== account.address.toLowerCase()) {
    throw new Error(`Caller ${account.address} is not the owner (${owner})`);
  }

  // Current pool count — sanity check that pids will land where expected.
  const poolLengthBefore = await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "poolLength",
  });
  console.log("\nCurrent pool count:", poolLengthBefore);
  const expectedNextPid = Number(poolLengthBefore as bigint);
  if (expectedNextPid !== 2) {
    console.warn(
      `Warning: expected 2 existing pools (pid 0 & 1) but found ${expectedNextPid}.`,
      "Verify pid assignments in ritualFarms.ts match.",
    );
  }

  // Update allocPoint for existing pools (pid 0 and pid 1) to 100 each.
  const EXISTING_POOLS: { pid: number; label: string; allocPoint: bigint }[] = [
    { pid: 0, label: "ASH (pid 0)",          allocPoint: 100n },
    { pid: 1, label: "ASH/WCYBER LP (pid 1)", allocPoint: 100n },
  ];

  for (const pool of EXISTING_POOLS) {
    console.log(`\n[set] ${pool.label} → allocPoint ${pool.allocPoint}`);
    const hash = await walletClient.writeContract({
      address: MASTERCHEF_ADDRESS,
      abi: MASTERCHEF_ABI,
      functionName: "set",
      args: [BigInt(pool.pid), pool.allocPoint, false],
    });
    console.log("  tx:", hash);
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    if (receipt.status !== "success") {
      throw new Error(`set() reverted for ${pool.label} (tx: ${hash})`);
    }
    console.log("  gas used:", receipt.gasUsed.toString());
  }

  // Add pools one by one. withUpdate=true on the last call to sync accRewardPerShare.
  for (let i = 0; i < NEW_POOLS.length; i++) {
    const pool = NEW_POOLS[i];
    const isLast = i === NEW_POOLS.length - 1;
    console.log(`\n[${i + 1}/${NEW_POOLS.length}] add ${pool.label}`);
    console.log("  lpToken:    ", pool.lpToken);
    console.log("  allocPoint: ", pool.allocPoint.toString());
    console.log("  withUpdate: ", isLast);

    const hash = await walletClient.writeContract({
      address: MASTERCHEF_ADDRESS,
      abi: MASTERCHEF_ABI,
      functionName: "add",
      args: [pool.allocPoint, pool.lpToken, isLast],
    });
    console.log("  tx:", hash);
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    if (receipt.status !== "success") {
      throw new Error(`Transaction reverted for pool ${pool.label} (tx: ${hash})`);
    }
    console.log("  gas used:", receipt.gasUsed.toString());
  }

  // Final pool count.
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
  console.log("Pool count now:    ", poolLengthAfter);
  console.log("totalAllocPoint:   ", totalAlloc);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
