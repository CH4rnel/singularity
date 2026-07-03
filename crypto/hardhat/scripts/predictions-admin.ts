/**
 * Oracle CLI for the PredictionMarket at deployments/cyberia-predictions.json.
 * The DEPLOYER_PK account must be the contract owner.
 *
 * Usage (npx tsx scripts/predictions-admin.ts <command> ...):
 *   list
 *   create "Will CYBER hit $1 by 2027?" +72h     # close in 72 hours
 *   create "…" 2026-12-31T00:00:00Z              # or an explicit ISO time
 *   resolve <id> yes|no|invalid
 *   set-fee <bps>
 *   set-create-fee <cyber>                       # flat market-creation fee
 *
 * Env: DEPLOYER_PK (required), CYBERIA_RPC_URL (optional).
 */

import "dotenv/config";
import * as fs from "node:fs";
import * as path from "node:path";
import {
  createPublicClient,
  createWalletClient,
  formatEther,
  http,
  parseEther,
  type Abi,
} from "viem";
import { privateKeyToAccount } from "viem/accounts";
import { mainnet } from "viem/chains";

const DEPLOYMENT_PATH = path.resolve("deployments/cyberia-predictions.json");

const deployment = JSON.parse(fs.readFileSync(DEPLOYMENT_PATH, "utf8")) as {
  chainId: number;
  rpc: string;
  PredictionMarket: `0x${string}`;
};

const DEPLOYER_PK = process.env.DEPLOYER_PK;
if (!DEPLOYER_PK) throw new Error("DEPLOYER_PK is not set in .env");
const pk = (DEPLOYER_PK.startsWith("0x") ? DEPLOYER_PK : `0x${DEPLOYER_PK}`) as `0x${string}`;
const account = privateKeyToAccount(pk);

const RPC_URL =
  process.env.CYBERIA_RPC_URL ?? deployment.rpc ?? "https://rpc.cyberia.church";

const chain = {
  ...mainnet,
  id: deployment.chainId ?? 49406,
  name: "Cyberia",
  nativeCurrency: { name: "Cyber", symbol: "CYBER", decimals: 18 },
};

const publicClient = createPublicClient({ chain, transport: http(RPC_URL) });
const walletClient = createWalletClient({ chain, transport: http(RPC_URL), account });

const abi = (
  JSON.parse(
    fs.readFileSync(
      path.resolve("artifacts/contracts/PredictionMarket.sol/PredictionMarket.json"),
      "utf8",
    ),
  ) as { abi: Abi }
).abi;

const OUTCOME_NAMES = ["open", "YES", "NO", "INVALID"] as const;
const OUTCOME_ARG: Record<string, number> = { yes: 1, no: 2, invalid: 3 };

type MarketView = {
  id: bigint;
  creator: string;
  question: string;
  closeTime: bigint;
  resolvedAt: bigint;
  outcome: number;
  yesPool: bigint;
  noPool: bigint;
  feePaid: bigint;
};

function parseCloseTime(arg: string): bigint {
  const rel = /^\+(\d+)h$/.exec(arg);
  if (rel) {
    return BigInt(Math.floor(Date.now() / 1000) + Number(rel[1]) * 3600);
  }
  const ts = Date.parse(arg);
  if (Number.isNaN(ts)) {
    throw new Error(`Cannot parse close time "${arg}" (use +72h or ISO 8601)`);
  }
  return BigInt(Math.floor(ts / 1000));
}

async function write(functionName: string, args: unknown[]) {
  const hash = await walletClient.writeContract({
    address: deployment.PredictionMarket,
    abi,
    functionName,
    args,
  });
  console.log("tx:", hash);
  const receipt = await publicClient.waitForTransactionReceipt({ hash });
  if (receipt.status !== "success") {
    throw new Error(`${functionName} reverted`);
  }
  console.log("ok (gas:", receipt.gasUsed.toString() + ")");
}

async function list() {
  const count = (await publicClient.readContract({
    address: deployment.PredictionMarket,
    abi,
    functionName: "marketCount",
  })) as bigint;
  console.log(`PredictionMarket ${deployment.PredictionMarket} — ${count} market(s)\n`);

  const markets = (await publicClient.readContract({
    address: deployment.PredictionMarket,
    abi,
    functionName: "getMarkets",
    args: [0n, count],
  })) as MarketView[];

  for (const m of markets) {
    const close = new Date(Number(m.closeTime) * 1000).toISOString();
    console.log(
      `#${m.id} [${OUTCOME_NAMES[m.outcome]}] ${m.question}\n` +
        `    by ${m.creator} | closes ${close} | YES ${formatEther(m.yesPool)} | NO ${formatEther(m.noPool)}` +
        (m.feePaid > 0n ? ` | fee ${formatEther(m.feePaid)}` : ""),
    );
  }
}

async function main() {
  const [cmd, ...rest] = process.argv.slice(2);

  switch (cmd) {
    case "list":
      await list();
      break;
    case "create": {
      const [question, when] = rest;
      if (!question || !when) throw new Error('Usage: create "<question>" <+72h|ISO>');
      const closeTime = parseCloseTime(when);
      console.log(`Creating "${question}" closing ${new Date(Number(closeTime) * 1000).toISOString()}`);
      await write("createMarket", [question, closeTime]);
      break;
    }
    case "resolve": {
      const [id, outcomeArg] = rest;
      const outcome = OUTCOME_ARG[outcomeArg?.toLowerCase() ?? ""];
      if (!id || !outcome) throw new Error("Usage: resolve <id> yes|no|invalid");
      await write("resolve", [BigInt(id), outcome]);
      break;
    }
    case "set-fee": {
      const [bps] = rest;
      if (!bps) throw new Error("Usage: set-fee <bps>");
      await write("setFeeBps", [BigInt(bps)]);
      break;
    }
    case "set-create-fee": {
      const [cyber] = rest;
      if (!cyber) throw new Error("Usage: set-create-fee <cyber>");
      await write("setCreateFee", [parseEther(cyber)]);
      break;
    }
    default:
      console.log(
        'Commands:\n  list\n  create "<question>" <+72h|ISO>\n  resolve <id> yes|no|invalid\n  set-fee <bps>\n  set-create-fee <cyber>',
      );
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
