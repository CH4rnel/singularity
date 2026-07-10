/**
 * Backend relayer for the CyberiaProfile contract (nicknames + achievements).
 * Invoked by the Laravel backend (ProfileOnchainService) with the relayer key.
 *
 * Usage:
 *   npx tsx scripts/profile-admin.ts award <contract> <user> <id> [<id> ...]
 *   npx tsx scripts/profile-admin.ts set-nickname <contract> <user> <nickname>
 *
 * env:
 *   BRIDGE_RELAYER_PRIVATE_KEY / DEPLOYER_PK — contract owner EOA
 *   EVM_RPC_URL / CYBERIA_RPC_URL            — Cyberia RPC
 *
 * stdout (last line):
 *   {"txHash":"0x...","status":"success"}
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

const PROFILE_ABI = [
  "function award(address user, uint256 id)",
  "function awardBatch(address[] users, uint256[] ids)",
  "function setNicknameFor(address user, string nickname)",
];

async function main() {
  const [, , action, contractAddr, user, ...rest] = process.argv;

  if (!action || !contractAddr || !user || rest.length === 0) {
    console.error(
      "Usage: profile-admin.ts award <contract> <user> <id> [<id> ...]\n" +
        "       profile-admin.ts set-nickname <contract> <user> <nickname>",
    );
    process.exit(1);
  }

  const pk = (
    RELAYER_PK!.startsWith("0x") ? RELAYER_PK! : `0x${RELAYER_PK}`
  ) as `0x${string}`;
  const network = new ethers.Network("cyberia", CHAIN_ID);
  const provider = new ethers.JsonRpcProvider(RPC_URL, network, {
    staticNetwork: network,
  });
  const wallet = new ethers.Wallet(pk, provider);
  const profile = new ethers.Contract(contractAddr, PROFILE_ABI, wallet);

  let tx;

  if (action === "award") {
    const ids = rest.map((id) => BigInt(id));

    tx =
      ids.length === 1
        ? await profile.award(user, ids[0])
        : await profile.awardBatch(
            ids.map(() => user),
            ids,
          );
  } else if (action === "set-nickname") {
    tx = await profile.setNicknameFor(user, rest[0]);
  } else {
    console.error(`Unknown action: ${action}`);
    process.exit(1);
  }

  console.log("TX:", tx.hash);
  const receipt = await tx.wait();

  if (!receipt || receipt.status !== 1) {
    console.error("Transaction reverted");
    process.exit(1);
  }

  console.log(JSON.stringify({ txHash: tx.hash, status: "success" }));
}

main().catch((e) => {
  console.error(e?.reason ?? e?.message ?? e);
  process.exit(1);
});
