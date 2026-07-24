/**
 * Satellite-farm funding keeper (Path A).
 *
 * For each satellite chain: harvest its EmissionChannel share on the Cyberia
 * chef (growing the relayer's ASH reserve), then mint backed bridged ASH on
 * the satellite and top up its FundedFarm, and keep the FundedFarm's
 * rewardPerBlock synced to that chain's live emission share.
 *
 * Invariants:
 *   - ASH is only ever minted on Cyberia (by the chef). Bridged ASH on a
 *     satellite is minted 1:1 against ASH the relayer holds on Cyberia, so
 *     bridged supply never exceeds the reserve (backing).
 *   - A satellite's emission share is derived live from its channel's
 *     allocPoint on the Cyberia chef, so adding farms/chains needs no keeper
 *     code change — only config below.
 *
 * Idempotent and safe to run on a schedule. Env: BUFFER_DAYS (default 7).
 *
 * Usage: BRIDGE_RELAYER_PRIVATE_KEY=… npx tsx scripts/fund-satellite-farms.ts
 */

import "dotenv/config";
import { createWalletClient, createPublicClient, http, parseAbi, formatEther, type Abi } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";
import * as fs from "fs";

const PK = process.env.BRIDGE_RELAYER_PRIVATE_KEY || process.env.DEPLOYER_PK;
if (!PK) throw new Error("BRIDGE_RELAYER_PRIVATE_KEY (or DEPLOYER_PK) not set");
const pk = (PK.startsWith("0x") ? PK : `0x${PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const BUFFER_DAYS = BigInt(process.env.BUFFER_DAYS || "7");

const CYBERIA_RPC = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";
const CHEF = "0xd540DEa828567160FFDe5e792ca359aDD1f6B03D" as const;
const ASH_CYBERIA = "0x992Fca0a89DD95afb17751f6CC233Adb9B089df5" as const;

const channels = JSON.parse(fs.readFileSync("./deployments/cyberia-emission-channels.json", "utf8")).channels;
const rhFarm = JSON.parse(fs.readFileSync("./deployments/robinhood-funded-farm.json", "utf8"));

// One entry per satellite chain. blocksPerDay is the chain's Solidity
// block.number cadence (Robinhood/Orbit tracks its ~12s parent ⇒ 7200).
const SATELLITES = [
  {
    name: "Robinhood",
    rpc: process.env.ROBINHOOD_RPC_URL || "https://rpc.mainnet.chain.robinhood.com",
    chainId: 4663,
    fundedFarm: rhFarm.FundedFarm as `0x${string}`,
    ashBridged: rhFarm.AshBridged as `0x${string}`,
    channelToken: channels.robinhood as `0x${string}`,
    blocksPerDay: 7200n,
  },
];

const chefAbi = parseAbi([
  "function poolLength() view returns (uint256)",
  "function poolInfo(uint256) view returns (address,uint256,uint256,uint256)",
  "function totalAllocPoint() view returns (uint256)",
  "function rewardPerBlock() view returns (uint256)",
  "function pendingReward(uint256,address) view returns (uint256)",
  "function deposit(uint256,uint256)",
]) as Abi;
const ercAbi = parseAbi(["function balanceOf(address) view returns (uint256)", "function totalSupply() view returns (uint256)"]) as Abi;
const ashAbi = parseAbi(["function mint(address,uint256)", "function balanceOf(address) view returns (uint256)", "function totalSupply() view returns (uint256)"]) as Abi;
const farmAbi = parseAbi(["function rewardPerBlock() view returns (uint256)", "function setRewardPerBlock(uint256)", "function rewardBalance() view returns (uint256)"]) as Abi;

const cyb = { ...mainnet, id: 49406, name: "Cyberia", nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 } };
const cybPub = createPublicClient({ chain: cyb, transport: http(CYBERIA_RPC) });
const cybWal = createWalletClient({ chain: cyb, transport: http(CYBERIA_RPC), account });

async function channelPid(channelToken: string): Promise<number> {
  const len = Number((await cybPub.readContract({ address: CHEF, abi: chefAbi, functionName: "poolLength" })) as bigint);
  for (let pid = 0; pid < len; pid++) {
    const p = (await cybPub.readContract({ address: CHEF, abi: chefAbi, functionName: "poolInfo", args: [BigInt(pid)] })) as readonly [string, bigint, bigint, bigint];
    if (p[0].toLowerCase() === channelToken.toLowerCase()) return pid;
  }
  throw new Error(`channel pool for ${channelToken} not found on chef`);
}

async function main() {
  console.log(`Keeper run @ ${new Date().toISOString()} — buffer ${BUFFER_DAYS}d`);

  const [chefRpb, chefTotalAlloc] = (await Promise.all([
    cybPub.readContract({ address: CHEF, abi: chefAbi, functionName: "rewardPerBlock" }),
    cybPub.readContract({ address: CHEF, abi: chefAbi, functionName: "totalAllocPoint" }),
  ])) as [bigint, bigint];
  const chefDailyWei = chefRpb * 86400n;

  for (const sat of SATELLITES) {
    console.log(`\n== ${sat.name} ==`);
    const pid = await channelPid(sat.channelToken);
    const pool = (await cybPub.readContract({ address: CHEF, abi: chefAbi, functionName: "poolInfo", args: [BigInt(pid)] })) as readonly [string, bigint, bigint, bigint];
    const channelAlloc = pool[1];

    // This chain's live daily ASH share (allocPoint-weighted) and the matching
    // FundedFarm rewardPerBlock at the satellite's own block cadence.
    const dailyShareWei = (chefDailyWei * channelAlloc) / chefTotalAlloc;
    const targetRpb = dailyShareWei / sat.blocksPerDay;
    console.log(`  channel alloc ${channelAlloc}/${chefTotalAlloc} => ${formatEther(dailyShareWei)} ASH/day; target rewardPerBlock ${formatEther(targetRpb)}`);

    // 1) Harvest the channel (deposit 0) so the relayer's Cyberia ASH reserve
    //    grows by this chain's accrued share.
    const pending = (await cybPub.readContract({ address: CHEF, abi: chefAbi, functionName: "pendingReward", args: [BigInt(pid), account.address] })) as bigint;
    if (pending > 0n) {
      const h = await cybWal.writeContract({ address: CHEF, abi: chefAbi, functionName: "deposit", args: [BigInt(pid), 0n], gas: 800_000n });
      await cybPub.waitForTransactionReceipt({ hash: h });
      console.log(`  harvested ${formatEther(pending)} ASH (tx ${h})`);
    } else {
      console.log("  nothing to harvest yet");
    }

    // 2) Backing check: bridged ASH supply must stay ≤ reserve (relayer's
    //    Cyberia ASH). freeBacking is how much more we may mint.
    const satPub = createPublicClient({ chain: { ...mainnet, id: sat.chainId }, transport: http(sat.rpc) });
    const satWal = createWalletClient({ chain: { ...mainnet, id: sat.chainId }, transport: http(sat.rpc), account });
    const [reserve, bridgedSupply, farmBal] = (await Promise.all([
      cybPub.readContract({ address: ASH_CYBERIA, abi: ercAbi, functionName: "balanceOf", args: [account.address] }),
      satPub.readContract({ address: sat.ashBridged, abi: ashAbi, functionName: "totalSupply" }),
      satPub.readContract({ address: sat.fundedFarm, abi: farmAbi, functionName: "rewardBalance" }),
    ])) as [bigint, bigint, bigint];
    const freeBacking = reserve > bridgedSupply ? reserve - bridgedSupply : 0n;
    console.log(`  reserve ${formatEther(reserve)} | bridged ${formatEther(bridgedSupply)} | freeBacking ${formatEther(freeBacking)} | farmBalance ${formatEther(farmBal)}`);

    // 3) Top the FundedFarm up toward BUFFER_DAYS of rewards, capped by backing.
    const target = dailyShareWei * BUFFER_DAYS;
    const need = target > farmBal ? target - farmBal : 0n;
    const mintAmount = need < freeBacking ? need : freeBacking;
    if (mintAmount > 0n) {
      const m = await satWal.writeContract({ address: sat.ashBridged, abi: ashAbi, functionName: "mint", args: [sat.fundedFarm, mintAmount] });
      await satPub.waitForTransactionReceipt({ hash: m });
      console.log(`  funded FundedFarm with ${formatEther(mintAmount)} bridged ASH (tx ${m})`);
    } else {
      console.log(`  no top-up needed (or no free backing); need ${formatEther(need)}`);
    }

    // 4) Sync the FundedFarm emission rate to this chain's share.
    const curRpb = (await satPub.readContract({ address: sat.fundedFarm, abi: farmAbi, functionName: "rewardPerBlock" })) as bigint;
    if (curRpb !== targetRpb) {
      const s = await satWal.writeContract({ address: sat.fundedFarm, abi: farmAbi, functionName: "setRewardPerBlock", args: [targetRpb] });
      await satPub.waitForTransactionReceipt({ hash: s });
      console.log(`  set rewardPerBlock ${formatEther(curRpb)} -> ${formatEther(targetRpb)} (tx ${s})`);
    } else {
      console.log("  rewardPerBlock already in sync");
    }
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
