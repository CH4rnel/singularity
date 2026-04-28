/**
 * Seed ASH/WCYBER pair with the 1-ASH premint and register it as a MasterChef
 * LP pool.
 *
 * Reads:
 *   ./deployments/cyberia-quickswap.json  → Router/Factory/WCYBER
 *   ./deployments/cyberia-ash-emission.json → new Ash + MasterChef
 *
 * Steps:
 *   1. Approve Router for ASH.
 *   2. Router.addLiquidityETH(ASH, 0.5 ASH, …, 0.05 CYBER) — creates pair + seeds.
 *   3. Read pair address via Factory.getPair.
 *   4. MasterChef.add(allocPoint=LP_ALLOC, lpToken=pair, withUpdate=true).
 *   5. Persist pair address back to cyberia-ash-emission.json.
 *
 * Env:
 *   ASH_LIQ      — ASH amount to lock as liquidity (default "0.5")
 *   CYBER_LIQ    — CYBER amount (default "0.05")
 *   LP_ALLOC     — alloc points for LP pool (default 80)
 */

import "dotenv/config";
import {
  createWalletClient,
  createPublicClient,
  http,
  parseAbi,
  parseUnits,
  type Abi,
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

const ASH_LIQ = parseUnits(process.env.ASH_LIQ ?? "0.5", 18);
const CYBER_LIQ = parseUnits(process.env.CYBER_LIQ ?? "0.05", 18);
const LP_ALLOC = BigInt(process.env.LP_ALLOC ?? "80");

async function main() {
  const dexSummary = JSON.parse(
    fs.readFileSync(path.resolve("./deployments/cyberia-quickswap.json"), "utf8"),
  );
  const emissionFile = path.resolve("./deployments/cyberia-ash-emission.json");
  const emission = JSON.parse(fs.readFileSync(emissionFile, "utf8"));

  const ROUTER = dexSummary.UniswapV2Router02 as `0x${string}`;
  const FACTORY = dexSummary.UniswapV2Factory as `0x${string}`;
  const WCYBER = dexSummary.WCYBER as `0x${string}`;
  const ASH = emission.Ash as `0x${string}`;
  const CHEF = emission.MasterChef as `0x${string}`;

  console.log("=== Add ASH/WCYBER liquidity + register LP pool ===");
  console.log("Router:    ", ROUTER);
  console.log("Factory:   ", FACTORY);
  console.log("WCYBER:    ", WCYBER);
  console.log("ASH (new): ", ASH);
  console.log("MasterChef:", CHEF);
  console.log("ASH liq:   ", ASH_LIQ.toString(), "wei");
  console.log("CYBER liq: ", CYBER_LIQ.toString(), "wei");

  const erc20 = parseAbi([
    "function approve(address,uint256) returns (bool)",
    "function balanceOf(address) view returns (uint256)",
  ]);
  const ashBal = (await publicClient.readContract({
    address: ASH,
    abi: erc20,
    functionName: "balanceOf",
    args: [account.address],
  })) as bigint;
  console.log("ASH bal:", ashBal.toString());
  if (ashBal < ASH_LIQ) throw new Error("not enough ASH for liquidity");

  const ethBal = await publicClient.getBalance({ address: account.address });
  if (ethBal < CYBER_LIQ + parseUnits("0.02", 18)) {
    throw new Error("not enough CYBER for liquidity + gas");
  }

  // 1. Approve
  console.log("\n1. Approve Router for ASH…");
  const apTx = await walletClient.writeContract({
    address: ASH,
    abi: erc20,
    functionName: "approve",
    args: [ROUTER, ASH_LIQ],
  });
  console.log("  tx:", apTx);
  const apR = await publicClient.waitForTransactionReceipt({ hash: apTx });
  if (apR.status !== "success") throw new Error("approve failed");

  // 2. addLiquidityETH (creates pair on the fly)
  console.log("\n2. Router.addLiquidityETH…");
  const routerAbi = parseAbi([
    "function addLiquidityETH(address,uint256,uint256,uint256,address,uint256) payable returns (uint256,uint256,uint256)",
  ]);
  const latest = await publicClient.getBlock({ blockTag: "latest" });
  const deadline = latest.timestamp + 1200n;
  const lqTx = await walletClient.writeContract({
    address: ROUTER,
    abi: routerAbi,
    functionName: "addLiquidityETH",
    args: [
      ASH,
      ASH_LIQ,
      (ASH_LIQ * 95n) / 100n,
      (CYBER_LIQ * 95n) / 100n,
      account.address,
      deadline,
    ],
    value: CYBER_LIQ,
  });
  console.log("  tx:", lqTx);
  const lqR = await publicClient.waitForTransactionReceipt({ hash: lqTx });
  if (lqR.status !== "success") throw new Error("addLiquidityETH reverted");

  // 3. Get pair
  const factoryAbi = parseAbi(["function getPair(address,address) view returns (address)"]);
  const pair = (await publicClient.readContract({
    address: FACTORY,
    abi: factoryAbi,
    functionName: "getPair",
    args: [ASH, WCYBER],
  })) as `0x${string}`;
  console.log("\n3. Pair address:", pair);

  const pairAbi = parseAbi([
    "function getReserves() view returns (uint112,uint112,uint32)",
    "function token0() view returns (address)",
    "function token1() view returns (address)",
    "function balanceOf(address) view returns (uint256)",
  ]);
  const [t0, t1, reserves, lpOur] = await Promise.all([
    publicClient.readContract({ address: pair, abi: pairAbi, functionName: "token0" }),
    publicClient.readContract({ address: pair, abi: pairAbi, functionName: "token1" }),
    publicClient.readContract({ address: pair, abi: pairAbi, functionName: "getReserves" }),
    publicClient.readContract({
      address: pair,
      abi: pairAbi,
      functionName: "balanceOf",
      args: [account.address],
    }),
  ]);
  const [r0, r1] = reserves as unknown as [bigint, bigint, number];
  console.log("  token0:", t0, "reserve:", r0.toString());
  console.log("  token1:", t1, "reserve:", r1.toString());
  console.log("  our LP:", lpOur.toString());

  // 4. Add LP pool to MasterChef
  console.log("\n4. MasterChef.add(LP, allocPoint=" + LP_ALLOC + ", withUpdate=true)…");
  const chefAbi = parseAbi([
    "function add(uint256,address,bool)",
    "function poolLength() view returns (uint256)",
    "function poolInfo(uint256) view returns (address lpToken,uint256 allocPoint,uint256 lastRewardBlock,uint256 accRewardPerShare)",
    "function totalAllocPoint() view returns (uint256)",
  ]);
  const addTx = await walletClient.writeContract({
    address: CHEF,
    abi: chefAbi,
    functionName: "add",
    args: [LP_ALLOC, pair, true],
  });
  console.log("  tx:", addTx);
  const addR = await publicClient.waitForTransactionReceipt({ hash: addTx });
  if (addR.status !== "success") throw new Error("chef.add reverted");

  const poolLen = (await publicClient.readContract({
    address: CHEF,
    abi: chefAbi,
    functionName: "poolLength",
  })) as bigint;
  const totalAlloc = (await publicClient.readContract({
    address: CHEF,
    abi: chefAbi,
    functionName: "totalAllocPoint",
  })) as bigint;
  const lpPid = poolLen - 1n;
  console.log("  LP pid:", lpPid.toString());
  console.log("  totalAllocPoint:", totalAlloc.toString());

  // 5. Persist
  emission.pools = emission.pools ?? {};
  emission.pools.lp = {
    pid: Number(lpPid),
    lpToken: pair,
    allocPoint: LP_ALLOC.toString(),
  };
  emission.totalAllocPoint = totalAlloc.toString();
  emission.lpPair = {
    address: pair,
    token0: t0,
    token1: t1,
    reserve0: r0.toString(),
    reserve1: r1.toString(),
  };
  emission.timestamp_lp = new Date().toISOString();
  fs.writeFileSync(emissionFile, JSON.stringify(emission, null, 2));
  console.log("\nUpdated", emissionFile);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
