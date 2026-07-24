/**
 * Wire the Robinhood EmissionChannel into the Cyberia MasterChef and retune
 * total ASH emission to the 436/day budget.
 *
 *   1. add(200, channelToken, true)   — 2 RH farms worth of allocPoint
 *   2. relayer stakes the channel token so its share accrues to the relayer
 *   3. setRewardPerBlock(436/day)     — total emission across all 29 farms
 *
 * After this the Cyberia chef splits 436/day over 2900 allocPoint: its 27
 * farms get 27/29 (405.9/day, 15.03 each) and the channel gets 2/29 (30.07/
 * day), which the funding keeper harvests and bridges to the RH FundedFarm.
 *
 * Usage: npx tsx scripts/wire-cyberia-emission-channel.ts
 */

import "dotenv/config";
import { createWalletClient, createPublicClient, http, parseAbi, formatEther, type Abi } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const CHEF = "0xd540DEa828567160FFDe5e792ca359aDD1f6B03D" as const;
const CHANNEL = (JSON.parse(fs.readFileSync("./deployments/cyberia-emission-channels.json", "utf8"))
  .channels.robinhood as string) as `0x${string}`;
const CHANNEL_ALLOC = 200n; // 2 RH farms × 100
const STAKE = 1_000_000_000_000_000_000n; // 1 channel token (full supply)
// 436 ASH/day over 86400 blocks (Cyberia ~1s blocks).
const REWARD_PER_BLOCK = (436n * 10n ** 18n) / 86400n;

const chain = { ...mainnet, id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 } };
const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });
const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });

const chefAbi = parseAbi([
  "function owner() view returns (address)",
  "function poolLength() view returns (uint256)",
  "function poolInfo(uint256) view returns (address,uint256,uint256,uint256)",
  "function totalAllocPoint() view returns (uint256)",
  "function rewardPerBlock() view returns (uint256)",
  "function add(uint256 allocPoint,address lpToken,bool withUpdate)",
  "function deposit(uint256 pid,uint256 amount)",
  "function setRewardPerBlock(uint256)",
]) as Abi;
const ercAbi = parseAbi(["function approve(address,uint256) returns (bool)", "function balanceOf(address) view returns (uint256)"]) as Abi;

async function send(label: string, request: Parameters<typeof walletClient.writeContract>[0]): Promise<void> {
  // Explicit gas: the Cyberia node's eth_estimateGas is unreliable for the
  // multi-pool massUpdatePools loops these calls trigger.
  const hash = await walletClient.writeContract({ ...request, gas: 6_000_000n } as typeof request);
  const r = await publicClient.waitForTransactionReceipt({ hash });
  if (r.status !== "success") throw new Error(`${label} reverted (${hash})`);
  console.log(`${label}: ok (${hash})`);
}

async function main() {
  const owner = (await publicClient.readContract({ address: CHEF, abi: chefAbi, functionName: "owner" })) as string;
  if (owner.toLowerCase() !== account.address.toLowerCase()) throw new Error(`not chef owner: ${owner}`);

  // Guard against a re-run adding a second channel pool.
  const len = Number((await publicClient.readContract({ address: CHEF, abi: chefAbi, functionName: "poolLength" })) as bigint);
  for (let pid = 0; pid < len; pid++) {
    const p = (await publicClient.readContract({ address: CHEF, abi: chefAbi, functionName: "poolInfo", args: [BigInt(pid)] })) as readonly [string, bigint, bigint, bigint];
    if (p[0].toLowerCase() === CHANNEL.toLowerCase()) {
      throw new Error(`channel already added at pid ${pid} — nothing to do`);
    }
  }
  const newPid = BigInt(len);

  console.log(`Adding channel ${CHANNEL} at pid ${newPid}, alloc ${CHANNEL_ALLOC}`);
  await send("chef.add(200, channel)", { address: CHEF, abi: chefAbi, functionName: "add", args: [CHANNEL_ALLOC, CHANNEL, true] });
  await send("channel.approve(chef)", { address: CHANNEL, abi: ercAbi, functionName: "approve", args: [CHEF, STAKE] });
  await send("chef.deposit(channel stake)", { address: CHEF, abi: chefAbi, functionName: "deposit", args: [newPid, STAKE] });
  await send(`chef.setRewardPerBlock(${formatEther(REWARD_PER_BLOCK)})`, { address: CHEF, abi: chefAbi, functionName: "setRewardPerBlock", args: [REWARD_PER_BLOCK] });

  const [tap, rpb] = (await Promise.all([
    publicClient.readContract({ address: CHEF, abi: chefAbi, functionName: "totalAllocPoint" }),
    publicClient.readContract({ address: CHEF, abi: chefAbi, functionName: "rewardPerBlock" }),
  ])) as [bigint, bigint];
  console.log(`\ntotalAllocPoint: ${tap} | rewardPerBlock: ${formatEther(rpb)} => ${(Number(formatEther(rpb)) * 86400).toFixed(1)} ASH/day total`);
  console.log(`  Cyberia 27 farms: ${((Number(formatEther(rpb)) * 86400 * 2700) / Number(tap)).toFixed(1)}/day`);
  console.log(`  RH channel (2/29): ${((Number(formatEther(rpb)) * 86400 * 200) / Number(tap)).toFixed(1)}/day`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
