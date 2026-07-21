/**
 * Adds the SPY/CYBER LP farm pool (pid 25) to the MasterChef on Cyberia,
 * with allocPoint 100 — the same weight as every other active farm.
 *
 * Usage:
 *   npx hardhat run scripts/add-spy-cyber-farm.ts --network cyberia
 */

import "dotenv/config";
import {
  createPublicClient,
  createWalletClient,
  http,
  parseAbi,
  type Abi,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "node:fs";
import * as path from "node:path";

const MASTERCHEF_ADDRESS =
  (process.env.MASTERCHEF_ADDRESS as `0x${string}` | undefined) ??
  "0xd540DEa828567160FFDe5e792ca359aDD1f6B03D";
const LP_TOKEN = "0x50d60d11cd42c250901d1bD12351A20E3c781b27" as const;
const WCYBER_ADDRESS = "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9" as const;
const SPY_ADDRESS = "0x1241FC4F06DB7268243D9439ef56B7a2708DC096" as const;
const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";
const EXPECTED_PID = 25;
const ALLOC_POINT = 100n;

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

const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });
const walletClient = createWalletClient({
  chain,
  transport: http(RPC_URL),
  account,
});

const MASTERCHEF_ABI = JSON.parse(
  fs.readFileSync(
    path.resolve("./artifacts/contracts/MasterChef.sol/MasterChef.json"),
    "utf8",
  ),
).abi as Abi;

const PAIR_ABI = parseAbi([
  "function token0() view returns (address)",
  "function token1() view returns (address)",
  "function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)",
]);

async function main() {
  console.log("=== Add SPY/CYBER LP farm pool ===");
  console.log("MasterChef:", MASTERCHEF_ADDRESS);
  console.log("LP token:  ", LP_TOKEN);
  console.log("Caller:    ", account.address);
  console.log("RPC:       ", RPC_URL);

  const [owner, poolLengthBefore, token0, token1, reserves] = await Promise.all(
    [
      publicClient.readContract({
        address: MASTERCHEF_ADDRESS,
        abi: MASTERCHEF_ABI,
        functionName: "owner",
      }) as Promise<string>,
      publicClient.readContract({
        address: MASTERCHEF_ADDRESS,
        abi: MASTERCHEF_ABI,
        functionName: "poolLength",
      }) as Promise<bigint>,
      publicClient.readContract({
        address: LP_TOKEN,
        abi: PAIR_ABI,
        functionName: "token0",
      }),
      publicClient.readContract({
        address: LP_TOKEN,
        abi: PAIR_ABI,
        functionName: "token1",
      }),
      publicClient.readContract({
        address: LP_TOKEN,
        abi: PAIR_ABI,
        functionName: "getReserves",
      }),
    ],
  );

  if (owner.toLowerCase() !== account.address.toLowerCase()) {
    throw new Error(`Caller ${account.address} is not the owner (${owner})`);
  }

  const pairTokens = new Set([token0.toLowerCase(), token1.toLowerCase()]);
  if (
    !pairTokens.has(WCYBER_ADDRESS.toLowerCase()) ||
    !pairTokens.has(SPY_ADDRESS.toLowerCase())
  ) {
    throw new Error(`LP token ${LP_TOKEN} is not the expected SPY/WCYBER pair`);
  }
  if (reserves[0] === 0n || reserves[1] === 0n) {
    throw new Error(`SPY/WCYBER pair ${LP_TOKEN} has no liquidity`);
  }

  for (let pid = 0; pid < Number(poolLengthBefore); pid++) {
    const info = (await publicClient.readContract({
      address: MASTERCHEF_ADDRESS,
      abi: MASTERCHEF_ABI,
      functionName: "poolInfo",
      args: [BigInt(pid)],
    })) as readonly [string, bigint, bigint, bigint];

    if (info[0].toLowerCase() === LP_TOKEN.toLowerCase()) {
      if (pid === EXPECTED_PID && info[1] === ALLOC_POINT) {
        console.log(`Pool already exists at pid ${pid}; nothing to do.`);
        return;
      }

      throw new Error(
        `LP token ${LP_TOKEN} already exists at unexpected pid ${pid}`,
      );
    }
  }

  const nextPid = Number(poolLengthBefore);
  if (nextPid !== EXPECTED_PID) {
    throw new Error(
      `Expected next pid ${EXPECTED_PID}, but poolLength is ${nextPid}. Aborting to avoid pid drift.`,
    );
  }

  console.log(`\n[add] SPY/CYBER LP (pid ${EXPECTED_PID})`);
  console.log("  allocPoint: ", ALLOC_POINT.toString());
  console.log("  reserve0:   ", reserves[0].toString());
  console.log("  reserve1:   ", reserves[1].toString());

  const { request } = await publicClient.simulateContract({
    account,
    address: MASTERCHEF_ADDRESS,
    abi: MASTERCHEF_ABI,
    functionName: "add",
    args: [ALLOC_POINT, LP_TOKEN, true],
  });
  const hash = await walletClient.writeContract(request);
  console.log("  tx:", hash);

  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    throw new Error(`Transaction reverted (tx: ${hash})`);
  }

  const [poolLengthAfter, poolInfo, totalAlloc] = await Promise.all([
    publicClient.readContract({
      address: MASTERCHEF_ADDRESS,
      abi: MASTERCHEF_ABI,
      functionName: "poolLength",
    }) as Promise<bigint>,
    publicClient.readContract({
      address: MASTERCHEF_ADDRESS,
      abi: MASTERCHEF_ABI,
      functionName: "poolInfo",
      args: [BigInt(EXPECTED_PID)],
    }) as Promise<readonly [string, bigint, bigint, bigint]>,
    publicClient.readContract({
      address: MASTERCHEF_ADDRESS,
      abi: MASTERCHEF_ABI,
      functionName: "totalAllocPoint",
    }) as Promise<bigint>,
  ]);

  if (
    poolLengthAfter !== BigInt(EXPECTED_PID + 1) ||
    poolInfo[0].toLowerCase() !== LP_TOKEN.toLowerCase() ||
    poolInfo[1] !== ALLOC_POINT
  ) {
    throw new Error("On-chain pool verification failed after transaction");
  }

  console.log("\n=== Done ===");
  console.log("Pool count now: ", poolLengthAfter.toString());
  console.log("totalAllocPoint:", totalAlloc.toString());
  console.log("gas used:       ", receipt.gasUsed.toString());
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
