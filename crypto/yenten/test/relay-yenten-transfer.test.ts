import assert from 'node:assert/strict';
import test from 'node:test';

import {
  apiRequest,
  estimateFee,
  selectCoins,
  type PoolUtxo,
  type YentenUtxo,
} from '../scripts/relay-yenten-transfer.js';

const utxo = (value: number, suffix: string): YentenUtxo => ({
  txid: suffix.padStart(64, '0'),
  index: 0,
  script: '76a914000000000000000000000000000000000000000088ac',
  value,
  height: 1,
});

const poolUtxo = (value: number, address: string, suffix: string): PoolUtxo => ({
  ...utxo(value, suffix),
  address,
});

const FEE_RATE = 1000n;

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

test('pools UTXOs across multiple deposit addresses and keeps their keys', () => {
  const amount = 150_000_000n;
  const sel = selectCoins(
    [poolUtxo(100_000_000, 'Ydep1', '1'), poolUtxo(100_000_000, 'Ydep2', '2')],
    amount,
    FEE_RATE,
  );

  assert.equal(sel.inputs.length, 2);
  assert.deepEqual(
    [...new Set(sel.inputs.map((i) => i.address))].sort(),
    ['Ydep1', 'Ydep2'],
  );
  // The recipient receives `amount` untouched; the fee comes out of change.
  assert.equal(amount + sel.change + sel.fee, sel.total);
});

test('throws when the pool covers the amount but not the network fee', () => {
  assert.throws(
    () => selectCoins([poolUtxo(100_000_000, 'Yaddr', '1')], 100_000_000n, FEE_RATE),
    /Insufficient/,
  );
});

test('apiRequest retries a timed-out read and then succeeds', async (t) => {
  let calls = 0;

  t.mock.method(globalThis, 'fetch', async (): Promise<Response> => {
    calls += 1;

    if (calls === 1) {
      throw new Error('The operation was aborted due to timeout');
    }

    return new Response(JSON.stringify({ result: 1234, error: null }));
  });

  assert.equal(await apiRequest<number>('https://api.test', '/fee'), 1234);
  assert.equal(calls, 2);
});

test('apiRequest does not retry an API-level error', async (t) => {
  let calls = 0;

  t.mock.method(globalThis, 'fetch', async (): Promise<Response> => {
    calls += 1;

    return new Response(
      JSON.stringify({ result: null, error: { message: 'bad transaction' } }),
    );
  });

  await assert.rejects(apiRequest('https://api.test', '/broadcast'), /bad transaction/);
  assert.equal(calls, 1);
});

test('apiRequest gives up after the configured attempts', async (t) => {
  let calls = 0;

  t.mock.method(globalThis, 'fetch', async (): Promise<Response> => {
    calls += 1;
    throw new Error('The operation was aborted due to timeout');
  });

  await assert.rejects(
    apiRequest('https://api.test', '/broadcast', undefined, 1),
    /aborted due to timeout/,
  );
  assert.equal(calls, 1);
});
