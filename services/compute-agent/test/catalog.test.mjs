import assert from "node:assert/strict";
import { test } from "node:test";

import { loadCatalog, render } from "../src/catalog.mjs";
import { validateManifest } from "../src/manifest.mjs";

const DIGEST = `sha256:${"b".repeat(64)}`;

test("every catalog service renders to a runnable manifest", async () => {
  const { services } = await loadCatalog();
  assert.ok(services.length >= 8);
  for (const entry of services) {
    const m = render(entry, DIGEST);
    assert.deepEqual(validateManifest(m), [], entry.service);
    assert.ok(!("wave" in m) && !("treasury" in m), "bookkeeping stays out of the hash");
  }
});

test("anything holding a secret is trusted tier, and the chain's own key is never leased", async () => {
  const { services, neverLeased } = await loadCatalog();
  for (const s of services) if ((s.secrets ?? []).length) assert.equal(s.tier, "trusted", s.service);
  assert.ok(neverLeased.some((n) => /validator/.test(n.service)));
  assert.ok(!services.some((s) => /validator/.test(s.service)));
});

test("a digest is required", () => {
  assert.throws(() => render({ service: "x", image: "ghcr.io/x" }, "latest"), /digest/);
});
