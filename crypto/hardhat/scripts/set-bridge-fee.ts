/**
 * Set the on-chain fee for the CyberBridge contract.
 *
 * The bridge enforces its own fee (in basis points) inside redeemCyberSol —
 * separate from our backend BridgeFeeService. To make CYBER.sol fee-free at
 * the contract layer, set this to 0.
 *
 * Usage:
 *   npx tsx scripts/set-bridge-fee.ts <bps>
 *
 * env:
 *   BRIDGE_RELAYER_PRIVATE_KEY  — must own the bridge contract
 *   BRIDGE_EVM_CONTRACT_ADDRESS — CyberBridge address
 *   CYBERIA_RPC_URL             — RPC
 */
import "dotenv/config";
import { ethers } from "ethers";

const RELAYER_PK = process.env.BRIDGE_RELAYER_PRIVATE_KEY || process.env.DEPLOYER_PK;
const BRIDGE_ADDRESS = process.env.BRIDGE_EVM_CONTRACT_ADDRESS;
const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

if (!RELAYER_PK) {
  console.error("BRIDGE_RELAYER_PRIVATE_KEY (or DEPLOYER_PK) not set");
  process.exit(1);
}
if (!BRIDGE_ADDRESS) {
  console.error("BRIDGE_EVM_CONTRACT_ADDRESS not set");
  process.exit(1);
}

const BRIDGE_ABI = [
  "function feeBps() view returns (uint256)",
  "function setFeeBps(uint256)",
  "function owner() view returns (address)",
];

async function main() {
  const [, , bpsStr] = process.argv;
  if (bpsStr === undefined) {
    console.error("Usage: set-bridge-fee.ts <bps>   (e.g. 0 to disable, 100 for 1%)");
    process.exit(1);
  }

  const bps = BigInt(bpsStr);
  if (bps < 0n || bps > 1000n) {
    console.error("bps must be in [0, 1000] — contract caps at 10%");
    process.exit(1);
  }

  const pk = (RELAYER_PK!.startsWith("0x") ? RELAYER_PK! : `0x${RELAYER_PK}`) as `0x${string}`;
  const network = new ethers.Network("cyberia", 49406);
  const provider = new ethers.JsonRpcProvider(RPC_URL, network, { staticNetwork: network });
  const wallet = new ethers.Wallet(pk, provider);

  const bridge = new ethers.Contract(BRIDGE_ADDRESS!, BRIDGE_ABI, wallet);

  const owner = (await bridge.owner()) as string;
  if (owner.toLowerCase() !== wallet.address.toLowerCase()) {
    console.error(`Caller ${wallet.address} is not owner of ${BRIDGE_ADDRESS} (owner: ${owner})`);
    process.exit(1);
  }

  const before = await bridge.feeBps();
  console.log(`feeBps before: ${before}`);

  if (before === bps) {
    console.log("Already at target value — no-op");
    return;
  }

  const tx = await bridge.setFeeBps(bps);
  console.log(`tx: ${tx.hash}`);
  const receipt = await tx.wait();

  if (!receipt || receipt.status !== 1) {
    throw new Error(`setFeeBps reverted (tx ${tx.hash})`);
  }

  const after = await bridge.feeBps();
  console.log(`feeBps after:  ${after}`);
}

main().catch((e) => {
  console.error(e?.message ?? e);
  process.exit(1);
});
