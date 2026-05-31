/**
 * Direct-model bridge relayer for Solana destination.
 *
 * Generalised version of relay-release-native.ts — parametrised by mint and
 * token program so it works for CYBER.sol (Token-2022), USDC, USDT, etc.
 *
 * Usage:
 *   npx ts-node --transpile-only scripts/relay-spl-transfer.ts \
 *       <mint> <recipient_base58> <amount_raw> <token_program>
 *
 *   token_program: "token" | "token-2022"
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
  Transaction,
  sendAndConfirmTransaction,
} from "@solana/web3.js";
import {
  getAssociatedTokenAddress,
  createTransferInstruction,
  createAssociatedTokenAccountInstruction,
  getAccount,
  TOKEN_PROGRAM_ID,
  TOKEN_2022_PROGRAM_ID,
} from "@solana/spl-token";
import fs from "fs";

function resolveProgram(name: string): PublicKey {
  if (name === "token-2022") return TOKEN_2022_PROGRAM_ID;
  if (name === "token") return TOKEN_PROGRAM_ID;
  throw new Error(`Unknown token program: ${name}`);
}

async function main() {
  const [, , mintStr, recipientBase58, amountStr, programName] = process.argv;

  if (!mintStr || !recipientBase58 || !amountStr || !programName) {
    console.error(
      "Usage: relay-spl-transfer.ts <mint> <recipient_base58> <amount_raw> <token_program>",
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
  const mint = new PublicKey(mintStr);
  const recipient = new PublicKey(recipientBase58);
  const amount = BigInt(amountStr);
  const program = resolveProgram(programName);

  const relayerAta = await getAssociatedTokenAddress(mint, relayer.publicKey, false, program);
  const recipientAta = await getAssociatedTokenAddress(mint, recipient, false, program);

  console.log("Relayer:  ", relayer.publicKey.toBase58());
  console.log("Mint:     ", mint.toBase58());
  console.log("Recipient:", recipient.toBase58());
  console.log("Amount:   ", amount.toString());
  console.log("Program:  ", programName);

  const tx = new Transaction();

  try {
    await getAccount(connection, recipientAta, "confirmed", program);
  } catch {
    console.log("Creating recipient ATA…");
    tx.add(
      createAssociatedTokenAccountInstruction(
        relayer.publicKey,
        recipientAta,
        recipient,
        mint,
        program,
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
      program,
    ),
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
