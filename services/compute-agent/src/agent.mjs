#!/usr/bin/env node
// Cyberia compute agent.
//
//   node src/agent.mjs            provider: run what the chain pays for
//   node src/agent.mjs --watch    payer: pause leases whose service is down
//   node src/agent.mjs --hash f   print a manifest's spec hash (what a lease commits to)
//   node src/agent.mjs --manifest <service> <sha256:…>
//                                 render a catalog entry as a manifest + its hash
//
// Configuration is environment only; see README.md. Keys are read from the
// environment and never logged.

import { readFile } from "node:fs/promises";
import { Wallet } from "ethers";

import { loadCatalog, render } from "./catalog.mjs";
import { connect, toLease } from "./chain.mjs";
import { makeDocker } from "./docker.mjs";
import { dockerRunArgs, specHash, validateManifest } from "./manifest.mjs";
import { Meter, parseStatsLine, usageHash } from "./meter.mjs";
import { plan } from "./plan.mjs";
import { watchDecision } from "./watch.mjs";

const env = process.env;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const nowSec = () => Math.floor(Date.now() / 1000);
const log = (...a) => console.log(new Date().toISOString(), ...a);

function config() {
  const cfg = {
    rpcUrl: env.COMPUTE_RPC_URL ?? "https://rpc.cyberia.church",
    address: env.COMPUTE_ADDRESS,
    privateKey: env.COMPUTE_PROVIDER_KEY,
    gateway: (env.COMPUTE_IPFS_GATEWAY ?? "https://ipfs.io/ipfs/").replace(/\/?$/, "/"),
    trusted: env.COMPUTE_TRUSTED === "1",
    dryRun: env.COMPUTE_DRY_RUN === "1",
    tickSeconds: Number(env.COMPUTE_TICK_SECONDS ?? 30),
    reportSeconds: Number(env.COMPUTE_REPORT_SECONDS ?? 3600),
    withdrawMinWei: BigInt(env.COMPUTE_WITHDRAW_MIN_WEI ?? "100000000000000000"), // 0.1 CYBER
  };
  if (!cfg.address) throw new Error("COMPUTE_ADDRESS is not set");
  return cfg;
}

export async function fetchManifest(uri, gateway, fetchImpl = fetch) {
  const url = uri.startsWith("ipfs://") ? gateway + uri.slice(7) : uri;
  if (!/^https?:\/\//.test(url)) throw new Error(`unsupported manifest uri ${uri}`);
  const res = await fetchImpl(url, { signal: AbortSignal.timeout(15_000) });
  if (!res.ok) throw new Error(`manifest ${res.status}`);
  return res.json();
}

/** Fetch, hash-check and validate every lease's manifest. */
export async function resolveManifests(leases, gateway, fetchImpl = fetch, cache = new Map()) {
  return Promise.all(
    leases.map(async ({ id, lease }) => {
      if (lease.closed) return { id, lease }; // nothing to run, nothing to fetch
      const hit = cache.get(lease.specHash);
      if (hit) return { id, lease, ...hit };
      let entry;
      try {
        const manifest = await fetchManifest(lease.specURI, gateway, fetchImpl);
        const hash = specHash(manifest);
        const errors = validateManifest(manifest);
        if (hash !== lease.specHash) entry = { manifestError: `spec hash mismatch: lease ${lease.specHash}, manifest ${hash}` };
        else if (errors.length) entry = { manifestError: errors.join("; ") };
        else entry = { manifest };
      } catch (e) {
        return { id, lease, manifestError: String(e.message ?? e) }; // transient: not cached
      }
      cache.set(lease.specHash, entry);
      return { id, lease, ...entry };
    }),
  );
}

async function provide() {
  const cfg = config();
  if (!cfg.privateKey && !cfg.dryRun) throw new Error("COMPUTE_PROVIDER_KEY is not set (or use COMPUTE_DRY_RUN=1)");
  const { contract } = connect(cfg);
  const self = cfg.privateKey ? new Wallet(cfg.privateKey).address : env.COMPUTE_PROVIDER_ADDRESS;
  if (!self) throw new Error("COMPUTE_PROVIDER_ADDRESS is needed in dry-run without a key");
  const docker = makeDocker({ dryRun: cfg.dryRun, log });
  const cache = new Map();
  let meter = new Meter(nowSec());

  log(`agent for provider ${self} on ${cfg.address}${cfg.dryRun ? " (dry run)" : ""}`);
  for (;;) {
    try {
      const ids = (await contract.leasesOfProvider(self)).map(Number);
      const raw = await Promise.all(ids.map((id) => contract.lease(id)));
      const leases = await resolveManifests(
        ids.map((id, i) => ({ id, lease: toLease(raw[i]) })),
        cfg.gateway, fetch, cache,
      );
      const containers = await docker.leaseContainers();
      const now = nowSec();
      const actions = plan({ leases, containers, now, trusted: cfg.trusted });
      for (const a of actions) await apply(a, leases, docker);

      // Meter what is running.
      const running = (await docker.leaseContainers()).filter((c) => c.running);
      const stats = await docker.stats(running.map((c) => c.name));
      for (const line of stats.split("\n").filter(Boolean)) {
        const s = parseStatsLine(line);
        const c = running.find((r) => r.name === s.name);
        if (c) meter.add(c.leaseId, s, now);
      }

      if (now - meter.periodStart >= cfg.reportSeconds) {
        for (const report of meter.close(now)) {
          const hash = usageHash(report);
          log(`usage lease ${report.lease}: ${JSON.stringify(report)} ${hash}`);
          if (!cfg.dryRun) {
            // The report itself is published by the operator's pinning step;
            // the URI scheme here is the agent's own convention.
            await (await contract.reportUsage(report.lease, report.periodStart, report.periodEnd, hash, `usage:${hash}`)).wait();
          }
        }
        if (!cfg.dryRun) {
          for (const { id, lease } of leases) {
            if (!lease.closed) await (await contract.settle(id)).wait();
          }
        }
        if (!cfg.dryRun && (await contract.claimable(self)) >= cfg.withdrawMinWei) {
          await (await contract.withdraw()).wait();
          log("withdrew earnings");
        }
      }
    } catch (e) {
      log(`tick failed: ${e.message ?? e}`);
    }
    await sleep(cfg.tickSeconds * 1000);
  }
}

async function apply(action, leases, docker) {
  const entry = leases.find((l) => l.id === action.leaseId);
  switch (action.type) {
    case "start":
    case "redeploy": {
      if (action.type === "redeploy") await docker.remove(action.name);
      const m = entry.manifest;
      await docker.pull(m.image);
      await docker.run(dockerRunArgs(m, { leaseId: action.leaseId, hash: entry.lease.specHash, env }), env);
      log(`${action.type} lease ${action.leaseId}: ${m.service} ${m.image}`);
      break;
    }
    case "restart":
      await docker.start(action.name);
      log(`restart lease ${action.leaseId}`);
      break;
    case "stop":
      await docker.stop(action.name);
      log(`stop lease ${action.leaseId}: ${action.reason}`);
      break;
    case "remove":
      await docker.remove(action.name);
      log(`remove lease ${action.leaseId}${action.reason ? `: ${action.reason}` : ""}`);
      break;
    case "reject":
      log(`REFUSE lease ${action.leaseId}: ${action.reason}`);
      break;
    case "warn":
      log(`lease ${action.leaseId}: ${action.runwaySeconds}s of runway left`);
      break;
  }
}

/** COMPUTE_WATCH="12=https://dns.cyberia.church/health,13=https://…" */
export function parseWatchList(text) {
  return String(text ?? "")
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean)
    .map((pair) => {
      const i = pair.indexOf("=");
      return { id: Number(pair.slice(0, i)), url: pair.slice(i + 1) };
    });
}

