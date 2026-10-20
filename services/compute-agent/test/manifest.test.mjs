import assert from "node:assert/strict";
import { test } from "node:test";

import { canonicalize, dockerRunArgs, specHash, validateManifest } from "../src/manifest.mjs";
import { manifest } from "./fixtures.mjs";

test("the hash is over canonical JSON, so key order and spacing do not change it", () => {
  const a = manifest();
  const b = JSON.parse(JSON.stringify(Object.fromEntries(Object.entries(a).reverse())));
  assert.equal(canonicalize(a), canonicalize(b));
  assert.equal(specHash(a), specHash(b));
  assert.match(specHash(a), /^0x[0-9a-f]{64}$/);
  // …and any change to what runs does.
  assert.notEqual(specHash(a), specHash(manifest({ resources: { cpus: 1, memoryMb: 256, pids: 128 } })));
});

test("a valid manifest has no errors", () => {
  assert.deepEqual(validateManifest(manifest()), []);
});

test("an image must be pinned by digest: a tag can be re-pointed after the lease is paid", () => {
  const errors = validateManifest(manifest({ image: "ghcr.io/cyberia-temple/cyberia-dns:latest" }));
  assert.ok(errors.some((e) => /digest/.test(e)));
});

test("a public-tier service cannot declare secrets; a trusted one names them without values", () => {
  assert.ok(validateManifest(manifest({ secrets: ["DEPLOYER_PK"] })).some((e) => /public-tier/.test(e)));
  assert.deepEqual(validateManifest(manifest({ tier: "trusted", secrets: ["DEPLOYER_PK"] })), []);
});

test("resources are mandatory", () => {
  const errors = validateManifest(manifest({ resources: {} }));
  assert.ok(errors.some((e) => /cpus/.test(e)));
  assert.ok(errors.some((e) => /memoryMb/.test(e)));
});

test("docker run carries the limits, drops every capability, and never prints a secret value", () => {
  const m = manifest({ tier: "trusted", secrets: ["BOT_TOKEN"] });
  const args = dockerRunArgs(m, { leaseId: 7, hash: "0xabc", env: { BOT_TOKEN: "s3cret" } });
  const joined = args.join(" ");
  assert.match(joined, /--cpus 0\.5/);
  assert.match(joined, /--memory 256m/);
  assert.match(joined, /--cap-drop ALL/);
  assert.match(joined, /--read-only/);
  assert.match(joined, /--label cyberia\.spec=0xabc/);
  assert.match(joined, /--publish 53:53\/udp/);
  assert.ok(args.includes("BOT_TOKEN"));
  assert.ok(!joined.includes("s3cret"));
  assert.ok(!joined.includes("--privileged") && !joined.includes("--network host"));
  assert.equal(args.at(-3), m.image);
});

test("a trusted service whose secret is missing on this provider does not start half-configured", () => {
  const m = manifest({ tier: "trusted", secrets: ["BOT_TOKEN"] });
  assert.throws(() => dockerRunArgs(m, { leaseId: 1, hash: "0x", env: {} }), /BOT_TOKEN/);
});
