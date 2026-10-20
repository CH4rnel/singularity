// Reconciliation: given what the chain pays for and what docker is running,
// what should change. Pure, so every rule that decides whether a provider
// works for free or a payer pays for nothing is tested without docker.

import { isPaying, runway } from "./lease.mjs";

/**
 * @param {object} p
 * @param {Array<{ id: number, lease: import("./lease.mjs").Lease & { specHash: string }, manifest?: object, manifestError?: string }>} p.leases
 * @param {Array<{ name: string, leaseId: number, spec: string, running: boolean }>} p.containers
 * @param {number} p.now unix seconds
 * @param {boolean} p.trusted whether this provider may run trusted-tier services
 * @param {number} [p.warnSeconds] runway below which to warn
 * @param {number} [p.pauseGraceSeconds] how long a paused lease's container is
 *   kept up unpaid, so the payer's watcher can see it healthy and resume
 */
export function plan({ leases, containers, now, trusted, warnSeconds = 6 * 3600, pauseGraceSeconds = 900 }) {
  const actions = [];
  const byLease = new Map(containers.map((c) => [c.leaseId, c]));
  const wanted = new Set();

  for (const { id, lease, manifest, manifestError } of leases) {
    const container = byLease.get(id);
    const inGrace =
      !lease.closed && lease.pausedAt !== 0n && BigInt(now) - lease.pausedAt < BigInt(pauseGraceSeconds);
    if (inGrace) {
      // A watcher that paused on a blip on its own side can only resume after
      // seeing the service healthy, and it cannot see a stopped container. A
      // few unpaid minutes is the price of that not being a deadlock.
      if (container) wanted.add(id);
      continue;
    }
    if (!isPaying(lease, now)) {
      // Closed, paused or out of runway: stop working. This is the whole of
      // the provider's protection against a payer that stops paying.
      if (container?.running) actions.push({ type: "stop", leaseId: id, name: container.name, reason: why(lease, now) });
      if (lease.closed && container) actions.push({ type: "remove", leaseId: id, name: container.name });
      continue;
    }
    if (manifestError || !manifest) {
      // Paid for but unrunnable: refuse loudly rather than run something else.
      actions.push({ type: "reject", leaseId: id, reason: manifestError ?? "manifest unavailable" });
      continue;
    }
    if (manifest.tier === "trusted" && !trusted) {
      actions.push({ type: "reject", leaseId: id, reason: "trusted-tier service on an untrusted provider" });
      continue;
    }
    wanted.add(id);
    if (!container) {
      actions.push({ type: "start", leaseId: id });
    } else if (container.spec !== lease.specHash) {
      actions.push({ type: "redeploy", leaseId: id, name: container.name });
    } else if (!container.running) {
      actions.push({ type: "restart", leaseId: id, name: container.name });
    }
    const left = runway(lease, now);
    if (left < BigInt(warnSeconds)) actions.push({ type: "warn", leaseId: id, runwaySeconds: Number(left) });
  }

  // Containers carrying a lease label this provider no longer has.
  const known = new Set(leases.map((l) => l.id));
  for (const c of containers) {
    if (!known.has(c.leaseId) && !wanted.has(c.leaseId)) {
      actions.push({ type: "remove", leaseId: c.leaseId, name: c.name, reason: "unknown lease" });
    }
  }
  return actions;
}

function why(lease, now) {
  if (lease.closed) return "closed";
  if (lease.pausedAt !== 0n) return "paused";
  return runway(lease, now) === 0n ? "out of runway" : "not paying";
}
