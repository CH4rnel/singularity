/**
 * Unit tests for broadcastWithRecovery (bridge relay tx broadcast).
 *
 * Run standalone (no chain / no hardhat env needed):
 *   npx tsx --test scripts/lib/send-tx.test.ts
 *
 * The wallet is backed by an in-memory random key generated at runtime — no
 * private key is ever hardcoded or persisted. It signs a canonical EIP-1559 tx
 * for the mocked nonce so ethers.Transaction.from(signed).hash yields a real,
 * deterministic hash and re-signing the same nonce is byte-identical.
 */
import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { ethers } from "ethers";

import { broadcastWithRecovery } from "./send-tx";

const RECIPIENT = "0x00000000000000000000000000000000000000A1";

function rpcError(message: string, code?: string) {
  const e = new Error(message) as Error & { code?: string };
  if (code) e.code = code;
  return e;
}

type Behavior = (signed: string) => void; // throw to simulate a broadcast error

function makeWallet(startNonce = 5) {
  const signer = ethers.Wallet.createRandom();
  let nonce = startNonce;
  const wallet = {
    address: signer.address,
    bumpNonce() {
      nonce += 1;
    },
    async populateTransaction(req: ethers.TransactionRequest) {
      return {
        to: (req.to as string) ?? ethers.ZeroAddress,
        value: req.value ?? 0n,
        data: req.data ?? "0x",
        nonce,
        gasLimit: 21000n,
        maxFeePerGas: 1_000_000_000n,
        maxPriorityFeePerGas: 1_000_000_000n,
        chainId: 8453n,
        type: 2,
      };
    },
    async signTransaction(tx: ethers.TransactionRequest) {
      return signer.signTransaction(tx);
    },
  };
  return wallet as unknown as ethers.Wallet & { bumpNonce(): void };
}

function makeProvider(opts: {
  broadcast: Behavior[];
  receiptFor?: (hash: string) => unknown;
}) {
  const broadcasts: string[] = [];
  let i = 0;
  const provider = {
    broadcasts,
    async broadcastTransaction(signed: string) {
      broadcasts.push(signed);
      const behavior = opts.broadcast[Math.min(i, opts.broadcast.length - 1)];
      i += 1;
      behavior(signed); // may throw
      return { hash: ethers.Transaction.from(signed).hash };
    },
    async getTransactionReceipt(hash: string) {
      return opts.receiptFor?.(hash) ?? null;
    },
  };
  return provider as unknown as ethers.JsonRpcProvider & { broadcasts: string[] };
}

const FAST = { baseDelayMs: 1 };

describe("broadcastWithRecovery", () => {
  it("returns the hash on a clean first broadcast", async () => {
    const wallet = makeWallet();
    const provider = makeProvider({ broadcast: [() => {}] });

    const { hash } = await broadcastWithRecovery(
      wallet,
      provider,
      { to: RECIPIENT, value: 1n },
      FAST,
    );

    assert.equal(provider.broadcasts.length, 1);
    assert.equal(hash, ethers.Transaction.from(provider.broadcasts[0]).hash);
  });

  it('recovers "already known" as success without resending', async () => {
    const wallet = makeWallet();
    const provider = makeProvider({
      broadcast: [
        () => {
          throw rpcError(
            'could not coalesce error (error={ "code": -32000, "message": "already known" })',
          );
        },
      ],
    });

    const { hash } = await broadcastWithRecovery(
      wallet,
      provider,
      { to: RECIPIENT, value: 1n },
      FAST,
    );

    assert.equal(provider.broadcasts.length, 1);
    assert.equal(hash, ethers.Transaction.from(provider.broadcasts[0]).hash);
  });

  it("resends the SAME signed tx on a transient error (no double-pay)", async () => {
    const wallet = makeWallet();
    const provider = makeProvider({
      broadcast: [
        () => {
          throw rpcError("read ETIMEDOUT", "TIMEOUT");
        },
        () => {
          throw rpcError("already known");
        },
      ],
    });

    const { hash } = await broadcastWithRecovery(
      wallet,
      provider,
      { to: RECIPIENT, value: 1n },
      FAST,
    );

    assert.equal(provider.broadcasts.length, 2);
    // Byte-identical resend → same nonce → the node dedupes, never a 2nd payout.
    assert.equal(provider.broadcasts[0], provider.broadcasts[1]);
    assert.equal(hash, ethers.Transaction.from(provider.broadcasts[0]).hash);
  });

  it('treats "nonce too low" as success when our tx already mined', async () => {
    const wallet = makeWallet();
    const provider = makeProvider({
      broadcast: [
        () => {
          throw rpcError("nonce too low");
        },
      ],
      receiptFor: () => ({ status: 1 }),
    });

    const { hash } = await broadcastWithRecovery(
      wallet,
      provider,
      { to: RECIPIENT, value: 1n },
      FAST,
    );

    assert.equal(provider.broadcasts.length, 1);
    assert.equal(hash, ethers.Transaction.from(provider.broadcasts[0]).hash);
  });

  it("rebuilds a fresh nonce when a competing tx took ours", async () => {
    const wallet = makeWallet();
    const provider = makeProvider({
      broadcast: [
        (signed) => {
          // The competing tx mined, advancing the account nonce; our tx at the
          // old nonce can never land and has no receipt.
          void signed;
          wallet.bumpNonce();
          throw rpcError("nonce too low");
        },
        () => {}, // rebuilt tx at the new nonce broadcasts cleanly
      ],
      receiptFor: () => null,
    });

    const { hash } = await broadcastWithRecovery(
      wallet,
      provider,
      { to: RECIPIENT, value: 1n },
      FAST,
    );

    assert.equal(provider.broadcasts.length, 2);
    // Different nonce → different signed bytes → different hash.
    assert.notEqual(provider.broadcasts[0], provider.broadcasts[1]);
    assert.equal(hash, ethers.Transaction.from(provider.broadcasts[1]).hash);
  });

  it("fails fast on a terminal error (insufficient funds)", async () => {
    const wallet = makeWallet();
    const provider = makeProvider({
      broadcast: [
        () => {
          throw rpcError("insufficient funds for gas * price + value");
        },
      ],
    });

    await assert.rejects(
      () =>
        broadcastWithRecovery(wallet, provider, { to: RECIPIENT, value: 1n }, FAST),
      /insufficient funds/,
    );
    assert.equal(provider.broadcasts.length, 1);
  });
});
