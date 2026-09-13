import assert from 'node:assert/strict';
import test from 'node:test';

import * as bitcoin from 'bitcoinjs-lib';

import {
  CHAINS,
  LITECOIN_NETWORK,
  alreadyBroadcast,
  estimateFee,
  feeRateFor,
  isSpendable,
  selectCoins,
  type EsploraUtxo,
} from '../scripts/relay-utxo-transfer.js';

const utxo = (value: number, suffix: string, confirmed = true): EsploraUtxo => ({
  txid: suffix.padStart(64, '0'),
  vout: 0,
  value,
  status: { confirmed, block_height: confirmed ? 800_000 : undefined },
});

/** 1 sat/vB, the shape the relay passes around (satoshis per kilobyte). */
const FEE_RATE = 1000n;

test('estimates a ceil-rounded legacy transaction fee', () => {
  assert.equal(estimateFee(1, 2, 1000n), 226n);
  assert.equal(estimateFee(7, 2, 1000n), 1114n);
});

test('selects the largest outputs first and reserves the network fee', () => {
  const result = selectCoins(
    [utxo(25_000, '1'), utxo(70_000, '2'), utxo(40_000, '3')],
    100_000n,
    FEE_RATE,
  );

  assert.deepEqual(
    result.inputs.map((input) => input.value),
    [70_000, 40_000],
  );
  assert.equal(result.fee, estimateFee(2, 2, FEE_RATE));
  assert.equal(result.change, 110_000n - 100_000n - result.fee);
});

test('folds change below the dust limit into the fee instead of writing it', () => {
  // Exactly the payout plus the fee plus 100 satoshis: an output nobody could
  // ever spend. It goes to the miner rather than into the transaction.
  const fee = estimateFee(1, 2, FEE_RATE);
  const value = Number(100_000n + fee + 100n);

  const result = selectCoins([utxo(value, '1')], 100_000n, FEE_RATE);

  assert.equal(result.change, 0n);
  assert.equal(result.fee, BigInt(value) - 100_000n);
});

test('never spends an unconfirmed output', () => {
  assert.equal(isSpendable(utxo(500_000, '1', false)), false);

  assert.throws(
    () => selectCoins([utxo(500_000, '1', false)], 100_000n, FEE_RATE),
    /Insufficient relayer balance/,
  );
});

test('refuses a payout the pool cannot cover once the fee is counted', () => {
  // Enough for the amount, not enough for the amount *and* the fee — the
  // recipient is promised the exact figure, so this is a refusal and not a
  // smaller transfer.
  assert.throws(
    () => selectCoins([utxo(100_050, '1')], 100_000n, FEE_RATE),
    /Insufficient relayer balance/,
  );
});

test('takes the nearest fee estimate at or beyond the target', () => {
  const estimates = { '1': 40.4, '3': 21.2, '6': 9.1, '144': 1.02 };

  assert.equal(feeRateFor(estimates, 6, 1, 200), 10n);
  assert.equal(feeRateFor(estimates, 2, 1, 200), 22n);
});

test('clamps a fee estimate to the floor and the ceiling', () => {
  // A spike must not turn a small payout into a large one unnoticed, and a
  // missing or nonsense estimate must not broadcast something no miner takes.
  assert.equal(feeRateFor({ '6': 4_000 }, 6, 1, 200), 200n);
  assert.equal(feeRateFor({ '6': 0 }, 6, 3, 200), 3n);
  assert.equal(feeRateFor({}, 6, 5, 200), 5n);
});

test('reads a node saying it already has the transaction as success', () => {
  assert.equal(
    alreadyBroadcast('Esplora /tx returned HTTP 400: sendrawtransaction RPC error: txn-already-known'),
    true,
  );
  assert.equal(
    alreadyBroadcast('Esplora /tx returned HTTP 400: transaction already in block chain'),
    true,
  );
  assert.equal(alreadyBroadcast('Esplora /tx returned HTTP 400: bad-txns-inputs-missingorspent'), false);
});

test('derives Bitcoin addresses from the published private key 1 vector', () => {
  const pubkey = Buffer.from(
    '0279BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798',
    'hex',
  );

  const { address } = bitcoin.payments.p2pkh({
    pubkey,
    network: CHAINS.bitcoin.network,
  });

  assert.equal(address, '1BgGZ9tcN4rm9KBzDn7KprQz87SZ26SAMH');
});

test('pins the Litecoin version bytes, which bitcoinjs does not ship', () => {
  const pubkey = Buffer.from(
    '0279BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798',
    'hex',
  );

  const p2pkh = bitcoin.payments.p2pkh({ pubkey, network: LITECOIN_NETWORK });
  const p2wpkh = bitcoin.payments.p2wpkh({ pubkey, network: LITECOIN_NETWORK });

  // Same key, same hash160 as Bitcoin above: only the version byte differs,
  // and getting that wrong sends coins to an address on the wrong chain.
  assert.equal(bitcoin.address.fromBase58Check(p2pkh.address!).version, 0x30);
  assert.match(p2pkh.address!, /^L/);
  assert.match(p2wpkh.address!, /^ltc1/);
  assert.equal(LITECOIN_NETWORK.wif, 0xb0);
});

test('rejects an address from the other chain', () => {
  const litecoin = bitcoin.payments.p2pkh({
    pubkey: Buffer.from(
      '0279BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798',
      'hex',
    ),
    network: LITECOIN_NETWORK,
  }).address!;

  assert.throws(() => bitcoin.address.toOutputScript(litecoin, CHAINS.bitcoin.network));
  assert.throws(
    () => bitcoin.address.toOutputScript('1BgGZ9tcN4rm9KBzDn7KprQz87SZ26SAMH', LITECOIN_NETWORK),
  );
});
