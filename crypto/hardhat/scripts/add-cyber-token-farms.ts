/**
 * Adds a batch of LP farm pools to the MasterChef on Cyberia, one per DEX pair
 * listed below, each with allocPoint 100 (the same weight every active farm
 * already has, so they earn the same share of ASH emissions).
 *
 * Pools cannot be removed from the chef, so pids are permanent. To keep the
 * frontend's pid->lpToken mapping (constants/ritualFarms.ts) correct, this
 * script:
 *   - expects the first new pool to land at pid 8 (START_PID), and
 *   - is resumable: if a previous run already added some of these pools, it
 *     verifies the on-chain prefix matches POOLS and continues from where it
 *     stopped, so a mid-run failure can be re-run safely without pid drift.
 *
 * Usage:
 *   npx hardhat run scripts/add-cyber-token-farms.ts --network cyberia
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

/** First pid these pools should occupy. Guards against pid drift. */
const START_PID = 8;
/** Same weight as every other active farm — equal ASH share. */
const ALLOC_POINT = 100n;

/**
 * Pools to add, in pid order (START_PID, START_PID+1, ...). lpToken is the
 * QuickSwap V2 pair address on Cyberia (factory
 * 0xB0aC30907c04b61F1482e62eA66eF4562a690917). Keep this list in sync with
 * constants/ritualFarms.ts in the ritual frontend.
 */
const POOLS: { label: string; lpToken: `0x${string}` }[] = [
  { label: "MINE/LAIN LP",     lpToken: "0x86199CD222E0d3412b77323772B4D1cFd0a35242" },
  { label: "CYBER/HATCHER LP", lpToken: "0x2AC6779E02ED515360a984A52441ba77fa7e3cAB" },
  { label: "CYBER/LAIN LP",    lpToken: "0x9298d13f57D1e5bD14C443144b500aaa210a1175" },
  { label: "CYBER/MINE LP",    lpToken: "0xF18bA050eFF63B2be2D244A423691D44BDDeF60d" },
  { label: "CYBER/GOAL LP",    lpToken: "0x7598B5A2421E7A9cA443368DB939Ec860Dc9d536" },
  { label: "YTN/CYBER LP",     lpToken: "0xda176FEd5D6D1e1eB8C37556A01678f9e18B941F" },
  { label: "KRSQ/CYBER LP",    lpToken: "0x828b0c5D46CfDf4Adc78E2cB4139547aca56845c" },
  { label: "TRX/CYBER LP",     lpToken: "0xb6184A51C0fAa2810D4A8Eb8C25bB18CB0bD4E33" },
  { label: "CYBER/XMR LP",     lpToken: "0x86dC072E44556c4F5cE948b94368b6631bCB0332" },
  { label: "LTC/CYBER LP",     lpToken: "0x1DF2329f6b9E94f9fdB5D76ea006DffF70C378Ec" },
  { label: "CYBER/BTC LP",     lpToken: "0x144211C1476e1Da2eD55B19ff25d1ea18AA75aBC" },
  { label: "CYBER/SILVER LP",  lpToken: "0xF94a92ED03fB44578f5246920D7fc1463Df2cF6D" },
  { label: "CYBER/ETH LP",     lpToken: "0x64252D0d9D9d2f27146c01CA15dD8680ACFFdEa2" },
  // BTC/ETH pair is not created/seeded on-chain yet. lpToken is its deterministic
  // CREATE2 pair address; the add loop below skips it gracefully until liquidity
  // exists, then a re-run appends it as pid 21.
  { label: "BTC/ETH LP",       lpToken: "0x28521507A465Da62B73348C9FBB5561cCB1f311c" },
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

const MASTERCHEF_ABI = JSON.parse(
  fs.readFileSync(
    path.resolve("./artifacts/contracts/MasterChef.sol/MasterChef.json"),
    "utf8",
  ),
).abi as Abi;

async function poolLength(): Promise<number> {
  return Number(
    (await publicClient.readContract({
      address: MASTERCHEF_ADDRESS,
      abi: MASTERCHEF_ABI,
      functionName: "poolLength",
    })) as bigint,
  );
}

async function lpTokenAt(pid: number): Promise<string> {
  const info = (await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "poolInfo",
    args: [BigInt(pid)],
  })) as readonly [string, bigint, bigint, bigint];
  return info[0];
}

