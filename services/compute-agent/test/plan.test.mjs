import assert from "node:assert/strict";
import { test } from "node:test";

import { plan } from "../src/plan.mjs";
import { lease, manifest } from "./fixtures.mjs";

const NOW = 1_000_100;
const types = (actions) => actions.map((a) => a.type);

test("starts a paid lease that has no container", () => {
  const actions = plan({ leases: [{ id: 1, lease: lease(), manifest: manifest() }], containers: [], now: NOW, trusted: false });
  assert.deepEqual(types(actions), ["start"]);
});

test("leaves a healthy, current container alone", () => {
  const actions = plan({
    leases: [{ id: 1, lease: lease(), manifest: manifest() }],
    containers: [{ name: "c1", leaseId: 1, spec: "0xspec", running: true }],
    now: NOW, trusted: false,
  });
  assert.deepEqual(actions, []);
});

test("redeploys when the lease points at a new release", () => {
  const actions = plan({
    leases: [{ id: 1, lease: lease({ specHash: "0xv2" }), manifest: manifest() }],
    containers: [{ name: "c1", leaseId: 1, spec: "0xspec", running: true }],
    now: NOW, trusted: false,
  });
  assert.deepEqual(types(actions), ["redeploy"]);
});

test("stops working the moment the runway is gone — the provider's only protection", () => {
  const actions = plan({
    leases: [{ id: 1, lease: lease({ balance: 50n * 1_000_000_000n }), manifest: manifest() }],
    containers: [{ name: "c1", leaseId: 1, spec: "0xspec", running: true }],
    now: NOW, trusted: false,
  });
  assert.deepEqual(actions, [{ type: "stop", leaseId: 1, name: "c1", reason: "out of runway" }]);
});

test("keeps a paused container up for the grace period, then stops it", () => {
  const paused = { id: 1, lease: lease({ pausedAt: 1_000_050n }), manifest: manifest() };
  const containers = [{ name: "c1", leaseId: 1, spec: "0xspec", running: true }];
  assert.deepEqual(plan({ leases: [paused], containers, now: 1_000_050 + 60, trusted: false }), []);
  assert.deepEqual(types(plan({ leases: [paused], containers, now: 1_000_050 + 901, trusted: false })), ["stop"]);
});

test("removes the container of a closed lease", () => {
  const actions = plan({
    leases: [{ id: 1, lease: lease({ closed: true }) }],
    containers: [{ name: "c1", leaseId: 1, spec: "0xspec", running: true }],
    now: NOW, trusted: false,
  });
  assert.deepEqual(types(actions), ["stop", "remove"]);
});

test("refuses a lease whose manifest does not match, rather than running something else", () => {
  const actions = plan({
    leases: [{ id: 1, lease: lease(), manifestError: "spec hash mismatch" }],
    containers: [], now: NOW, trusted: false,
  });
  assert.deepEqual(actions, [{ type: "reject", leaseId: 1, reason: "spec hash mismatch" }]);
});

test("an untrusted provider refuses a service that holds secrets", () => {
  const entry = { id: 1, lease: lease(), manifest: manifest({ tier: "trusted", secrets: ["X"] }) };
  assert.deepEqual(types(plan({ leases: [entry], containers: [], now: NOW, trusted: false })), ["reject"]);
  assert.deepEqual(types(plan({ leases: [entry], containers: [], now: NOW, trusted: true })), ["start"]);
});

test("warns while there is still time to top up", () => {
  const actions = plan({
    leases: [{ id: 1, lease: lease({ balance: 3600n * 1_000_000_000n }), manifest: manifest() }],
    containers: [{ name: "c1", leaseId: 1, spec: "0xspec", running: true }],
    now: NOW, trusted: false,
  });
  assert.deepEqual(actions, [{ type: "warn", leaseId: 1, runwaySeconds: 3500 }]);
});

test("removes containers whose lease this provider does not hold", () => {
  const actions = plan({ leases: [], containers: [{ name: "x", leaseId: 9, spec: "0x", running: true }], now: NOW, trusted: false });
  assert.deepEqual(types(actions), ["remove"]);
});
