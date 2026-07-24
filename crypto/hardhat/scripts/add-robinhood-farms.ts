/**
 * Adds LP farm pools to the Robinhood Chain MasterChef (deployments/
 * robinhood-dex.json). Pools already present with the expected allocPoint are
 * skipped, so the script is safe to re-run; extend FARM_POOLS to add more.
 *
 * Usage:
 *   npx tsx scripts/add-robinhood-farms.ts
 *   DRY_RUN=true npx tsx scripts/add-robinhood-farms.ts
 */

import "dotenv/config";
import { createPublicClient, createWalletClient, http, parseAbi, type Abi } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

const MASTERCHEF = "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9" as const;
const RPC_URL = process.env.ROBINHOOD_RPC_URL ?? "https://rpc.mainnet.chain.robinhood.com";
const ALLOC_POINT = 100n;
const DRY_RUN = process.env.DRY_RUN === "true";

const FARM_POOLS = [
  { label: "ASH/WETH LP", token: "0x631fba154Ae40aAcCaa1659Aa6031190105FF38f" as const },
  { label: "CYBER/WETH LP", token: "0x4E93763183A3eC492f01C06AE28805f0C1d0e6E7" as const },
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
  id: 4663,
  name: "Robinhood Chain",
  nativeCurrency: { name: "Ethereum", symbol: "ETH", decimals: 18 },
};
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });
const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const chefAbi = parseAbi([
  "function owner() view returns (address)",
  "function poolLength() view returns (uint256)",
  "function poolInfo(uint256) view returns (address lpToken,uint256 allocPoint,uint256 lastRewardBlock,uint256 accRewardPerShare)",
  "function totalAllocPoint() view returns (uint256)",
  "function add(uint256 allocPoint,address lpToken,bool withUpdate)",
]) as Abi;

async function main() {
  const [owner, poolLength] = (await Promise.all([
    publicClient.readContract({ address: MASTERCHEF, abi: chefAbi, functionName: "owner" }),
    publicClient.readContract({ address: MASTERCHEF, abi: chefAbi, functionName: "poolLength" }),
  ])) as [string, bigint];

  if (!DRY_RUN && owner.toLowerCase() !== account.address.toLowerCase()) {
    throw new Error(`Caller ${account.address} is not owner ${owner}`);
  }

  const byToken = new Map<string, { pid: number; allocPoint: bigint }>();

  for (let pid = 0; pid < Number(poolLength); pid++) {
    const pool = (await publicClient.readContract({
      address: MASTERCHEF, abi: chefAbi, functionName: "poolInfo", args: [BigInt(pid)],
    })) as readonly [string, bigint, bigint, bigint];
    byToken.set(pool[0].toLowerCase(), { pid, allocPoint: pool[1] });
  }

  for (const { label, token } of FARM_POOLS) {
    const existing = byToken.get(token.toLowerCase());

    if (existing) {
      if (existing.allocPoint === ALLOC_POINT) {
        console.log(`${label} already exists at pid ${existing.pid}.`);
        continue;
      }
      throw new Error(`${label} exists at pid ${existing.pid} with allocPoint ${existing.allocPoint}`);
    }

    const { request } = await publicClient.simulateContract({
      account: DRY_RUN ? owner : account,
      address: MASTERCHEF, abi: chefAbi, functionName: "add",
      args: [ALLOC_POINT, token, true],
    });

    if (DRY_RUN) {
      console.log(JSON.stringify({ status: "simulation_success", label, lpToken: token }));
      continue;
    }

    const txHash = await walletClient.writeContract(request);
    const receipt = await publicClient.waitForTransactionReceipt({ hash: txHash });
    if (receipt.status !== "success") throw new Error(`add(${label}) reverted (${txHash})`);
    const pid = Number(
      (await publicClient.readContract({
        address: MASTERCHEF, abi: chefAbi, functionName: "poolLength",
      })) as bigint,
    ) - 1;
    console.log(JSON.stringify({ label, txHash, pid, lpToken: token, allocPoint: ALLOC_POINT.toString() }));
  }

  const totalAllocPoint = (await publicClient.readContract({
    address: MASTERCHEF, abi: chefAbi, functionName: "totalAllocPoint",
  })) as bigint;
  console.log(`totalAllocPoint: ${totalAllocPoint}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
