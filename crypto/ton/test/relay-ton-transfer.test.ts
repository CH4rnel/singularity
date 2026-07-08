import assert from "node:assert/strict";
import { test } from "node:test";
import { beginCell } from "@ton/core";
import {
  bridgeComment,
  buildCommentBody,
  parseTextComment,
} from "../scripts/relay-ton-transfer.js";

test("round-trips the bridge comment through a body cell", () => {
  assert.equal(parseTextComment(buildCommentBody(42n)), "cyberia-bridge:42");
  assert.equal(
    parseTextComment(buildCommentBody(9007199254740993n)),
    bridgeComment(9007199254740993n),
  );
});

test("rejects non-comment bodies", () => {
  const jettonTransfer = beginCell()
    .storeUint(0x0f8a7ea5, 32)
    .storeUint(7n, 64)
    .endCell();
  assert.equal(parseTextComment(jettonTransfer), null);

  const empty = beginCell().endCell();
  assert.equal(parseTextComment(empty), null);
});
