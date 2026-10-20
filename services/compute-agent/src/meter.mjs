// Usage metering from `docker stats`.
//
// A lease is priced per second of a reserved shape (cpus, memory), so billing
// does not depend on this. What it is for is evidence: an hourly report of what
// each container actually used, hashed and committed on chain by the provider
// (`reportUsage`), so a payer who suspects the container was not running — or
// was starved — has the provider's own signed numbers to argue with. It is
// also the data a later usage-priced lease would bill from.

import { canonicalize } from "./manifest.mjs";
import { keccak256, toUtf8Bytes } from "ethers";

const UNITS = { B: 1, KB: 1e3, MB: 1e6, GB: 1e9, TB: 1e12, KIB: 1024, MIB: 1024 ** 2, GIB: 1024 ** 3, TIB: 1024 ** 4 };

/** "12.5MiB" → bytes. */
export function parseBytes(text) {
  const m = String(text).trim().match(/^([0-9.]+)\s*([A-Za-z]*)$/);
  if (!m) return 0;
  const unit = UNITS[(m[2] || "B").toUpperCase()];
  return unit === undefined ? 0 : Math.round(Number(m[1]) * unit);
}

/** One line of `docker stats --no-stream --format '{{json .}}'`. */
export function parseStatsLine(line) {
  const s = JSON.parse(line);
  const [memUsed] = String(s.MemUsage ?? "0B / 0B").split("/");
  const [rx, tx] = String(s.NetIO ?? "0B / 0B").split("/");
  return {
    name: s.Name,
    cpuPct: Number(String(s.CPUPerc ?? "0").replace("%", "")) || 0,
    memBytes: parseBytes(memUsed),
    netRx: parseBytes(rx),
    netTx: parseBytes(tx),
  };
}

/** Integrates samples into cpu-seconds, MB-seconds and bytes moved. */
export class Meter {
  constructor(periodStart) {
    this.periodStart = periodStart;
    this.byLease = new Map();
  }

  /** @param {number} leaseId @param {ReturnType<typeof parseStatsLine>} sample @param {number} at unix seconds */
  add(leaseId, sample, at) {
    const u = this.byLease.get(leaseId) ?? {
      cpuMilliSeconds: 0, memMbSeconds: 0, egressBytes: 0, ingressBytes: 0,
      samples: 0, lastAt: null, lastRx: null, lastTx: null,
    };
    if (u.lastAt !== null) {
      const dt = Math.max(0, at - u.lastAt);
      u.cpuMilliSeconds += Math.round(sample.cpuPct * 10 * dt); // pct/100 cores × 1000 ms
      u.memMbSeconds += Math.round((sample.memBytes / 1e6) * dt);
      // Counters reset when a container restarts; a drop is a restart, not negative traffic.
      u.ingressBytes += sample.netRx >= u.lastRx ? sample.netRx - u.lastRx : sample.netRx;
      u.egressBytes += sample.netTx >= u.lastTx ? sample.netTx - u.lastTx : sample.netTx;
    }
    u.samples += 1;
    u.lastAt = at;
    u.lastRx = sample.netRx;
    u.lastTx = sample.netTx;
    this.byLease.set(leaseId, u);
  }

  /** Close the period: one report per lease, and a fresh meter. */
  close(periodEnd) {
    const reports = [];
    for (const [leaseId, u] of this.byLease) {
      reports.push({
        schema: "cyberia.usage/v1",
        lease: leaseId,
        periodStart: this.periodStart,
        periodEnd,
        samples: u.samples,
        cpuMilliSeconds: u.cpuMilliSeconds,
        memMbSeconds: u.memMbSeconds,
        ingressBytes: u.ingressBytes,
        egressBytes: u.egressBytes,
      });
    }
    this.periodStart = periodEnd;
    this.byLease = new Map();
    return reports;
  }
}

export function usageHash(report) {
  return keccak256(toUtf8Bytes(canonicalize(report)));
}
