import assert from 'node:assert/strict';
import test from 'node:test';

import { estimateFee, selectCoins, type YentenUtxo } from '../scripts/relay-yenten-transfer.js';

const utxo = (value: number, suffix: string): YentenUtxo => ({
  txid: suffix.padStart(64, '0'),
  index: 0,
  script: '76a914000000000000000000000000000000000000000088ac',
  value,
  height: 1,
});

test('estimates a ceil-rounded legacy transaction fee', () => {
  assert.equal(estimateFee(1, 2, 1000n), 226n);
  assert.equal(estimateFee(7, 2, 1000n), 1114n);
});

test('selects largest UTXOs first and reserves the network fee', () => {
  const result = selectCoins(
    [utxo(25_000, '1'), utxo(70_000, '2'), utxo(40_000, '3')],
    100_000n,
    1000n,
  );

  assert.deepEqual(result.inputs.map((input) => input.value), [70_000, 40_000]);
  assert.equal(result.fee, 374n);
  assert.equal(result.change, 9_626n);
});

test('rejects a payout larger than available balance plus fee', () => {
  assert.throws(
    () => selectCoins([utxo(100_000, '1')], 100_000n, 1000n),
    /Insufficient YTN relayer balance/,
  );
});

test('adds sub-dust change to the fee instead of creating an output', () => {
  const result = selectCoins([utxo(100_500, '1')], 100_000n, 1000n);

  assert.equal(result.change, 0n);
  assert.equal(result.fee, 500n);
});
