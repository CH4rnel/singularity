/**
 * On-chain proof that a locked launch position still pays its creator.
 *
 * Mints a deliberately tiny full-range position in the existing WCYBER/ASH pool, hands it to the
 * LaunchLocker naming a creator, trades against the pool, and collects. The position is locked
 * PERMANENTLY -- that is the whole point of the contract -- so the amounts here are kept trivial.
 *
 * Usage: node scripts/v3-locker-smoke.ts
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
/** A stand-in for a launch creator: an address nobody holds, so its balance is unambiguous proof. */
const CREATOR = "0x000000000000000000000000000000000000c0Fe" as const;

const FEE_TIER = 2500;
const TICK_SPACING = 50;
const FULL_LOWER = Math.ceil(-887272 / TICK_SPACING) * TICK_SPACING;
const FULL_UPPER = Math.floor(887272 / TICK_SPACING) * TICK_SPACING;

const ONE = 10n ** 18n;
const WCYBER_IN = ONE / 1000n; // 0.001 WCYBER
const ASH_IN = 100n * ONE; // the same 1:100,000 price the pool is at
const SWAP_IN = 10n * ONE;
const GAS = 10_000_000n;
const MIN_GAS_PRICE = 1_500_000_000n;

const ERC20 = [
  { name: "balanceOf", type: "function", stateMutability: "view", inputs: [{ type: "address" }], outputs: [{ type: "uint256" }] },
  { name: "allowance", type: "function", stateMutability: "view", inputs: [{ type: "address" }, { type: "address" }], outputs: [{ type: "uint256" }] },
  { name: "approve", type: "function", stateMutability: "nonpayable", inputs: [{ type: "address" }, { type: "uint256" }], outputs: [{ type: "bool" }] },
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

const npmAbi = artifact("NonfungiblePositionManager");
const routerAbi = artifact("SwapRouter");
const lockerAbi = artifact("LaunchLocker");

async function gasPrice(): Promise<bigint> {
  const live = await publicClient.getGasPrice();
  const bumped = (live * 3n) / 2n;
  return bumped > MIN_GAS_PRICE ? bumped : MIN_GAS_PRICE;
}

async function send(label: string, address: Hex, abi: Abi, functionName: string, args: unknown[]) {
  const hash = await walletClient.writeContract({
    address, abi, functionName, args: args as never, gas: GAS, gasPrice: await gasPrice(),
  });
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") throw new Error(`${label} reverted (${hash})`);
  console.log(`  ${label.padEnd(38)} ok  ${receipt.gasUsed.toString().padStart(8)} gas`);
  return receipt;
}

async function balance(token: Hex, who: Hex): Promise<bigint> {
  return (await publicClient.readContract({
    address: token, abi: ERC20, functionName: "balanceOf", args: [who],
  })) as bigint;
}

async function main() {
  console.log(`Cyberia V3 launch-locker smoke test`);
  console.log(`  locker  ${V3.LaunchLocker}`);
  console.log(`  creator ${CREATOR}  (stand-in)\n`);

  const locker = V3.LaunchLocker as Hex;
  const positions = V3.NonfungiblePositionManager as Hex;

  for (const [name, token] of [["WCYBER", WCYBER], ["ASH", ASH]] as const) {
    const allowance = (await publicClient.readContract({
      address: token, abi: ERC20, functionName: "allowance", args: [account.address, positions],
    })) as bigint;
    if (allowance < ASH_IN) {
      await send(`approve ${name} -> positions`, token, ERC20 as unknown as Abi, "approve", [positions, (1n << 256n) - 1n]);
    }
  }

  // Locking is permanent, so a re-run reuses the position already locked rather than minting
  // another one and burying more liquidity to prove the same thing twice.
  const alreadyLocked = (await publicClient.readContract({
    address: locker, abi: lockerAbi, functionName: "lockedCount",
  })) as bigint;

  let tokenId: bigint;
  if (alreadyLocked > 0n) {
    tokenId = (await publicClient.readContract({
      address: locker, abi: lockerAbi, functionName: "lockedIds", args: [alreadyLocked - 1n],
    })) as bigint;
    console.log(`  reusing locked position ${tokenId}\n`);
  } else {
    tokenId = await mintAndLock();
  }

  async function mintAndLock(): Promise<bigint> {
  const mint = await send("mint tiny full-range position", positions, npmAbi, "mint", [{
    token0: WCYBER, token1: ASH, fee: FEE_TIER,
    tickLower: FULL_LOWER, tickUpper: FULL_UPPER,
    amount0Desired: WCYBER_IN, amount1Desired: ASH_IN,
    amount0Min: 0n, amount1Min: 0n,
    recipient: account.address,
    deadline: BigInt(Math.floor(Date.now() / 1000) + 900),
  }]);

  // the position manager is an ERC721: the tokenId is in the Transfer log it emitted
  const transfer = mint.logs.find(
    (l) => l.address.toLowerCase() === positions.toLowerCase() && l.topics.length === 4,
  );
  if (!transfer) throw new Error("no position Transfer log found");
  const minted = BigInt(transfer.topics[3] as string);
  console.log(`  tokenId ${minted}\n`);

  const creatorData = `0x${CREATOR.slice(2).toLowerCase().padStart(64, "0")}` as Hex;
  await send("lock position (permanent)", positions, npmAbi, "safeTransferFrom", [
    account.address, locker, minted, creatorData,
  ]);
  return minted;
  }

  const owner = await publicClient.readContract({
    address: positions, abi: npmAbi, functionName: "ownerOf", args: [tokenId],
  });
  const lock = (await publicClient.readContract({
    address: locker, abi: lockerAbi, functionName: "locks", args: [tokenId],
  })) as readonly [Hex, number, boolean];
  console.log(`  position owner  ${owner}`);
  console.log(`  lock creator    ${lock[0]}`);
  console.log(`  lock creatorBps ${lock[1]}\n`);

  const allowance = (await publicClient.readContract({
    address: ASH, abi: ERC20, functionName: "allowance", args: [account.address, V3.SwapRouter],
  })) as bigint;
  if (allowance < SWAP_IN) {
    await send("approve ASH -> router", ASH, ERC20 as unknown as Abi, "approve", [V3.SwapRouter, (1n << 256n) - 1n]);
  }
  await send("swap 10 ASH (earns the position fees)", V3.SwapRouter as Hex, routerAbi, "exactInputSingle", [{
    tokenIn: ASH, tokenOut: WCYBER, fee: FEE_TIER,
    recipient: account.address,
    deadline: BigInt(Math.floor(Date.now() / 1000) + 900),
    amountIn: SWAP_IN, amountOutMinimum: 0n, sqrtPriceLimitX96: 0n,
  }]);

  const claimable = (await publicClient.readContract({
    address: locker, abi: lockerAbi, functionName: "claimable", args: [tokenId],
  })) as readonly bigint[];
  console.log(`\n  claimable creator  ${claimable[0]} wei WCYBER / ${claimable[1]} wei ASH`);
  console.log(`  claimable treasury ${claimable[2]} wei WCYBER / ${claimable[3]} wei ASH`);

  const creatorBefore = await balance(ASH, CREATOR);
  const treasuryBefore = await balance(ASH, V3.treasury as Hex);

  await send("collect (permissionless)", locker, lockerAbi, "collect", [tokenId]);

  const creatorGot = (await balance(ASH, CREATOR)) - creatorBefore;
  const treasuryGot = (await balance(ASH, V3.treasury as Hex)) - treasuryBefore;
  console.log(`\n  creator received   ${creatorGot} wei ASH`);
  console.log(`  treasury received  ${treasuryGot} wei ASH`);

  const stillOwned = await publicClient.readContract({
    address: positions, abi: npmAbi, functionName: "ownerOf", args: [tokenId],
  });
  const position = (await publicClient.readContract({
    address: positions, abi: npmAbi, functionName: "positions", args: [tokenId],
  })) as readonly unknown[];
  console.log(`  position still at  ${stillOwned}`);
  console.log(`  liquidity still    ${position[7]}`);

  const record = JSON.parse(fs.readFileSync(path.join(ROOT, "deployments", "cyberia-v3.json"), "utf8"));
  record.lockerSmoke = {
    tokenId: tokenId.toString(),
    creator: CREATOR,
    pool: record.smokePool?.pool,
    note: "tiny WCYBER/ASH position, locked permanently as a live proof",
    timestamp: new Date().toISOString(),
  };
  fs.writeFileSync(path.join(ROOT, "deployments", "cyberia-v3.json"), `${JSON.stringify(record, null, 2)}\n`);

  const ok =
    creatorGot > 0n &&
    treasuryGot > 0n &&
    creatorGot === ((creatorGot + treasuryGot) * BigInt(lock[1])) / 10_000n &&
    (stillOwned as string).toLowerCase() === locker.toLowerCase() &&
    (position[7] as bigint) > 0n;
  console.log(ok
    ? "\n  RESULT: the creator was paid, the treasury was paid, the liquidity did not move."
    : "\n  RESULT: FAILED");
  if (!ok) process.exit(1);
}

main().catch((e) => { console.error(e); process.exit(1); });
