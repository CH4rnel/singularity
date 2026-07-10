/**
 * Direct-model bridge relayer for native SOL payouts (evm_to_sol, SOL token).
 *
 * Plain SystemProgram.transfer from the hot wallet — the native counterpart
 * of relay-spl-transfer.ts.
 *
 * Usage:
 *   npx ts-node --transpile-only scripts/relay-sol-transfer.ts \
 *       <recipient_base58> <amount_lamports>
 *
 * env:
 *   ANCHOR_PROVIDER_URL — Solana RPC
 *   ANCHOR_WALLET      — path to relayer keypair JSON
 *
 * stdout (last line):
 *   {"txHash":"<solana_signature>","status":"success"}
 */
import {
  Connection,
  Keypair,
  PublicKey,
  SystemProgram,
  Transaction,
  sendAndConfirmTransaction,
} from "@solana/web3.js";
import fs from "fs";

async function main() {
  const [, , recipientBase58, amountStr] = process.argv;

  if (!recipientBase58 || !amountStr) {
    console.error(
      "Usage: relay-sol-transfer.ts <recipient_base58> <amount_lamports>",
    );
    process.exit(1);
  }

  const rpcUrl =
    process.env.ANCHOR_PROVIDER_URL || "https://api.mainnet-beta.solana.com";
  const walletPath = (process.env.ANCHOR_WALLET || "~/.config/solana/id.json").replace(
    "~",
    process.env.HOME || "/root",
  );

  const relayer = Keypair.fromSecretKey(
    Uint8Array.from(JSON.parse(fs.readFileSync(walletPath, "utf8"))),
  );

  const connection = new Connection(rpcUrl, "confirmed");
  const recipient = new PublicKey(recipientBase58);
  const lamports = BigInt(amountStr);

  console.log("Relayer:  ", relayer.publicKey.toBase58());
  console.log("Recipient:", recipient.toBase58());
  console.log("Lamports: ", lamports.toString());

  const tx = new Transaction().add(
    SystemProgram.transfer({
      fromPubkey: relayer.publicKey,
      toPubkey: recipient,
      lamports,
    }),
  );

  const sig = await sendAndConfirmTransaction(connection, tx, [relayer], {
    commitment: "confirmed",
  });

  console.log("TX:", sig);
  console.log(JSON.stringify({ txHash: sig, status: "success" }));
}

main().catch((e) => {
  console.error(e?.message ?? e);
  process.exit(1);
});
