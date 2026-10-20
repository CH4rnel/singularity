import assert from "node:assert/strict";
import { test } from "node:test";

import { Meter, parseBytes, parseStatsLine, usageHash } from "../src/meter.mjs";

const line = (cpu, mem, rx, tx) =>
  JSON.stringify({ Name: "cyberia-lease-1", CPUPerc: `${cpu}%`, MemUsage: `${mem} / 1GiB`, NetIO: `${rx} / ${tx}` });

test("parses docker's human units", () => {
  assert.equal(parseBytes("1.5MiB"), 1_572_864);
  assert.equal(parseBytes("2kB"), 2000);
  assert.equal(parseBytes("0B"), 0);
  const s = parseStatsLine(line("50.00", "100MB", "1MB", "2MB"));
  assert.deepEqual(s, { name: "cyberia-lease-1", cpuPct: 50, memBytes: 100e6, netRx: 1e6, netTx: 2e6 });
});

test("integrates cpu, memory and traffic between samples", () => {
  const m = new Meter(1000);
  m.add(1, parseStatsLine(line("50", "100MB", "1MB", "1MB")), 1000);
  m.add(1, parseStatsLine(line("50", "100MB", "3MB", "5MB")), 1060);
  const [r] = m.close(1060);
  assert.equal(r.cpuMilliSeconds, 30_000); // half a core for 60 s
  assert.equal(r.memMbSeconds, 6000);
  assert.equal(r.ingressBytes, 2e6);
  assert.equal(r.egressBytes, 4e6);
  assert.equal(m.periodStart, 1060);
  assert.match(usageHash(r), /^0x[0-9a-f]{64}$/);
});

test("a counter that drops is a restart, not negative traffic", () => {
  const m = new Meter(0);
  m.add(1, parseStatsLine(line("0", "1MB", "10MB", "10MB")), 0);
  m.add(1, parseStatsLine(line("0", "1MB", "1MB", "2MB")), 30);
  const [r] = m.close(30);
  assert.equal(r.ingressBytes, 1e6);
  assert.equal(r.egressBytes, 2e6);
});
