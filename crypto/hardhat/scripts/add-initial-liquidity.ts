/**
 * Create the ASH/WCYBER pair and seed it with initial liquidity via the
 * UniswapV2Router02.addLiquidityETH flow.
 *
 * Amounts: 10 000 ASH paired with 0.1 CYBER (1 ASH ≈ 0.00001 CYBER — arbitrary).
 *
 * Reads deployment addresses from ./deployments/cyberia-quickswap.json
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

async function main() {
  const summary = JSON.parse(
    fs.readFileSync(path.resolve("./deployments/cyberia-quickswap.json"), "utf8"),
  );
  const ASH = summary.ASH as `0x${string}`;
  const ROUTER = summary.UniswapV2Router02 as `0x${string}`;
  const FACTORY = summary.UniswapV2Factory as `0x${string}`;
  const WCYBER = summary.WCYBER as `0x${string}`;

  console.log("=== Create ASH/WCYBER pair + seed initial liquidity ===");
  console.log("Router:", ROUTER);
  console.log("Factory:", FACTORY);
  console.log("ASH:", ASH);
  console.log("WCYBER:", WCYBER);
  console.log("Deployer …", account.address.slice(-6));

  // Parameters
  const ASH_AMOUNT = parseUnits("10000", 18); // 10 000 ASH
  const CYBER_AMOUNT = parseUnits("0.1", 18); // 0.1 CYBER

  // ---- 1. Approve router for ASH ----
  console.log("\n1. Approving Router to spend ASH…");
  const erc20Abi = parseAbi([
    "function approve(address spender, uint256 value) returns (bool)",
    "function allowance(address owner, address spender) view returns (uint256)",
    "function balanceOf(address) view returns (uint256)",
  ]);
  const ashBalance = (await publicClient.readContract({
    address: ASH,
    abi: erc20Abi,
    functionName: "balanceOf",
    args: [account.address],
  })) as bigint;
  console.log("  ASH balance:", ashBalance);
  if (ashBalance < ASH_AMOUNT) throw new Error(`insufficient ASH (need ${ASH_AMOUNT}, have ${ashBalance})`);
  const ethBalance = await publicClient.getBalance({ address: account.address });
  console.log("  CYBER balance:", ethBalance);
  if (ethBalance < CYBER_AMOUNT + parseUnits("0.01", 18)) {
    throw new Error(`insufficient CYBER for liquidity + gas (have ${ethBalance})`);
  }

  const approveTx = await walletClient.writeContract({
    address: ASH,
    abi: erc20Abi,
    functionName: "approve",
    args: [ROUTER, ASH_AMOUNT],
  });
  console.log("  approve tx:", approveTx);
  const approveR = await publicClient.waitForTransactionReceipt({ hash: approveTx });
  if (approveR.status !== "success") throw new Error("approve failed");
  console.log("  approved");

  // ---- 2. Call addLiquidityETH (this will create the pair on the fly) ----
  console.log("\n2. addLiquidityETH via Router…");
  const routerAbi = parseAbi([
    "function addLiquidityETH(address token, uint256 amountTokenDesired, uint256 amountTokenMin, uint256 amountETHMin, address to, uint256 deadline) payable returns (uint256 amountToken, uint256 amountETH, uint256 liquidity)",
  ]);
  const latest = await publicClient.getBlock({ blockTag: "latest" });
  const deadline = latest.timestamp + 1200n; // 20 min

  const hash = await walletClient.writeContract({
    address: ROUTER,
    abi: routerAbi,
    functionName: "addLiquidityETH",
    args: [
      ASH,                 // token
      ASH_AMOUNT,          // amountTokenDesired
      (ASH_AMOUNT * 95n) / 100n,   // amountTokenMin (5% slippage)
      (CYBER_AMOUNT * 95n) / 100n, // amountETHMin
      account.address,     // to
      deadline,
    ],
    value: CYBER_AMOUNT,
  });
  console.log("  tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    console.error("gas used:", receipt.gasUsed);
    throw new Error("addLiquidityETH reverted");
  }
  console.log("  gas used:", receipt.gasUsed);

  // ---- 3. Read back pair ----
  const factoryAbi = parseAbi(["function getPair(address,address) view returns (address)"]);
  const pair = (await publicClient.readContract({
    address: FACTORY,
    abi: factoryAbi,
    functionName: "getPair",
    args: [ASH, WCYBER],
  })) as `0x${string}`;
  console.log("\n3. Pair address:", pair);

  const pairAbi = parseAbi([
    "function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)",
    "function token0() view returns (address)",
    "function token1() view returns (address)",
    "function totalSupply() view returns (uint256)",
    "function balanceOf(address) view returns (uint256)",
  ]);
  const [t0, t1, reserves, lpTotal, lpOur] = await Promise.all([
    publicClient.readContract({ address: pair, abi: pairAbi, functionName: "token0" }),
    publicClient.readContract({ address: pair, abi: pairAbi, functionName: "token1" }),
    publicClient.readContract({ address: pair, abi: pairAbi, functionName: "getReserves" }),
    publicClient.readContract({ address: pair, abi: pairAbi, functionName: "totalSupply" }),
    publicClient.readContract({
      address: pair,
      abi: pairAbi,
      functionName: "balanceOf",
      args: [account.address],
    }),
  ]);
  const [r0, r1] = reserves as unknown as [bigint, bigint, number];
  console.log("  token0:", t0, "reserve:", r0);
  console.log("  token1:", t1, "reserve:", r1);
  console.log("  LP totalSupply:", lpTotal);
  console.log("  our LP balance:", lpOur);

  // Update summary
  summary.initialPair = pair;
  summary.initialPairTokens = { token0: t0, token1: t1, reserve0: r0.toString(), reserve1: r1.toString() };
  fs.writeFileSync(
    path.resolve("./deployments/cyberia-quickswap.json"),
    JSON.stringify(summary, null, 2),
  );
  console.log("\nUpdated deployments/cyberia-quickswap.json");
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
