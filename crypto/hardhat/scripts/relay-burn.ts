/**
 * Mint-model bridge relayer — burn step for evm_to_sol bridges.
 *
 * After the user has transferred wrapped-USDC/USDT to the relayer EOA, the
 * relayer burns its received balance so the EVM-side supply stays in sync
 * with the Solana hot-wallet reserve. Uses burnFrom(owner, amount) which is
 * gated to the owner without requiring allowance.
 *
 * Usage:
 *   npx tsx scripts/relay-burn.ts <token_addr> <amount_wei>
 *
 * env:
 *   BRIDGE_RELAYER_PRIVATE_KEY — relayer EOA (must be owner of the token contract)
 *   CYBERIA_RPC_URL           — Cyberia EVM RPC
 *
 * stdout (last line):
 *   {"txHash":"0x..."}
 */
import "dotenv/config";
import { ethers } from "ethers";

const RELAYER_PK = process.env.BRIDGE_RELAYER_PRIVATE_KEY || process.env.DEPLOYER_PK;

if (!RELAYER_PK) {
  console.error("BRIDGE_RELAYER_PRIVATE_KEY or DEPLOYER_PK not set");
  process.exit(1);
}

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const BURNABLE_ABI = [
  "function burnFrom(address from, uint256 amount)",
];

async function main() {
  const [, , tokenAddr, amountWei] = process.argv;

  if (!tokenAddr || !amountWei) {
    console.error("Usage: relay-burn.ts <token_addr> <amount_wei>");
    process.exit(1);
  }

  const pk = (RELAYER_PK!.startsWith("0x") ? RELAYER_PK! : `0x${RELAYER_PK}`) as `0x${string}`;
  const network = new ethers.Network("cyberia", 49406);
  const provider = new ethers.JsonRpcProvider(RPC_URL, network, {
    staticNetwork: network,
    polling: false,
  });
  const wallet = new ethers.Wallet(pk, provider);

  console.log(`Relayer: ${wallet.address}`);
  console.log(`Token:   ${tokenAddr}`);
  console.log(`Burn:    ${amountWei} wei from relayer's own balance`);

  const token = new ethers.Contract(tokenAddr, BURNABLE_ABI, wallet);
  const tx = await token.burnFrom(wallet.address, BigInt(amountWei));
  const receipt = await tx.wait();

  if (!receipt || receipt.status !== 1) {
    throw new Error(`burnFrom() reverted (tx ${tx.hash})`);
  }

  console.log(JSON.stringify({ txHash: receipt.hash }));
}

main().catch((e) => {
  console.error(e?.message ?? e);
  process.exit(1);
});
