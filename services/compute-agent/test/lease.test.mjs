import assert from "node:assert/strict";
import { test } from "node:test";

import { isPaying, owed, remaining, runway } from "../src/lease.mjs";
import { lease } from "./fixtures.mjs";

test("accrual is rate × seconds since settlement", () => {
  const l = lease();
  assert.equal(owed(l, 1_000_100), 100n * l.rate);
  assert.equal(remaining(l, 1_000_100), l.balance - 100n * l.rate);
  assert.equal(runway(l, 1_000_100), 86_400n - 100n);
});

test("accrual never exceeds the prepaid balance", () => {
  const l = lease();
  assert.equal(owed(l, 1_000_000 + 10 * 86_400), l.balance);
  assert.equal(runway(l, 1_000_000 + 10 * 86_400), 0n);
  assert.equal(isPaying(l, 1_000_000 + 10 * 86_400), false);
});

test("a paused lease accrues only up to the pause", () => {
  const l = lease({ pausedAt: 1_000_050n });
  assert.equal(owed(l, 2_000_000), 50n * l.rate);
  assert.equal(isPaying(l, 1_000_060), false);
});

test("a closed lease owes and pays nothing", () => {
  const l = lease({ closed: true });
  assert.equal(owed(l, 2_000_000), 0n);
  assert.equal(runway(l, 2_000_000), 0n);
});
