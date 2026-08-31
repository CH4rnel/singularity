import { Connection, Keypair, PublicKey, Transaction } from "@solana/web3.js";
import {
  getAssociatedTokenAddress,
  createTransferInstruction,
  createAssociatedTokenAccountInstruction,
  getAccount,
  TOKEN_2022_PROGRAM_ID,
} from "@solana/spl-token";
import fs from "fs";

const NATIVE_MINT = new PublicKey("E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump"); // ← mainnet минт
const TOKEN_PROGRAM = TOKEN_2022_PROGRAM_ID;

async function main() {
  const [,, recipientBase58, amountStr] = process.argv;
  if (!recipientBase58 || !amountStr) {
    console.error("Usage: relay-release-native.ts <recipient_base58> <amount_raw>");
    process.exit(1);
  }

  const rpcUrl = process.env.ANCHOR_PROVIDER_URL || "https://api.mainnet-beta.solana.com";
  const walletPath = (process.env.ANCHOR_WALLET || "~/.config/solana/id.json")
    .replace("~", process.env.HOME || "/root");

  const relayer = Keypair.fromSecretKey(
    Uint8Array.from(JSON.parse(fs.readFileSync(walletPath, "utf8")))
  );
  const connection = new Connection(rpcUrl, "confirmed");
  const recipient = new PublicKey(recipientBase58);
  const amount = BigInt(amountStr);

  // ← TOKEN_PROGRAM во все вызовы
  const relayerAta = await getAssociatedTokenAddress(NATIVE_MINT, relayer.publicKey, false, TOKEN_PROGRAM);
  const recipientAta = await getAssociatedTokenAddress(NATIVE_MINT, recipient, false, TOKEN_PROGRAM);

  console.log("Relayer:", relayer.publicKey.toBase58());
  console.log("Recipient:", recipient.toBase58());
  console.log("Amount:", amount.toString());

  const tx = new Transaction();

  try {
    await getAccount(connection, recipientAta, "confirmed", TOKEN_PROGRAM);
  } catch {
    console.log("Creating recipient ATA...");
    tx.add(
      createAssociatedTokenAccountInstruction(
        relayer.publicKey,
        recipientAta,
        recipient,
        NATIVE_MINT,
        TOKEN_PROGRAM, 
      ),
    );
  }

  tx.add(
    createTransferInstruction(
      relayerAta,
      recipientAta,
      relayer.publicKey,
      amount,
      [],
      TOKEN_PROGRAM, 
    ),
  );

  const { blockhash, lastValidBlockHeight } =
    await connection.getLatestBlockhash("confirmed");
  tx.recentBlockhash = blockhash;
  tx.feePayer = relayer.publicKey;
  tx.sign(relayer);

  const sig = await connection.sendRawTransaction(tx.serialize(), { maxRetries: 5 });
  // Signature first, confirmation second: if this process dies while waiting,
  // the payout still has a durable record on the bridge request and the retry
  // reconciles it instead of sending a second transfer.
  console.log(JSON.stringify({ broadcastTxHash: sig }));

  const confirmation = await connection.confirmTransaction(
    { signature: sig, blockhash, lastValidBlockHeight },
    "confirmed",
  );

  if (confirmation.value.err) {
    throw new Error(
      `Solana transfer failed (${sig}): ${JSON.stringify(confirmation.value.err)}`,
    );
  }

  console.log("TX:", sig);
  console.log(JSON.stringify({ txHash: sig, status: "success" }));
}

main().catch((e) => { console.error(e.message || e); process.exit(1); });