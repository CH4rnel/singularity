/**
 * Slot machine settlement transaction.
 *
 * Reads a single JSON payload from stdin describing the burn portion (bet
 * tokens already owned by the hot wallet after the user's deposit) and zero
 * or more prize payouts. Builds one Solana Transaction containing:
 *
 *   1. Optional `Burn` of bet tokens from the hot-wallet ATA.
 *   2. For each prize: `createAssociatedTokenAccountInstruction` if the
 *      recipient ATA does not yet exist, then `TransferChecked`.
 *
 * Signed by the slot hot-wallet keypair (ANCHOR_WALLET). Slot infrastructure
 * is intentionally separate from the bridge — different keypair, different
 * RPC URL. Bridge scripts are not touched.
 *
 * Stdin JSON:
 *   {
 *     "burn":    { "mint": "...", "amount": "...", "tokenProgram": "token|token-2022" } | null,
 *     "payouts": [{ "mint": "...", "amount": "...", "recipient": "...", "tokenProgram": "..." }, ...]
 *   }
 *
 * Stdout (last line): {"txHash":"...","status":"success"}
 *
 * env:
 *   ANCHOR_PROVIDER_URL — Solana RPC for the slot machine
 *   ANCHOR_WALLET      — path to the slot hot-wallet keypair JSON
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
  createBurnInstruction,
  createTransferCheckedInstruction,
  createAssociatedTokenAccountInstruction,
  getMint,
  getAccount,
  TOKEN_PROGRAM_ID,
  TOKEN_2022_PROGRAM_ID,
} from "@solana/spl-token";
import fs from "fs";

type PayoutSpec = {
  mint: string;
  amount: string;
  recipient: string;
  tokenProgram: "token" | "token-2022";
};

type BurnSpec = {
  mint: string;
  amount: string;
  tokenProgram: "token" | "token-2022";
};

type Payload = {
  burn: BurnSpec | null;
  payouts: PayoutSpec[];
};

function resolveProgram(name: string): PublicKey {
  if (name === "token-2022") return TOKEN_2022_PROGRAM_ID;
  if (name === "token") return TOKEN_PROGRAM_ID;
  throw new Error(`Unknown token program: ${name}`);
}

async function readStdin(): Promise<string> {
  return await new Promise((resolve, reject) => {
    let data = "";
    process.stdin.setEncoding("utf8");
    process.stdin.on("data", (chunk) => (data += chunk));
    process.stdin.on("end", () => resolve(data));
    process.stdin.on("error", reject);
  });
}

async function main() {
  const raw = await readStdin();
  const payload = JSON.parse(raw) as Payload;

  const rpcUrl =
    process.env.ANCHOR_PROVIDER_URL || "https://api.mainnet-beta.solana.com";
  const walletPath = (process.env.ANCHOR_WALLET || "").replace(
    "~",
    process.env.HOME || "/root",
  );

  if (!walletPath) {
    throw new Error("ANCHOR_WALLET is required");
  }

  const hot = Keypair.fromSecretKey(
    Uint8Array.from(JSON.parse(fs.readFileSync(walletPath, "utf8"))),
  );

  const connection = new Connection(rpcUrl, "confirmed");
  const tx = new Transaction();

  // --- Burn -----------------------------------------------------------------
  if (payload.burn && BigInt(payload.burn.amount) > 0n) {
    const burnMint = new PublicKey(payload.burn.mint);
    const burnProgram = resolveProgram(payload.burn.tokenProgram);
    const burnAta = await getAssociatedTokenAddress(
      burnMint,
      hot.publicKey,
      false,
      burnProgram,
    );

    tx.add(
      createBurnInstruction(
        burnAta,
        burnMint,
        hot.publicKey,
        BigInt(payload.burn.amount),
        [],
        burnProgram,
      ),
    );
  }

  // --- Payouts --------------------------------------------------------------
  for (const payout of payload.payouts) {
    const amount = BigInt(payout.amount);
    if (amount <= 0n) continue;

    const mint = new PublicKey(payout.mint);
    const recipient = new PublicKey(payout.recipient);
    const program = resolveProgram(payout.tokenProgram);

    const mintInfo = await getMint(connection, mint, "confirmed", program);

    const sourceAta = await getAssociatedTokenAddress(
      mint,
      hot.publicKey,
      false,
      program,
    );
    const destAta = await getAssociatedTokenAddress(
      mint,
      recipient,
      false,
      program,
    );

    try {
      await getAccount(connection, destAta, "confirmed", program);
    } catch {
      tx.add(
        createAssociatedTokenAccountInstruction(
          hot.publicKey,
          destAta,
          recipient,
          mint,
          program,
        ),
      );
    }

    tx.add(
      createTransferCheckedInstruction(
        sourceAta,
        mint,
        destAta,
        hot.publicKey,
        amount,
        mintInfo.decimals,
        [],
        program,
      ),
    );
  }

  if (tx.instructions.length === 0) {
    console.log(JSON.stringify({ txHash: null, status: "noop" }));
    return;
  }

  const sig = await sendAndConfirmTransaction(connection, tx, [hot], {
    commitment: "confirmed",
  });

  console.log("TX:", sig);
  console.log(JSON.stringify({ txHash: sig, status: "success" }));
}

main().catch((e) => {
  console.error(e?.message ?? e);
  process.exit(1);
});
