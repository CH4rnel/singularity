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
 * stdout:
 *   {"broadcastTxHash":"<signature>"}                  — the moment it is sent
 *   {"txHash":"<solana_signature>","status":"success"} — after confirmation
 *
 * The first line is not cosmetic. A Solana signature exists nowhere until this
 * process prints it: if the relayer dies between sending and confirming, the
 * only record of a payout that already happened is on an explorer somebody has
 * to go and read. Laravel streams this stdout and writes the signature onto the
 * bridge request the instant it appears, so a crashed relay reconciles instead
 * of paying twice.
 */
import {
  Connection,
  Keypair,
  PublicKey,
  SystemProgram,
  Transaction,
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

  const { blockhash, lastValidBlockHeight } =
    await connection.getLatestBlockhash("confirmed");
  tx.recentBlockhash = blockhash;
  tx.feePayer = relayer.publicKey;
  tx.sign(relayer);

  const sig = await connection.sendRawTransaction(tx.serialize(), {
    maxRetries: 5,
  });
  // Print the signature BEFORE waiting on confirmation — see the header.
  console.log(JSON.stringify({ broadcastTxHash: sig }));

  const confirmation = await connection.confirmTransaction(
    { signature: sig, blockhash, lastValidBlockHeight },
    "confirmed",
  );

  if (confirmation.value.err) {
    throw new Error(
      `Solana transfer failed (${sig}): ${JSON.stringify(
        confirmation.value.err,
      )}`,
    );
  }

  console.log("TX:", sig);
  console.log(JSON.stringify({ txHash: sig, status: "success" }));
}

main().catch((e) => {
  console.error(e?.message ?? e);
  process.exit(1);
});
