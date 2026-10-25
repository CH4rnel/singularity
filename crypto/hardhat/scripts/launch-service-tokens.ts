import "dotenv/config";
import * as fs from "fs";
import * as path from "path";
import { createPublicClient, createWalletClient, decodeEventLog, http, type Abi, type Hex } from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK not set in .env");

const account = privateKeyToAccount(
  (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as Hex,
);

const RPC_URL = process.env.CYBERIA_RPC_URL ?? "https://rpc.cyberia.church";
const LIVE = process.env.LAUNCH === "1";
const ONLY = (process.env.ONLY ?? "").split(",").map((s) => s.trim().toUpperCase()).filter(Boolean);

const chain = {
  ...mainnet,
  id: 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });
const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });

const ROOT = path.resolve(import.meta.dirname, "..");
const PLAN = path.join(ROOT, "deployments", "cyberia-service-tokens.json");
const V3 = path.join(ROOT, "deployments", "cyberia-v3.json");

const LAUNCH_GAS = 12_000_000n;
const CALL_GAS = 300_000n;
const MIN_GAS_PRICE = 1_500_000_000n;

type Row = {
  symbol: string;
  name: string;
  service: string;
  treasury: `0x${string}` | null;
  address: `0x${string}` | null;
  pool?: `0x${string}`;
  positionId?: string;
  tx?: `0x${string}`;
};

function artifact(name: string): Abi {
  const matches: string[] = [];
  const walk = (dir: string) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (entry.name === `${name}.json`) matches.push(full);
    }
  };
  walk(path.join(ROOT, "artifacts", "contracts"));
  if (matches.length === 0) throw new Error(`artifact ${name} not found -- run: npx hardhat compile`);
  return JSON.parse(fs.readFileSync(matches[0], "utf8")).abi as Abi;
}

async function gasPrice(): Promise<bigint> {
  const bumped = ((await publicClient.getGasPrice()) * 3n) / 2n;
  return bumped > MIN_GAS_PRICE ? bumped : MIN_GAS_PRICE;
}

async function main() {
  const plan = JSON.parse(fs.readFileSync(PLAN, "utf8"));
  const v3 = JSON.parse(fs.readFileSync(V3, "utf8"));
  const launchpad = v3.LaunchpadV3 as `0x${string}`;
  const locker = v3.LaunchLocker as `0x${string}`;
  const launchpadAbi = artifact("LaunchpadV3");
  const lockerAbi = artifact("LaunchLocker");

  const supply = BigInt(plan.defaults.supply);
  const value = BigInt(plan.defaults.liquidityCyber);
  const creatorFee = Number(plan.defaults.creatorFee);
  const holdersBps = Number(plan.defaults.holdersShareBps);

  const minLiquidity = (await publicClient.readContract({
    address: launchpad,
    abi: launchpadAbi,
    functionName: "minLiquidity",
  })) as bigint;
  if (value < minLiquidity) throw new Error(`liquidityCyber ${value} is under the launchpad minimum ${minLiquidity}`);

  const pending = (plan.tokens as Row[]).filter(
    (row) => !row.address && (ONLY.length === 0 || ONLY.includes(row.symbol)),
  );
  const balance = await publicClient.getBalance({ address: account.address });
  console.log(`${LIVE ? "LAUNCH" : "dry run"}: ${pending.length} token(s) from ${account.address}`);
  console.log(`  needs ${(Number(value * BigInt(pending.length)) / 1e18).toFixed(2)} CYBER + gas, holds ${(Number(balance) / 1e18).toFixed(2)}`);

  const args = (row: Row) => [row.name, row.symbol, supply, creatorFee, holdersBps] as const;

  for (const row of pending) {
    if (!LIVE) {
      const { result } = await publicClient.simulateContract({
        address: launchpad,
        abi: launchpadAbi,
        functionName: "launch",
        args: args(row) as never,
        value,
        account: account.address,
        gas: LAUNCH_GAS,
      });
      console.log(`  ok   ${row.symbol.padEnd(8)} would be ${(result as [string])[0]}`);
      continue;
    }

    const hash = await walletClient.writeContract({
      address: launchpad,
      abi: launchpadAbi,
      functionName: "launch",
      args: args(row) as never,
      value,
      gas: LAUNCH_GAS,
      gasPrice: await gasPrice(),
    });
    const receipt = await publicClient.waitForTransactionReceipt({ hash });
    if (receipt.status !== "success") throw new Error(`${row.symbol}: launch reverted (${hash})`);

    for (const log of receipt.logs) {
      try {
        const parsed = decodeEventLog({ abi: launchpadAbi, ...log });
        const a = parsed.args as unknown as { token?: `0x${string}`; pool?: `0x${string}`; positionId?: bigint };
        if (parsed.eventName === "TokenLaunched") {
          row.address = a.token!;
          row.pool = a.pool!;
        }
        if (parsed.eventName === "LaunchTerms") row.positionId = a.positionId!.toString();
      } catch {
        /* not one of ours */
      }
    }
    if (!row.address) throw new Error(`${row.symbol}: launched in ${hash} but no TokenLaunched log`);
    row.tx = hash;
    fs.writeFileSync(PLAN, JSON.stringify(plan, null, 2) + "\n");
    console.log(`  done ${row.symbol.padEnd(8)} ${row.address}  ${hash}`);

    if (row.treasury && row.positionId && row.treasury.toLowerCase() !== account.address.toLowerCase()) {
      const handoff = await walletClient.writeContract({
        address: locker,
        abi: lockerAbi,
        functionName: "setCreator",
        args: [BigInt(row.positionId), row.treasury],
        gas: CALL_GAS,
        gasPrice: await gasPrice(),
      });
      await publicClient.waitForTransactionReceipt({ hash: handoff });
      console.log(`       fees  -> ${row.treasury}  ${handoff}`);
    }
  }
  if (LIVE) console.log("\nThe launch watcher opens each token's DAO and feed card within a minute.");
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
