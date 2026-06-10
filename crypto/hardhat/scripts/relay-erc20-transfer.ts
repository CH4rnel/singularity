/**
 * Direct-model bridge relayer for EVM destination.
 *
 * Called by Laravel after verifying that the user has deposited the source
 * token to the relayer hot wallet (on Solana for direction=sol_to_evm).
 *
 * Usage:
 *   npx tsx scripts/relay-erc20-transfer.ts <token_addr> <recipient> <amount_wei> [gas_drop_wei]
 *
 * env:
 *   BRIDGE_RELAYER_PRIVATE_KEY — relayer EOA (sends the tokens + optional gas)
 *   CYBERIA_RPC_URL           — Cyberia EVM RPC
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

const RPC_URL = process.env.CYBERIA_RPC_URL || "https://rpc.cyberia.church";

const ERC20_ABI = [
  "function transfer(address to, uint256 amount) returns (bool)",
];

async function main() {
  const [, , tokenAddr, recipient, amountWei, gasDropWei] = process.argv;

  if (!tokenAddr || !recipient || !amountWei) {
    console.error(
      "Usage: relay-erc20-transfer.ts <token_addr> <recipient> <amount_wei> [gas_drop_wei]",
    );
    process.exit(1);
  }

  const pk = (RELAYER_PK!.startsWith("0x") ? RELAYER_PK! : `0x${RELAYER_PK}`) as `0x${string}`;
  // staticNetwork avoids an extra eth_chainId roundtrip per call.
  const network = new ethers.Network("cyberia", 49406);
  const provider = new ethers.JsonRpcProvider(RPC_URL, network, {
    staticNetwork: network,
    polling: false,
  });
  const wallet = new ethers.Wallet(pk, provider);

  console.log(`Relayer: ${wallet.address}`);
  console.log(`Token:   ${tokenAddr}`);
  console.log(`To:      ${recipient}`);
  console.log(`Amount:  ${amountWei}`);

  const token = new ethers.Contract(tokenAddr, ERC20_ABI, wallet);
  const tx = await token.transfer(recipient, BigInt(amountWei));
  const receipt = await tx.wait();

  if (!receipt || receipt.status !== 1) {
    throw new Error(`ERC20.transfer reverted (tx ${tx.hash})`);
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
