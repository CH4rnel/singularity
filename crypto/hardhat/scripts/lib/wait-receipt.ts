/**
 * Resilient transaction-receipt wait for the bridge relay scripts.
 *
 * The relay scripts broadcast a payout and then block on the receipt. Plain
 * `tx.wait()` dies on the first transient RPC/network hiccup (e.g. a public
 * BSC dataseed dropping the connection mid-wait with `read ETIMEDOUT`) even
 * though the tx is already in the mempool and confirms seconds later — which
 * false-fails a payout that actually went through. This polls
 * `getTransactionReceipt` instead, swallowing transient errors and only giving
 * up at a wall-clock deadline (kept under the caller's 120s Process timeout so
 * the script exits cleanly, having already printed its broadcastTxHash for the
 * backend to recover).
 */
import { ethers } from "ethers";

async function withTimeout<T>(p: Promise<T>, ms: number): Promise<T> {
  let timer: ReturnType<typeof setTimeout>;
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => reject(new Error("receipt attempt timed out")), ms);
  });
  try {
    return await Promise.race([p, timeout]);
  } finally {
    clearTimeout(timer!);
  }
}

export async function waitForReceipt(
  provider: ethers.JsonRpcProvider,
  hash: string,
  {
    delayMs = 2500,
    attemptTimeoutMs = 8000,
    deadlineMs = 100_000,
  }: { delayMs?: number; attemptTimeoutMs?: number; deadlineMs?: number } = {},
): Promise<ethers.TransactionReceipt | null> {
  const start = Date.now();

  while (Date.now() - start < deadlineMs) {
    try {
      const receipt = await withTimeout(
        provider.getTransactionReceipt(hash),
        attemptTimeoutMs,
      );
      if (receipt) return receipt;
    } catch {
      // Transient RPC/network error (ETIMEDOUT, socket hangup, attempt
      // timeout) — the tx is already broadcast, so keep polling.
    }
    await new Promise((r) => setTimeout(r, delayMs));
  }

  return null;
}
