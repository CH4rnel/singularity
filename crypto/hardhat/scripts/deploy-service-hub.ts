/**
 * Deploy ServiceHub and list the fourteen service tokens launched on 2026-10-25.
 *
 * Each token's lock id is found on chain, never typed: LaunchLocker records every launch with
 * `rewardsTo` = the launched token, so the position whose `rewardsTo` is a service token is that
 * service's pool.
 *
 * Moving the creator share onto the hub (`LaunchLocker.setCreator(id, hub)`) is a separate,
 * explicit step: it redirects real fees, and only the current creator key may sign it. After it,
 * only the hub's owner can move the share on again (`releaseLock`).
 *
 * Usage:
 *   npx hardhat compile
 *   npx tsx scripts/deploy-service-hub.ts                         # dry run: find lock ids, print the plan
 *   DEPLOY=1 npx tsx scripts/deploy-service-hub.ts                # deploy + list services
 *   HUB=0x… SET_CREATOR=1 npx tsx scripts/deploy-service-hub.ts   # route each pool's creator share to the hub
 *
 * Env:
 *   OPERATOR            who runs every treasury until DAO executors exist (default: the deployer)
 *   HOLDERS_BPS         share of each inflow paid to holders (default 5000)
 *   PROTOCOL_FEE_BPS    cut of a payment for the fee address, max 100 (default 100)
 *   CYBERIA_RPC_URL, CYBERIA_CHAIN_ID
 */

import "dotenv/config";
import * as fs from "fs";
import * as path from "path";
import { createPublicClient, createWalletClient, getAddress, http, parseAbi, type Abi, type Hex } from "viem";
import { privateKeyToAccount } from "viem/accounts";

// Every fee in this project goes here (Lev, 2026-10-25) — never to the launch key.
const FEE_RECIPIENT = "0x54BB8a86Fc001d5BB3e6C7ad2e2f153E5Fa6d7f6";

