/**
 * Adds single-asset staking pools to the live ASH MasterChef for the /staking
 * page: native CYBER (staked as WCYBER — MasterChef only accepts ERC-20, the
 * site wraps/unwraps around deposit and withdrawal), HATCHER and ORBV.
 * Pools that already exist with the expected allocPoint are skipped, so the
 * script is safe to re-run.
 *
 * Usage:
 *   npx hardhat run scripts/add-cyber-solo-farm.ts --network cyberia
 *   DRY_RUN=true npx hardhat run scripts/add-cyber-solo-farm.ts --network cyberia
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

const MASTERCHEF = "0xd540DEa828567160FFDe5e792ca359aDD1f6B03D" as const;
const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";
const ALLOC_POINT = 100n;
const DRY_RUN = process.env.DRY_RUN === "true";

const SOLO_POOLS = [
  {
    symbol: "WCYBER",
    token: "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9" as const,
  },
  {
    symbol: "HATCHER",
    token: "0x621021F18b6404123f98b1395c418868418ACF36" as const,
  },
  {
    symbol: "ORBV",
    token: "0x19E92D8475522FF6c8f3660372B9dc6674d85cC8" as const,
  },
];

const deployerPrivateKey = process.env.DEPLOYER_PK;

if (!deployerPrivateKey) {
  throw new Error("DEPLOYER_PK not set in .env");
}

const account = privateKeyToAccount(
  (deployerPrivateKey.startsWith("0x")
    ? deployerPrivateKey
    : `0x${deployerPrivateKey}`) as `0x${string}`,
);
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
const chefAbi = parseAbi([
  "function owner() view returns (address)",
  "function poolLength() view returns (uint256)",
  "function poolInfo(uint256) view returns (address lpToken,uint256 allocPoint,uint256 lastRewardBlock,uint256 accRewardPerShare)",
  "function totalAllocPoint() view returns (uint256)",
  "function add(uint256 allocPoint,address lpToken,bool withUpdate)",
]) as Abi;

async function existingPools(): Promise<Map<string, { pid: number; allocPoint: bigint }>> {
  const poolLength = (await publicClient.readContract({
    address: MASTERCHEF,
    abi: chefAbi,
    functionName: "poolLength",
  })) as bigint;

  const byToken = new Map<string, { pid: number; allocPoint: bigint }>();

  for (let pid = 0; pid < Number(poolLength); pid++) {
    const pool = (await publicClient.readContract({
      address: MASTERCHEF,
      abi: chefAbi,
      functionName: "poolInfo",
      args: [BigInt(pid)],
    })) as readonly [string, bigint, bigint, bigint];

    byToken.set(pool[0].toLowerCase(), { pid, allocPoint: pool[1] });
  }

  return byToken;
}

async function addPool(
  symbol: string,
  token: `0x${string}`,
  owner: string,
): Promise<void> {
  const { request } = await publicClient.simulateContract({
    account: DRY_RUN ? owner : account,
    address: MASTERCHEF,
    abi: chefAbi,
    functionName: "add",
    args: [ALLOC_POINT, token, true],
  });

  const expectedPid = Number(
    (await publicClient.readContract({
      address: MASTERCHEF,
      abi: chefAbi,
      functionName: "poolLength",
    })) as bigint,
  );

  if (DRY_RUN) {
    console.log(
      JSON.stringify({
        status: "simulation_success",
        symbol,
        pid: expectedPid,
        lpToken: token,
        allocPoint: ALLOC_POINT.toString(),
      }),
    );
    return;
  }

  const txHash = await walletClient.writeContract(request);
  console.log(`${symbol}: broadcast ${txHash}`);
  const receipt = await publicClient.waitForTransactionReceipt({
    hash: txHash,
  });

  if (receipt.status !== "success") {
    throw new Error(`MasterChef.add(${symbol}) reverted (${txHash})`);
  }

  const pool = (await publicClient.readContract({
    address: MASTERCHEF,
    abi: chefAbi,
    functionName: "poolInfo",
    args: [BigInt(expectedPid)],
  })) as readonly [string, bigint, bigint, bigint];

  if (
    pool[0].toLowerCase() !== token.toLowerCase() ||
    pool[1] !== ALLOC_POINT
  ) {
    throw new Error(`Post-add verification failed for ${symbol}`);
  }

  console.log(
    JSON.stringify({
      symbol,
      txHash,
      pid: expectedPid,
      lpToken: token,
      allocPoint: ALLOC_POINT.toString(),
    }),
  );
}

async function main() {
  const owner = (await publicClient.readContract({
    address: MASTERCHEF,
    abi: chefAbi,
    functionName: "owner",
  })) as string;

  if (!DRY_RUN && owner.toLowerCase() !== account.address.toLowerCase()) {
    throw new Error(`Caller ${account.address} is not owner ${owner}`);
  }

  const byToken = await existingPools();

  for (const { symbol, token } of SOLO_POOLS) {
    const existing = byToken.get(token.toLowerCase());

    if (existing) {
      if (existing.allocPoint === ALLOC_POINT) {
        console.log(`${symbol} solo pool already exists at pid ${existing.pid}.`);
        continue;
      }

      throw new Error(
        `${symbol} already exists at pid ${existing.pid} with allocPoint ${existing.allocPoint}`,
      );
    }

    await addPool(symbol, token, owner);
  }

  const totalAllocPoint = (await publicClient.readContract({
    address: MASTERCHEF,
    abi: chefAbi,
    functionName: "totalAllocPoint",
  })) as bigint;

  console.log(`totalAllocPoint: ${totalAllocPoint}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