async function main() {
  console.log("=== Add CYBER token LP farm pools ===");
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

  const len = await poolLength();
  console.log("\nCurrent pool count:", len);
  if (len < START_PID) {
    throw new Error(
      `poolLength is ${len} but START_PID is ${START_PID}; ` +
        `earlier pools are missing. Aborting to avoid pid drift.`,
    );
  }
  if (len > START_PID + POOLS.length) {
    throw new Error(
      `poolLength is ${len}, past the end of this batch (${START_PID + POOLS.length}). ` +
        `Nothing to do, or the list is stale.`,
    );
  }

  // Verify any already-added pools in our pid range match POOLS (resumability).
  for (let pid = START_PID; pid < len; pid++) {
    const expected = POOLS[pid - START_PID];
    const actual = await lpTokenAt(pid);
    if (actual.toLowerCase() !== expected.lpToken.toLowerCase()) {
      throw new Error(
        `pid ${pid} already holds ${actual}, expected ${expected.lpToken} ` +
          `(${expected.label}). On-chain pools diverge from this script — aborting.`,
      );
    }
    console.log(`  pid ${pid}: already added (${expected.label}) — skipping`);
  }

  // Add the remaining pools.
  for (let pid = len; pid < START_PID + POOLS.length; pid++) {
    const pool = POOLS[pid - START_PID];

    // Guard: never add a farm for an address with no contract deployed.
    const code = await publicClient.getBytecode({ address: pool.lpToken });
    if (!code || code === "0x") {
      // A missing pair at the very tail of the list is an expected "pending
      // liquidity" case: stop cleanly so everything before it still lands, and
      // a later re-run appends this pool once the pair is seeded. A gap earlier
      // in the list means something is wrong, so abort.
      const isTrailing = pid === START_PID + POOLS.length - 1;
      if (isTrailing) {
        console.log(
          `\n[skip] pid ${pid} — ${pool.label}: pair not created/seeded yet ` +
            `(${pool.lpToken}).\n       Stopping here; re-run this script after ` +
            `adding liquidity and it will be appended as pid ${pid}.`,
        );
        break;
      }
      throw new Error(
        `No contract at ${pool.lpToken} (${pool.label}); is the pair created?`,
      );
    }

    console.log(`\n[add] pid ${pid} — ${pool.label}`);
    console.log("  lpToken:    ", pool.lpToken);
    console.log("  allocPoint: ", ALLOC_POINT.toString());

    const hash = await walletClient.writeContract({
      address: MASTERCHEF_ADDRESS,
      abi: MASTERCHEF_ABI,
      functionName: "add",
      args: [ALLOC_POINT, pool.lpToken, true],
    });
    console.log("  tx:", hash);
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    if (receipt.status !== "success") {
      throw new Error(`Transaction reverted (tx: ${hash})`);
    }
    console.log("  gas used:", receipt.gasUsed.toString());

    // Confirm it landed at the expected pid.
    const newLen = await poolLength();
    if (newLen !== pid + 1) {
      throw new Error(
        `Expected poolLength ${pid + 1} after add, got ${newLen}. Aborting.`,
      );
    }
  }

  const totalAlloc = await publicClient.readContract({
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "totalAllocPoint",
  });
  console.log("\n=== Done ===");
  console.log("Pool count now: ", await poolLength());
  console.log("totalAllocPoint:", (totalAlloc as bigint).toString());
  console.log("\npid -> lpToken map (for constants/ritualFarms.ts):");
  POOLS.forEach((p, i) =>
    console.log(`  pid ${START_PID + i}: ${p.lpToken}  // ${p.label}`),
  );
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
