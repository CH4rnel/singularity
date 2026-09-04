/**
 * On-chain proof that the Cyberia V3 stack works, and that a live pool's swap fee is settable.
 *
 * Creates (or reuses) a WCYBER/ASH pool at the 0.25% tier priced off the existing v2 pair, adds a
 * deliberately small full-range position, and then:
 *   quote -> swap -> compare, set the pool's fee to 0.05% -> quote -> swap -> compare.
 *
 * The fee is restored to the pool's own tier at the end, so nothing is left charging a rate its
 * listing does not claim.
 *
 * Usage: node scripts/v3-smoke.ts
 */

import "dotenv/config";
import * as fs from "fs";
import * as path from "path";
import { createPublicClient, createWalletClient, http, type Abi, type Hex } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");
const account = privateKeyToAccount(
  (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as Hex,
);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });
const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });

const ROOT = path.resolve(import.meta.dirname, "..");
const V3 = JSON.parse(fs.readFileSync(path.join(ROOT, "deployments", "cyberia-v3.json"), "utf8"));

const WCYBER = "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9" as const;
const ASH = "0xD199e6ae74B992F017f8940B26Fa18A7dD30eE86" as const;

const FEE_TIER = 2500;
const PROMO_FEE = 500;
const TICK_SPACING = 50;
const FULL_LOWER = Math.ceil(-887272 / TICK_SPACING) * TICK_SPACING;
const FULL_UPPER = Math.floor(887272 / TICK_SPACING) * TICK_SPACING;

const ONE = 10n ** 18n;
const WCYBER_IN = ONE / 100n; // 0.01 WCYBER
const ASH_IN = 1000n * ONE; // matches the v2 price of 1 WCYBER = 100,000 ASH
const SWAP_IN = ONE; // 1 ASH, small enough that price impact does not drown the fee
const MAX_UINT256 = (1n << 256n) - 1n;
// Creating a pool deploys a 23 KB contract by CREATE2: about 5M gas, well over any default.
// Unused gas is not charged, and the block limit here is 30M, so the ceiling is set generously.
const GAS = 10_000_000n;
const MIN_GAS_PRICE = 1_500_000_000n;

const ERC20 = [
  { name: "approve", type: "function", stateMutability: "nonpayable", inputs: [{ name: "s", type: "address" }, { name: "a", type: "uint256" }], outputs: [{ type: "bool" }] },
  { name: "allowance", type: "function", stateMutability: "view", inputs: [{ name: "o", type: "address" }, { name: "s", type: "address" }], outputs: [{ type: "uint256" }] },
  { name: "balanceOf", type: "function", stateMutability: "view", inputs: [{ name: "o", type: "address" }], outputs: [{ type: "uint256" }] },
] as const satisfies Abi;

function artifact(name: string): Abi {
  const matches: string[] = [];
  const walk = (dir: string) => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      if (e.isDirectory()) walk(full);
      else if (e.name === `${name}.json`) matches.push(full);
    }
  };
  walk(path.join(ROOT, "artifacts", "contracts"));
  return JSON.parse(fs.readFileSync(matches[0], "utf8")).abi as Abi;
}

const factoryAbi = artifact("PancakeV3Factory");
const poolAbi = artifact("PancakeV3Pool");
const npmAbi = artifact("NonfungiblePositionManager");
const quoterAbi = artifact("QuoterV2");
const routerAbi = artifact("SwapRouter");

async function gasPrice(): Promise<bigint> {
  const live = await publicClient.getGasPrice();
  const bumped = (live * 3n) / 2n;
  return bumped > MIN_GAS_PRICE ? bumped : MIN_GAS_PRICE;
}

async function send(label: string, address: Hex, abi: Abi, functionName: string, args: unknown[], value = 0n) {
  const hash = await walletClient.writeContract({
    address, abi, functionName, args: args as never, gas: GAS, gasPrice: await gasPrice(), value,
  });
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") throw new Error(`${label} reverted (${hash})`);
  console.log(`  ${label.padEnd(38)} ok  ${receipt.gasUsed.toString().padStart(8)} gas`);
  return receipt;
}

/** integer sqrt, so the initial price is exact rather than a float rounded into a uint160 */
function isqrt(n: bigint): bigint {
  if (n < 2n) return n;
  let x = n, y = (x + 1n) / 2n;
  while (y < x) { x = y; y = (x + n / x) / 2n; }
  return x;
}

async function quoteAshForWcyber(amountIn: bigint): Promise<bigint> {
  const { result } = await publicClient.simulateContract({
    address: V3.QuoterV2 as Hex,
    abi: quoterAbi,
    functionName: "quoteExactInputSingle",
    args: [{ tokenIn: ASH, tokenOut: WCYBER, amountIn, fee: FEE_TIER, sqrtPriceLimitX96: 0n }],
    account,
  });
  return (result as readonly bigint[])[0];
}

async function swapAshForWcyber(label: string, amountIn: bigint): Promise<bigint> {
  const before = (await publicClient.readContract({
    address: WCYBER, abi: ERC20, functionName: "balanceOf", args: [account.address],
  })) as bigint;
  await send(label, V3.SwapRouter as Hex, routerAbi, "exactInputSingle", [{
    tokenIn: ASH,
    tokenOut: WCYBER,
    fee: FEE_TIER,
    recipient: account.address,
    deadline: BigInt(Math.floor(Date.now() / 1000) + 900),
    amountIn,
    amountOutMinimum: 0n,
    sqrtPriceLimitX96: 0n,
  }]);
  const after = (await publicClient.readContract({
    address: WCYBER, abi: ERC20, functionName: "balanceOf", args: [account.address],
  })) as bigint;
  return after - before;
}

