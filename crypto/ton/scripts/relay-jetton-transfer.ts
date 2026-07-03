/**
 * TON jetton bridge payout: sends jettons from the relayer wallet to the
 * recipient. Idempotent on query_id (= bridge request id): if the relayer
 * wallet already emitted a jetton transfer with this query_id, the existing
 * transaction hash is returned instead of double-paying.
 *
 * Usage:
 *   npx tsx scripts/relay-jetton-transfer.ts <jetton_master> <recipient> <amount_raw> <query_id>
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
  toNano,
  type Transaction,
} from "@ton/core";
import { mnemonicToPrivateKey } from "@ton/crypto";
import { TonClient, WalletContractV4 } from "@ton/ton";

const JETTON_TRANSFER_OP = 0x0f8a7ea5;

/** TON attached to the jetton-wallet call (fees; excess bounces back). */
const TRANSFER_TON_AMOUNT = "0.08";

/**
 * Parse a jetton-transfer body: returns its query_id, or null when the cell
 * is not a jetton transfer. Exported for tests.
 */
export function parseJettonTransferQueryId(body: Cell): bigint | null {
  try {
    const slice = body.beginParse();

    if (slice.remainingBits < 96) {
      return null;
    }

    const op = slice.loadUint(32);

    if (op !== JETTON_TRANSFER_OP) {
      return null;
    }

    return slice.loadUintBig(64);
  } catch {
    return null;
  }
}

/**
 * Find an outgoing jetton transfer with the given query_id among the wallet's
 * recent transactions. Returns the transaction hash (hex) or null.
 */
export function findTransferByQueryId(
  transactions: Transaction[],
  queryId: bigint,
): string | null {
  for (const tx of transactions) {
    for (const message of tx.outMessages.values()) {
      const body = message.body;

      if (!body) {
        continue;
      }

      if (parseJettonTransferQueryId(body) === queryId) {
        return tx.hash().toString("hex");
      }
    }
  }

  return null;
}

async function resolveJettonWallet(
  client: TonClient,
  jettonMaster: Address,
  owner: Address,
): Promise<Address> {
  const result = await client.runMethod(jettonMaster, "get_wallet_address", [
    {
      type: "slice",
      cell: beginCell().storeAddress(owner).endCell(),
    },
  ]);

  return result.stack.readAddress();
}

async function sleep(ms: number): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

async function main() {
  const [, , jettonMasterArg, recipientArg, amountRaw, queryIdArg] =
    process.argv;

  if (!jettonMasterArg || !recipientArg || !amountRaw || !queryIdArg) {
    console.error(
      "Usage: relay-jetton-transfer.ts <jetton_master> <recipient> <amount_raw> <query_id>",
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

  const jettonMaster = Address.parse(jettonMasterArg);
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
  console.log(`Jetton master:  ${jettonMaster.toString()}`);
  console.log(`Recipient:      ${recipient.toString()}`);
  console.log(`Amount (raw):   ${amount}`);
  console.log(`Query id:       ${queryId}`);

  // Idempotency: a re-run after a sent-but-unconfirmed payout must not pay twice.
  const recent = await client.getTransactions(wallet.address, { limit: 50 });
  const existing = findTransferByQueryId(recent, queryId);

  if (existing) {
    console.log(`Found existing transfer for query_id ${queryId}`);
    console.log(JSON.stringify({ txHash: existing, reused: true }));

    return;
  }

  const jettonWallet = await resolveJettonWallet(
    client,
    jettonMaster,
    wallet.address,
  );
  console.log(`Jetton wallet:  ${jettonWallet.toString()}`);

  const transferBody = beginCell()
    .storeUint(JETTON_TRANSFER_OP, 32)
    .storeUint(queryId, 64)
    .storeCoins(amount)
    .storeAddress(recipient)
    .storeAddress(wallet.address) // response destination (excess gas back)
    .storeBit(false) // no custom payload
    .storeCoins(1n) // forward_ton_amount: 1 nanoton notification
    .storeBit(false) // no forward payload
    .endCell();

  const seqno = await contract.getSeqno();

  await contract.sendTransfer({
    seqno,
    secretKey: keyPair.secretKey,
    sendMode: SendMode.PAY_GAS_SEPARATELY | SendMode.IGNORE_ERRORS,
    messages: [
      internal({
        to: jettonWallet,
        value: toNano(TRANSFER_TON_AMOUNT),
        bounce: true,
        body: transferBody,
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

  // Locate the transaction that carried our transfer (by query_id).
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
    "Transfer sent (seqno advanced) but transaction not found by query_id — check manually before retrying",
  );
}

const isDirectRun =
  process.argv[1]?.endsWith("relay-jetton-transfer.ts") ?? false;

if (isDirectRun) {
  main().catch((e) => {
    console.error(e?.message ?? e);
    process.exit(1);
  });
}