const SERVICE_TOKENS: Record<string, string> = {
  NFT: "0x8bb022060e4946B9498D11f3e87666dc7129CcbC",
  LEND: "0xba6BDDa1988E72e796032C1cEBFc4192aC5A8497",
  PRED: "0x0A2d855D70728fEA342C338f3668b91ECc330CB5",
  FARM: "0x9ba523B368Fd338D45594616b4515f6D421b21d2",
  STAKE: "0x0F5CEE1F315A84E109B5817839B5474E0ca7431d",
  LAUNCH: "0xE17f466E3501d80650F955C66a8AD4336BfD17C8",
  BRIDGE: "0x82708a8d873CA89F9F341307ccA949a4a5a240F3",
  WALLET: "0xBf763e079584a35d19968FD5e2D528087D9bc3FE",
  CLOUD: "0xdb003B50DD2f5E9dcD1D177cA07d85cf3E3c6b48",
  INFER: "0xdd390535a353317625bCD8c27582abcc5e151186",
  IPFS: "0x28084A351a87F20219EF04e596B6d3b84dd0Afd3",
  SEED: "0x7d01915b2ef0C5931f2520b7fa2523d1bD6B0CB5",
  SCAN: "0xDE99A012A910419736E4c33228DF6972fBcF0E72",
  GAS: "0x7911085dc26825349b0ce2518b72D49fF4a26Ee8",
};

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");
const account = privateKeyToAccount((DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as Hex);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const CHAIN_ID = Number(process.env.CYBERIA_CHAIN_ID ?? 49406);
const chain = {
  id: CHAIN_ID,
  name: "Cyberia",
  nativeCurrency: { name: "CYBER", symbol: "CYBER", decimals: 18 },
  rpcUrls: { default: { http: [RPC_URL] } },
} as const;

const ROOT = path.resolve(import.meta.dirname, "..");
const V3 = JSON.parse(fs.readFileSync(path.join(ROOT, "deployments", "cyberia-v3.json"), "utf8"));
const OUT = path.join(ROOT, "deployments", "cyberia-service-hub.json");

const OPERATOR = getAddress(process.env.OPERATOR ?? account.address);
const HOLDERS_BPS = Number(process.env.HOLDERS_BPS ?? 5000);
const PROTOCOL_FEE_BPS = Number(process.env.PROTOCOL_FEE_BPS ?? 100);

const lockerAbi = parseAbi([
  "function lockedCount() view returns (uint256)",
  "function lockedIds(uint256) view returns (uint256)",
  "function locks(uint256) view returns (address creator, uint16 creatorBps, uint16 holdersBps, address rewardsTo, bool locked)",
  "function setCreator(uint256 tokenId, address to)",
]);

function hubArtifact(): { abi: Abi; bytecode: Hex } {
  const file = path.join(ROOT, "artifacts", "contracts", "ServiceHub.sol", "ServiceHub.json");
  if (!fs.existsSync(file)) throw new Error("ServiceHub artifact missing -- run: npx hardhat compile");
  const json = JSON.parse(fs.readFileSync(file, "utf8"));
  return { abi: json.abi, bytecode: json.bytecode };
}

async function main() {
  const pub = createPublicClient({ chain, transport: http(RPC_URL) });
  const wallet = createWalletClient({ chain, transport: http(RPC_URL), account });
  const locker = getAddress(V3.LaunchLocker);

  const count = await pub.readContract({ address: locker, abi: lockerAbi, functionName: "lockedCount" });
  const lockOf = new Map<string, { id: bigint; creator: string }>();
  for (let i = 0n; i < count; i++) {
    const id = await pub.readContract({ address: locker, abi: lockerAbi, functionName: "lockedIds", args: [i] });
    const [creator, , , rewardsTo] = await pub.readContract({ address: locker, abi: lockerAbi, functionName: "locks", args: [id] });
    lockOf.set(rewardsTo.toLowerCase(), { id, creator });
  }

  const plan = Object.entries(SERVICE_TOKENS).map(([symbol, token]) => {
    const lock = lockOf.get(token.toLowerCase());
    return { symbol, token: getAddress(token), lockId: lock?.id ?? 0n, creator: lock?.creator ?? null };
  });
  console.table(plan.map((p) => ({ ...p, lockId: p.lockId.toString() })));
  const missing = plan.filter((p) => p.lockId === 0n);
  if (missing.length > 0) console.warn(`no lock found for ${missing.map((p) => p.symbol).join(", ")}: listed without pool fees`);

  const { abi, bytecode } = hubArtifact();

  if (process.env.SET_CREATOR === "1") {
    const hub = getAddress(process.env.HUB ?? JSON.parse(fs.readFileSync(OUT, "utf8")).ServiceHub);
    for (const p of plan) {
      if (p.lockId === 0n || p.creator?.toLowerCase() !== account.address.toLowerCase()) {
        console.log(`${p.symbol}: skipped (creator is ${p.creator})`);
        continue;
      }
      const hash = await wallet.writeContract({ address: locker, abi: lockerAbi, functionName: "setCreator", args: [p.lockId, hub] });
      await pub.waitForTransactionReceipt({ hash });
      console.log(`${p.symbol}: creator share -> hub (${hash})`);
    }
    return;
  }

  if (process.env.DEPLOY !== "1") {
    console.log(`dry run: would deploy ServiceHub(WCYBER ${V3.WCYBER}, locker ${locker}, fees ${FEE_RECIPIENT}, ${PROTOCOL_FEE_BPS} bps)`);
    console.log(`and list ${plan.length} services, operator ${OPERATOR}, holders ${HOLDERS_BPS} bps. DEPLOY=1 to do it.`);
    return;
  }

  const deployHash = await wallet.deployContract({
    abi,
    bytecode,
    args: [getAddress(V3.WCYBER), locker, FEE_RECIPIENT, PROTOCOL_FEE_BPS],
  });
  const receipt = await pub.waitForTransactionReceipt({ hash: deployHash });
  const hub = getAddress(receipt.contractAddress!);
  console.log(`ServiceHub ${hub}`);

  for (const p of plan) {
    const hash = await wallet.writeContract({
      address: hub,
      abi,
      functionName: "listService",
      args: [p.token, OPERATOR, p.lockId, HOLDERS_BPS],
    });
    await pub.waitForTransactionReceipt({ hash });
    console.log(`${p.symbol} listed (lock ${p.lockId})`);
  }

  fs.writeFileSync(
    OUT,
    JSON.stringify(
      {
        chainId: CHAIN_ID,
        ServiceHub: hub,
        deployTx: deployHash,
        feeRecipient: FEE_RECIPIENT,
        protocolFeeBps: PROTOCOL_FEE_BPS,
        holdersBps: HOLDERS_BPS,
        operator: OPERATOR,
        services: plan.map((p) => ({ symbol: p.symbol, token: p.token, lockId: p.lockId.toString() })),
      },
      null,
      2,
    ) + "\n",
  );
  console.log(`wrote ${OUT}. Next: SET_CREATOR=1 to route pool fees to the hub; CYBERIA_SERVICE_HUB_ADDRESS=${hub} on the site.`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
