// CyberiaCompute's accrual arithmetic, restated so the agent can decide what
// to do without asking the chain every second. Pinned to the contract by
// crypto/hardhat/test/CyberiaCompute.ts, which compares the two.

/** @typedef {{ rate: bigint, balance: bigint, settledAt: bigint, pausedAt: bigint, closed: boolean }} Lease */

/** Wei accrued and not yet settled at unix second `now`. */
export function owed(lease, now) {
  if (lease.closed) return 0n;
  const end = lease.pausedAt !== 0n ? lease.pausedAt : BigInt(now);
  if (end <= lease.settledAt) return 0n;
  const due = lease.rate * (end - lease.settledAt);
  return due > lease.balance ? lease.balance : due;
}

export function remaining(lease, now) {
  return lease.balance - owed(lease, now);
}

/** Seconds of paid time left. */
export function runway(lease, now) {
  if (lease.closed || lease.rate === 0n) return 0n;
  return remaining(lease, now) / lease.rate;
}

/** Is the provider being paid for this second? */
export function isPaying(lease, now) {
  return !lease.closed && lease.pausedAt === 0n && runway(lease, now) > 0n;
}

/** Wei per day at a lease's rate, for people. */
export function perDay(rate) {
  return rate * 86_400n;
}
