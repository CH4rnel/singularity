/**
 * Mint-model bridge relayer for EVM destination.
 *
 * Used when the EVM-side token is a wrapper owned by the relayer EOA
 * (USDC, USDT on Cyberia). The relayer calls token.mint(recipient, amount)
 * directly — no inventory needed.
 *
 * Usage:
 *   npx tsx scripts/relay-mint.ts <token_addr> <recipient> <amount_wei> [gas_drop_wei]
 *
 * env:
 *   BRIDGE_RELAYER_PRIVATE_KEY — relayer EOA (must be owner of the token contract)
 *   EVM_RPC_URL / EVM_CHAIN_ID — target EVM chain (default Cyberia via
 *   CYBERIA_RPC_URL / 49406)
 *
 * stdout (last line):
 *   {"txHash":"0x...","gasDropTxHash":"0x..."|null}
 */
import "dotenv/config";
import { ethers } from "ethers";

const RELAYER_PK = process.env.BRIDGE_RELAYER_PRIVATE_KEY || process.env.DEPLOYER_PK;

if (!RELAYER_PK) {
  console.error("BRIDGE_RELAYER_PRIVATE_KEY or DEPLOYER_PK not set");
  process.exit(1);
}

// Generic EVM chain params (BSC etc.); legacy Cyberia vars as fallback.
const RPC_URL =
  process.env.EVM_RPC_URL ||
  process.env.CYBERIA_RPC_URL ||
  "https://rpc.cyberia.church";
const CHAIN_ID = Number(process.env.EVM_CHAIN_ID || 49406);
const CHAIN_NAME = process.env.EVM_NETWORK_NAME || "evm";

const MINTABLE_ABI = [
  "function mint(address to, uint256 amount)",
];

async function main() {
  const [, , tokenAddr, recipient, amountWei, gasDropWei] = process.argv;

  if (!tokenAddr || !recipient || !amountWei) {
    console.error(
      "Usage: relay-mint.ts <token_addr> <recipient> <amount_wei> [gas_drop_wei]",
    );
    process.exit(1);
  }

  const pk = (RELAYER_PK!.startsWith("0x") ? RELAYER_PK! : `0x${RELAYER_PK}`) as `0x${string}`;
  const network = new ethers.Network(CHAIN_NAME, CHAIN_ID);
  const provider = new ethers.JsonRpcProvider(RPC_URL, network, {
    staticNetwork: network,
    polling: false,
  });
  const wallet = new ethers.Wallet(pk, provider);

  console.log(`Relayer: ${wallet.address}`);
  console.log(`Token:   ${tokenAddr}`);
  console.log(`To:      ${recipient}`);
  console.log(`Amount:  ${amountWei}`);

  const token = new ethers.Contract(tokenAddr, MINTABLE_ABI, wallet);
  const tx = await token.mint(recipient, BigInt(amountWei));
  const receipt = await tx.wait();

  if (!receipt || receipt.status !== 1) {
    throw new Error(`mint() reverted (tx ${tx.hash})`);
  }

  let gasDropTxHash: string | null = null;

  if (gasDropWei && BigInt(gasDropWei) > 0n) {
    console.log(`GasDrop: ${gasDropWei} wei`);
    const drop = await wallet.sendTransaction({
      to: recipient,
      value: BigInt(gasDropWei),
    });
    const dropReceipt = await drop.wait();

    if (!dropReceipt || dropReceipt.status !== 1) {
      throw new Error(`Gas drop transfer reverted (tx ${drop.hash})`);
    }

    gasDropTxHash = drop.hash;
  }

  console.log(JSON.stringify({ txHash: receipt.hash, gasDropTxHash }));
}

main().catch((e) => {
  console.error(e?.message ?? e);
  process.exit(1);
});
