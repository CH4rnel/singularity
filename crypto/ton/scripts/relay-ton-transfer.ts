/**
 * Native TON bridge payout: sends Toncoin from the relayer wallet to the
 * recipient. Idempotent on the bridge request id: every payout carries the
 * text comment "cyberia-bridge:<query_id>", and a re-run first scans the
 * relayer wallet's recent transactions for an outgoing transfer with that
 * comment — if found, the existing transaction hash is returned instead of
 * double-paying.
 *
 * Usage:
 *   npx tsx scripts/relay-ton-transfer.ts <recipient> <amount_nanoton> <query_id>
 *
 * env:
 *   TON_RELAYER_MNEMONIC — 24-word mnemonic of the relayer wallet (v4R2)
 *   TONCENTER_RPC_URL    — toncenter JSON-RPC endpoint (default mainnet)
 *   TONCENTER_API_KEY    — optional toncenter API key (higher rate limits)
 *
 * stdout (last line):
 *   {"txHash":"<hex>","reused":true|false}
 */
import {
  Address,
  beginCell,
  Cell,
  internal,
  SendMode,
  type Transaction,
} from "@ton/core";
import { mnemonicToPrivateKey } from "@ton/crypto";
import { TonClient, WalletContractV4 } from "@ton/ton";

const TEXT_COMMENT_OP = 0;

/** Payout marker comment for a bridge request id. */
export function bridgeComment(queryId: bigint): string {
  return `cyberia-bridge:${queryId}`;
}

/** Build the payout message body: a plain text-comment cell. */
export function buildCommentBody(queryId: bigint): Cell {
  return beginCell()
    .storeUint(TEXT_COMMENT_OP, 32)
    .storeStringTail(bridgeComment(queryId))
    .endCell();
}

/**
 * Parse a message body as a text comment: returns the comment string, or null
 * when the cell is not a plain text comment. Exported for tests.
 */
export function parseTextComment(body: Cell): string | null {
  try {
    const slice = body.beginParse();

    if (slice.remainingBits < 32) {
      return null;
    }

    const op = slice.loadUint(32);

    if (op !== TEXT_COMMENT_OP) {
      return null;
    }

    return slice.loadStringTail();
  } catch {
    return null;
  }
}

/**
 * Find an outgoing native transfer carrying this query_id's bridge comment
 * among the wallet's recent transactions. Returns the transaction hash (hex)
 * or null.
 */
export function findTransferByQueryId(
  transactions: Transaction[],
  queryId: bigint,
): string | null {
  const expected = bridgeComment(queryId);

  for (const tx of transactions) {
    for (const message of tx.outMessages.values()) {
      const body = message.body;

      if (!body) {
        continue;
      }

      if (parseTextComment(body) === expected) {
        return tx.hash().toString("hex");
      }
    }
  }

  return null;
}

async function sleep(ms: number): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

async function main() {
  const [, , recipientArg, amountRaw, queryIdArg] = process.argv;

  if (!recipientArg || !amountRaw || !queryIdArg) {
    console.error(
      "Usage: relay-ton-transfer.ts <recipient> <amount_nanoton> <query_id>",
    );
    process.exit(1);
  }

  const mnemonic = (process.env.TON_RELAYER_MNEMONIC || "").trim();

  if (!mnemonic) {
    console.error("TON_RELAYER_MNEMONIC not set");
    process.exit(1);
  }

  const endpoint =
    process.env.TONCENTER_RPC_URL || "https://toncenter.com/api/v2/jsonRPC";
  const apiKey = process.env.TONCENTER_API_KEY || undefined;

  const recipient = Address.parse(recipientArg);
  const amount = BigInt(amountRaw);
  const queryId = BigInt(queryIdArg);

  const client = new TonClient({ endpoint, apiKey });

  const keyPair = await mnemonicToPrivateKey(mnemonic.split(/\s+/));
  const wallet = WalletContractV4.create({
    workchain: 0,
    publicKey: keyPair.publicKey,
  });
  const contract = client.open(wallet);

  console.log(`Relayer wallet: ${wallet.address.toString()}`);
  console.log(`Recipient:      ${recipient.toString()}`);
  console.log(`Amount (nano):  ${amount}`);
  console.log(`Query id:       ${queryId}`);

  // Idempotency: a re-run after a sent-but-unconfirmed payout must not pay twice.
  const recent = await client.getTransactions(wallet.address, { limit: 50 });
  const existing = findTransferByQueryId(recent, queryId);

  if (existing) {
    console.log(`Found existing transfer for query_id ${queryId}`);
    console.log(JSON.stringify({ txHash: existing, reused: true }));

    return;
  }

  const balance = await contract.getBalance();

  if (balance < amount) {
    throw new Error(
      `Relayer TON balance ${balance} is below the payout amount ${amount}`,
    );
  }

  const seqno = await contract.getSeqno();

  await contract.sendTransfer({
    seqno,
    secretKey: keyPair.secretKey,
    // Fees on top of the payout amount so the recipient gets it exactly.
    sendMode: SendMode.PAY_GAS_SEPARATELY | SendMode.IGNORE_ERRORS,
    messages: [
      internal({
        to: recipient,
        value: amount,
        // Uninitialized recipient wallets can't bounce-return; plain payouts
        // must be non-bounceable so first-time TON addresses receive funds.
        bounce: false,
        body: buildCommentBody(queryId),
      }),
    ],
  });

  console.log(`Sent with seqno ${seqno}, waiting for confirmation...`);

  // Wait for the wallet to accept the external message (seqno bump).
  let confirmed = false;

  for (let attempt = 0; attempt < 30; attempt++) {
    await sleep(4000);

    try {
      if ((await contract.getSeqno()) > seqno) {
        confirmed = true;
        break;
      }
    } catch {
      // transient RPC error — keep polling
    }
  }

  if (!confirmed) {
    throw new Error("Timed out waiting for wallet seqno to advance");
  }

  // Locate the transaction that carried our transfer (by comment).
  for (let attempt = 0; attempt < 10; attempt++) {
    const transactions = await client.getTransactions(wallet.address, {
      limit: 20,
    });
    const txHash = findTransferByQueryId(transactions, queryId);

    if (txHash) {
      console.log(JSON.stringify({ txHash, reused: false }));

      return;
    }

    await sleep(3000);
  }

  throw new Error(
    "Transfer sent (seqno advanced) but transaction not found by comment — check manually before retrying",
  );
}

const isDirectRun = process.argv[1]?.endsWith("relay-ton-transfer.ts") ?? false;

if (isDirectRun) {
  main().catch((e) => {
    console.error(e?.message ?? e);
    process.exit(1);
  });
}
