/**
 * Backend oracle for the PredictionMarket contract.
 *
 * Invoked by Laravel (App\Services\Predictions\PredictionsResolver) with the
 * relayer key, mirroring scripts/profile-admin.ts. The decision of *what* to
 * resolve is made in PHP, where the price feeds live; this script only signs.
 * Keeping it that way means the key never has to see a price, and the thing
 * holding the key has no opinion about the outcome.
 *
 * Usage:
 *   npx tsx scripts/predictions-resolve.ts <contract> <id>:<outcome> [...]
 *     outcome: 1 = YES, 2 = NO, 3 = INVALID (cancel, refunds everyone)
 *
 * env:
 *   BRIDGE_RELAYER_PRIVATE_KEY / DEPLOYER_PK — contract owner EOA
 *   EVM_RPC_URL / CYBERIA_RPC_URL            — Cyberia RPC
 *
 * stdout: one JSON line per market, in order. A market that reverts does not
 * stop the ones after it — each line carries its own ok/error, and the caller
 * decides. Exit code is non-zero only when nothing at all could be sent.
 */
import "dotenv/config";
import { ethers } from "ethers";

const RELAYER_PK =
  process.env.BRIDGE_RELAYER_PRIVATE_KEY || process.env.DEPLOYER_PK;

if (!RELAYER_PK) {
  console.error("BRIDGE_RELAYER_PRIVATE_KEY or DEPLOYER_PK not set");
  process.exit(1);
}

const RPC_URL =
  process.env.EVM_RPC_URL ||
  process.env.CYBERIA_RPC_URL ||
  "https://rpc.cyberia.church";
const CHAIN_ID = Number(process.env.EVM_CHAIN_ID || 49406);

const PREDICTIONS_ABI = [
  "function resolve(uint256 id, uint8 outcome)",
  "function owner() view returns (address)",
];

type Job = { id: bigint; outcome: number };

function parseJobs(args: string[]): Job[] {
  return args.map((arg) => {
    const [rawId, rawOutcome] = arg.split(":");
    const id = BigInt(rawId ?? "");
    const outcome = Number(rawOutcome);

    if (![1, 2, 3].includes(outcome)) {
      throw new Error(`Bad outcome in "${arg}" (want 1=YES, 2=NO, 3=INVALID)`);
    }

    return { id, outcome };
  });
}

async function main() {
  const [, , contractAddr, ...rest] = process.argv;

  if (!contractAddr || rest.length === 0) {
    console.error(
      "Usage: predictions-resolve.ts <contract> <id>:<outcome> [<id>:<outcome> ...]",
    );
    process.exit(1);
  }

  const jobs = parseJobs(rest);
  const pk = (
    RELAYER_PK!.startsWith("0x") ? RELAYER_PK! : `0x${RELAYER_PK}`
  ) as `0x${string}`;
  const network = new ethers.Network("cyberia", CHAIN_ID);
  const provider = new ethers.JsonRpcProvider(RPC_URL, network, {
    staticNetwork: network,
  });
  const wallet = new ethers.Wallet(pk, provider);
  const market = new ethers.Contract(contractAddr, PREDICTIONS_ABI, wallet);

  const owner: string = await market.owner();

  if (owner.toLowerCase() !== (await wallet.getAddress()).toLowerCase()) {
    console.error(
      `Signer is not the oracle: contract owner is ${owner}. resolve() would revert.`,
    );
    process.exit(1);
  }

  // Strictly sequential, each awaiting its receipt. This key is shared with the
  // bridge relayer and the token minter, and firing several transactions from
  // it in parallel is how "replacement transaction underpriced" happens.
  for (const job of jobs) {
    try {
      const tx = await market.resolve(job.id, job.outcome);
      const receipt = await tx.wait();

      console.log(
        JSON.stringify({
          id: Number(job.id),
          outcome: job.outcome,
          txHash: tx.hash,
          ok: receipt?.status === 1,
        }),
      );
    } catch (e) {
      const error = e as { shortMessage?: string; reason?: string; message?: string };

      console.log(
        JSON.stringify({
          id: Number(job.id),
          outcome: job.outcome,
          ok: false,
          error: error?.reason ?? error?.shortMessage ?? error?.message ?? String(e),
        }),
      );
    }
  }
}

main().catch((e) => {
  console.error(e?.reason ?? e?.message ?? e);
  process.exit(1);
});