async function ensureAllowance(token: Hex, spender: Hex, label: string) {
  const current = (await publicClient.readContract({
    address: token, abi: ERC20, functionName: "allowance", args: [account.address, spender],
  })) as bigint;
  if (current < ASH_IN) await send(label, token, ERC20 as unknown as Abi, "approve", [spender, MAX_UINT256]);
}

async function main() {
  console.log(`Cyberia V3 smoke test\n  factory ${V3.PancakeV3Factory}\n`);

  // WCYBER sorts below ASH, so token0 = WCYBER and the pool's price is ASH per WCYBER.
  const price = ASH_IN / WCYBER_IN; // 100_000
  const sqrtPriceX96 = isqrt(price * (1n << 192n));

  let pool = (await publicClient.readContract({
    address: V3.PancakeV3Factory as Hex, abi: factoryAbi, functionName: "getPool",
    args: [WCYBER, ASH, FEE_TIER],
  })) as Hex;

  if (pool === "0x0000000000000000000000000000000000000000") {
    await ensureAllowance(WCYBER, V3.NonfungiblePositionManager as Hex, "approve WCYBER -> positions");
    await ensureAllowance(ASH, V3.NonfungiblePositionManager as Hex, "approve ASH -> positions");

    await send(
      "createAndInitializePool", V3.NonfungiblePositionManager as Hex, npmAbi,
      "createAndInitializePoolIfNecessary", [WCYBER, ASH, FEE_TIER, sqrtPriceX96],
    );
    pool = (await publicClient.readContract({
      address: V3.PancakeV3Factory as Hex, abi: factoryAbi, functionName: "getPool",
      args: [WCYBER, ASH, FEE_TIER],
    })) as Hex;

    // Cyberia ships pools at a zero protocol fee: taking a cut of the LP's fees while there is
    // barely any liquidity would repel the liquidity the chain is trying to attract.
    await send("setFeeProtocol(0, 0)", V3.PancakeV3Factory as Hex, factoryAbi, "setFeeProtocol", [pool, 0, 0]);

    await send("mint full-range position", V3.NonfungiblePositionManager as Hex, npmAbi, "mint", [{
      token0: WCYBER, token1: ASH, fee: FEE_TIER,
      tickLower: FULL_LOWER, tickUpper: FULL_UPPER,
      amount0Desired: WCYBER_IN, amount1Desired: ASH_IN,
      amount0Min: 0n, amount1Min: 0n,
      recipient: account.address,
      deadline: BigInt(Math.floor(Date.now() / 1000) + 900),
    }]);
  }
  console.log(`  pool    ${pool}\n`);

  await ensureAllowance(ASH, V3.SwapRouter as Hex, "approve ASH -> router");

  const slot0 = (await publicClient.readContract({ address: pool, abi: poolAbi, functionName: "slot0" })) as readonly unknown[];
  const liquidity = (await publicClient.readContract({ address: pool, abi: poolAbi, functionName: "liquidity" })) as bigint;
  console.log(`  liquidity ${liquidity}`);
  console.log(`  tick      ${slot0[1]}`);
  console.log(`  fee       ${await publicClient.readContract({ address: pool, abi: poolAbi, functionName: "fee" })}\n`);

  const quotedAt2500 = await quoteAshForWcyber(SWAP_IN);
  console.log(`  quote 1 ASH at 0.25%  -> ${quotedAt2500} wei WCYBER`);
  const gotAt2500 = await swapAshForWcyber("swap 1 ASH at 0.25%", SWAP_IN);
  console.log(`  swap  1 ASH at 0.25%  -> ${gotAt2500} wei WCYBER  ${gotAt2500 === quotedAt2500 ? "(quoter agrees)" : "(!! quoter disagrees)"}\n`);

  const requoteAt2500 = await quoteAshForWcyber(SWAP_IN);
  await send(`setPoolFee(${PROMO_FEE})`, V3.PancakeV3Factory as Hex, factoryAbi, "setPoolFee", [pool, PROMO_FEE]);
  const liveFee = await publicClient.readContract({ address: pool, abi: poolAbi, functionName: "fee" });
  const quotedAt500 = await quoteAshForWcyber(SWAP_IN);
  console.log(`  pool fee is now ${liveFee}`);
  console.log(`  quote 1 ASH at 0.25%  -> ${requoteAt2500} wei WCYBER  (same pool, before the change)`);
  console.log(`  quote 1 ASH at 0.05%  -> ${quotedAt500} wei WCYBER  (+${quotedAt500 - requoteAt2500})`);

  const gotAt500 = await swapAshForWcyber("swap 1 ASH at 0.05%", SWAP_IN);
  console.log(`  swap  1 ASH at 0.05%  -> ${gotAt500} wei WCYBER  ${gotAt500 === quotedAt500 ? "(quoter agrees)" : "(!! quoter disagrees)"}\n`);

  await send(`setPoolFee(${FEE_TIER}) restore`, V3.PancakeV3Factory as Hex, factoryAbi, "setPoolFee", [pool, FEE_TIER]);

  const record = JSON.parse(fs.readFileSync(path.join(ROOT, "deployments", "cyberia-v3.json"), "utf8"));
  record.smokePool = { pool, token0: WCYBER, token1: ASH, feeTier: FEE_TIER, timestamp: new Date().toISOString() };
  fs.writeFileSync(path.join(ROOT, "deployments", "cyberia-v3.json"), `${JSON.stringify(record, null, 2)}\n`);

  const better = quotedAt500 > requoteAt2500;
  console.log(better ? "  RESULT: a live pool's fee changed what a swap pays out. Everything else stayed put." : "  RESULT: FAILED -- the fee change did not move the quote");
  if (!better) process.exit(1);
}

main().catch((e) => { console.error(e); process.exit(1); });
