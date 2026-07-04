/**
 * Sweep a one-time deposit address into the central relayer wallet.
 *
 * A bridge-in on Yenten lands coins on a per-request address derived from the
 * master seed. This consolidates those coins (all UTXOs, minus network fee)
 * onto the central wallet so evm_to_yenten payouts have liquidity.
 *
 * Usage:
 *   YENTEN_CHILD_WIF=<wif> npx tsx scripts/sweep-deposit.ts <destination-address>
 *
 * env:
 *   YENTEN_CHILD_WIF — WIF of the one-time deposit address (derived in PHP)
 *   YENTEN_API_URL   — Yenten light-wallet API (default api.yentencoin.info)
 *
 * stdout (last line): {"txHash":"...","amount":"...","fee":"..."}
 */
import * as bitcoin from 'bitcoinjs-lib';
import { ECPairFactory } from 'ecpair';
import * as ecc from 'tiny-secp256k1';
import {
  apiRequest,
  estimateFee,
  YENTEN_NETWORK,
  type YentenUtxo,
} from './relay-yenten-transfer.js';

bitcoin.initEccLib(ecc);
const ECPair = ECPairFactory(ecc);
const DUST_SATOSHIS = 546n;

const main = async (): Promise<void> => {
  const [destination] = process.argv.slice(2);
  const wif = process.env.YENTEN_CHILD_WIF?.trim();
  const apiUrl = (process.env.YENTEN_API_URL ?? 'https://api.yentencoin.info').replace(/\/$/, '');

  if (!destination) {
    throw new Error('Usage: sweep-deposit.ts <destination-address>');
  }

  if (!wif) {
    throw new Error('YENTEN_CHILD_WIF is not configured');
  }

  bitcoin.address.toOutputScript(destination, YENTEN_NETWORK);

  const keyPair = ECPair.fromWIF(wif, YENTEN_NETWORK);
  const payment = bitcoin.payments.p2pkh({
    pubkey: Buffer.from(keyPair.publicKey),
    network: YENTEN_NETWORK,
  });
  const fromAddress = payment.address;

  if (!fromAddress) {
    throw new Error('Could not derive deposit address from WIF');
  }

  const feeResult = await apiRequest<{ feerate?: number }>(apiUrl, '/fee');
  const feeRate = BigInt(Math.max(1000, Math.ceil(feeResult.feerate ?? 1000)));
  const unspent = await apiRequest<YentenUtxo[]>(
    apiUrl,
    `/unspent/${encodeURIComponent(fromAddress)}?amount=0`,
  );

  const usable = unspent.filter(
    (utxo) => Number.isSafeInteger(utxo.value) && utxo.value > 0,
  );

  if (usable.length === 0) {
    throw new Error('Deposit address has no spendable UTXOs');
  }

  const total = usable.reduce((sum, utxo) => sum + BigInt(utxo.value), 0n);
  // Sweep = one output, no change.
  const fee = estimateFee(usable.length, 1, feeRate);
  const payout = total - fee;

  if (payout < DUST_SATOSHIS) {
    throw new Error('Deposit balance does not cover the network fee');
  }

  const psbt = new bitcoin.Psbt({ network: YENTEN_NETWORK });

  for (const utxo of usable) {
    const previous = await apiRequest<{ hex?: string }>(apiUrl, `/transaction/${utxo.txid}`);

    if (!previous.hex || !/^[0-9a-fA-F]+$/.test(previous.hex)) {
      throw new Error(`Yenten API did not return raw transaction ${utxo.txid}`);
    }

    psbt.addInput({
      hash: utxo.txid,
      index: utxo.index,
      nonWitnessUtxo: Buffer.from(previous.hex, 'hex'),
    });
  }

  psbt.addOutput({ address: destination, value: Number(payout) });
  psbt.signAllInputs(keyPair);
  psbt.finalizeAllInputs();

  const transaction = psbt.extractTransaction();
  const form = new URLSearchParams({ raw: transaction.toHex() });
  const txHash = await apiRequest<string>(apiUrl, '/broadcast', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form,
  });

  console.log(JSON.stringify({
    txHash,
    amount: payout.toString(),
    fee: fee.toString(),
    inputCount: usable.length,
  }));
};

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((error: unknown) => {
    const message = error instanceof Error ? error.message : String(error);
    console.error(message);
    process.exitCode = 1;
  });
}
