import assert from "node:assert/strict";
import { test } from "node:test";

import { watchDecision } from "../src/watch.mjs";
import { parseWatchList, resolveManifests } from "../src/agent.mjs";
import { specHash } from "../src/manifest.mjs";
import { lease, manifest } from "./fixtures.mjs";

test("pauses after consecutive failures, not on one blip", () => {
  assert.equal(watchDecision([true, false, false], { paused: false }), "none");
  assert.equal(watchDecision([true, false, false, false], { paused: false }), "pause");
  assert.equal(watchDecision([false, false, false], { paused: true }), "none");
});

test("resumes a paused lease after the service is seen healthy again", () => {
  assert.equal(watchDecision([false, true, true, true], { paused: true }), "resume");
  assert.equal(watchDecision([true, true, true], { paused: false }), "none");
});

test("parses the watch list", () => {
  assert.deepEqual(parseWatchList("12=https://a/health, 13=https://b/x?y=1"), [
    { id: 12, url: "https://a/health" },
    { id: 13, url: "https://b/x?y=1" },
  ]);
});

test("a manifest is accepted only when it hashes to what the lease committed to", async () => {
  const m = manifest();
  const fetchImpl = async () => ({ ok: true, json: async () => m });
  const [good] = await resolveManifests([{ id: 1, lease: lease({ specHash: specHash(m) }) }], "https://gw/", fetchImpl);
  assert.deepEqual(good.manifest, m);
  const [bad] = await resolveManifests([{ id: 2, lease: lease({ specHash: "0x" + "0".repeat(64) }) }], "https://gw/", fetchImpl);
  assert.match(bad.manifestError, /mismatch/);
});