async function watch() {
  const cfg = config();
  if (!cfg.privateKey) throw new Error("COMPUTE_PROVIDER_KEY must hold the lease manager's key in --watch mode");
  const { contract } = connect(cfg);
  const targets = parseWatchList(env.COMPUTE_WATCH);
  const history = new Map(targets.map((t) => [t.id, []]));
  log(`watching ${targets.length} lease(s)`);
  for (;;) {
    for (const t of targets) {
      let ok = false;
      try {
        ok = (await fetch(t.url, { signal: AbortSignal.timeout(10_000) })).ok;
      } catch {}
      const h = history.get(t.id);
      h.push(ok);
      if (h.length > 20) h.shift();
      try {
        const lease = toLease(await contract.lease(t.id));
        if (lease.closed) continue;
        const decision = watchDecision(h, { paused: lease.pausedAt !== 0n });
        if (decision !== "none" && !cfg.dryRun) await (await contract[decision](t.id)).wait();
        if (decision !== "none") log(`${decision} lease ${t.id} (${t.url})`);
      } catch (e) {
        log(`watch ${t.id} failed: ${e.message ?? e}`);
      }
    }
    await sleep(cfg.tickSeconds * 1000);
  }
}

async function main() {
  const args = process.argv.slice(2);
  if (args[0] === "--hash") {
    const manifest = JSON.parse(await readFile(args[1], "utf8"));
    const errors = validateManifest(manifest);
    if (errors.length) {
      console.error(errors.join("\n"));
      process.exitCode = 1;
    }
    console.log(specHash(manifest));
    return;
  }
  if (args[0] === "--manifest") {
    const entry = (await loadCatalog()).services.find((s) => s.service === args[1]);
    if (!entry) throw new Error(`no service ${args[1]} in catalog.json`);
    const manifest = render(entry, args[2] ?? "");
    const errors = validateManifest(manifest);
    if (errors.length) throw new Error(errors.join("; "));
    console.log(JSON.stringify({ manifest, specHash: specHash(manifest) }, null, 2));
    return;
  }
  if (args[0] === "--watch") return watch();
  return provide();
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((e) => {
    console.error(e.message ?? e);
    process.exit(1);
  });
}
