/**
 * Nonce-collision-resilient send for the bridge relay scripts.
 *
 * The relayer EOA is shared (bridge relayer + Telegram minter + DCA bot), so a
 * relay tx can race another tx from the same account for a nonce and be
 * rejected at broadcast with "replacement transaction underpriced" / "nonce too
 * low". Rather than hard-fail the whole bridge on a transient race, retry the
 * send with backoff: ethers re-derives the pending nonce each attempt, so once
 * the competing tx mines the nonce advances and the send goes through. We do
 * NOT bump gas to replace the in-flight tx — that would cancel the other
 * account's legitimate tx; we just wait it out.
 */
import { ethers } from "ethers";

const RACE_PATTERNS = [
  "replacement transaction underpriced",
  "replacement tx underpriced",
  "transaction underpriced",
  "nonce too low",
  "nonce has already been used",
];

function isNonceRace(err: unknown): boolean {
  const msg = String(
    (err as { shortMessage?: string; message?: string })?.shortMessage ??
      (err as { message?: string })?.message ??
      err,
  ).toLowerCase();
  return RACE_PATTERNS.some((p) => msg.includes(p));
}

export async function sendWithNonceRetry(
  send: () => Promise<ethers.TransactionResponse>,
  { tries = 6, baseDelayMs = 1500 }: { tries?: number; baseDelayMs?: number } = {},
): Promise<ethers.TransactionResponse> {
  let lastErr: unknown;

  for (let i = 0; i < tries; i++) {
    try {
      return await send();
    } catch (err) {
      lastErr = err;
      if (!isNonceRace(err) || i === tries - 1) throw err;
      // Backoff grows so a busy account has time to clear the pending nonce.
      await new Promise((r) => setTimeout(r, baseDelayMs * (i + 1)));
    }
  }

  throw lastErr;
}
