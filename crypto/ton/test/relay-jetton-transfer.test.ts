import assert from "node:assert/strict";
import { test } from "node:test";
import { Address, beginCell } from "@ton/core";
import { parseJettonTransferQueryId } from "../scripts/relay-jetton-transfer.js";

const JETTON_TRANSFER_OP = 0x0f8a7ea5;

function buildTransferBody(queryId: bigint) {
  return beginCell()
    .storeUint(JETTON_TRANSFER_OP, 32)
    .storeUint(queryId, 64)
    .storeCoins(1_500_000_000n)
    .storeAddress(
      Address.parse("EQBcumfGKvl8jD1eAjRMggu7xf0JV7D1n5mj4zfYTOnuCXhp"),
    )
    .storeAddress(
      Address.parse("EQBofbbpUhtSvnZxOsPmzAv84fq1bG0-Mf79OPB4FrEXsT0I"),
    )
    .storeBit(false)
    .storeCoins(1n)
    .storeBit(false)
    .endCell();
}

test("parses query_id out of a jetton transfer body", () => {
  assert.equal(parseJettonTransferQueryId(buildTransferBody(42n)), 42n);
  assert.equal(
    parseJettonTransferQueryId(buildTransferBody(9007199254740993n)),
    9007199254740993n,
  );
});

test("rejects non-transfer bodies", () => {
  const comment = beginCell().storeUint(0, 32).storeUint(7n, 64).endCell();
  assert.equal(parseJettonTransferQueryId(comment), null);

  const empty = beginCell().endCell();
  assert.equal(parseJettonTransferQueryId(empty), null);
});
