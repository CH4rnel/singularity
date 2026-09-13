import * as bitcoin from 'bitcoinjs-lib';
import { ECPairFactory } from 'ecpair';
import * as ecc from 'tiny-secp256k1';

bitcoin.initEccLib(ecc);

const ECPair = ECPairFactory(ecc);

/**
 * Paying out real Bitcoin and Litecoin.
 *
 * The bridge's other UTXO corridor (Yenten) talks to a light-wallet API that
 * answers in `{result, error}` envelopes and hands out UTXOs on request. BTC
 * and LTC have something better and more boring: Esplora, the index behind
 * mempool.space and litecoinspace.org, which needs no key, answers a browser
 * and a server alike, and speaks the same four endpoints on both chains. So
 * this is the Yenten relay's shape with Esplora underneath — deliberately the
 * same shape, because the parts that are easy to get wrong (coin selection,
 * the fee, and what to do when a broadcast's answer is lost) are the parts
 * worth having in one form we already trust.
 *
 * Two rules carried over unchanged:
 *
 *   - **The recipient receives the amount exactly.** The network fee comes out
 *     of the pool, paid for upstream by the flat bridge fee this corridor
 *     retains. A payout that quietly delivered less than it promised would be
 *     a bridge that lies by an amount nobody can predict.
 *   - **A lost broadcast is not a lost payout.** The transaction is signed
 *     before anything is sent, so its txid is known in advance: if the POST
 *     fails or times out, the relay asks the index whether that txid exists
 *     rather than rebuilding and sending a second transfer.
 */

const DUST_SATOSHIS = 546n;

/** Litecoin is Bitcoin with three numbers changed; bitcoinjs ships neither. */
export const LITECOIN_NETWORK: bitcoin.Network = {
  messagePrefix: '\x19Litecoin Signed Message:\n',
  bech32: 'ltc',
  bip32: {
    public: 0x019da462,
    private: 0x019d9cfe,
  },
  pubKeyHash: 0x30,
  scriptHash: 0x32,
  wif: 0xb0,
};

export type ChainKey = 'bitcoin' | 'litecoin';

type ChainProfile = {
  network: bitcoin.Network;
  esplora: string;
  /** Coins per whole unit, for the human-readable log line only. */
  label: string;
};

export const CHAINS: Record<ChainKey, ChainProfile> = {
  bitcoin: {
    network: bitcoin.networks.bitcoin,
    esplora: 'https://mempool.space/api',
    label: 'BTC',
  },
  litecoin: {
    network: LITECOIN_NETWORK,
    esplora: 'https://litecoinspace.org/api',
    label: 'LTC',
  },
};

/** One unspent output as Esplora reports it. */
export type EsploraUtxo = {
  txid: string;
  vout: number;
  value: number;
  status: { confirmed: boolean; block_height?: number };
};

/** A UTXO tagged with the address (and so the key) that controls it. */
export type PoolUtxo = EsploraUtxo & { address: string };

/**
 * Legacy sizing: 148 bytes per P2PKH input, 34 per output, 10 of overhead.
 * Every address this pool controls is P2PKH (the bridge derives them that
 * way), so the input figure is exact rather than optimistic; a bech32 output
 * is smaller than 34 bytes, which leaves the estimate a little high, and high
 * is the safe direction for a fee.
 */
export const estimateFee = (
  inputCount: number,
  outputCount: number,
  satoshisPerKilobyte: bigint,
): bigint => {
  const estimatedBytes = BigInt(10 + inputCount * 148 + outputCount * 34);

  return (estimatedBytes * satoshisPerKilobyte + 999n) / 1000n;
};

/** Confirmed, sanely-sized outputs only: unconfirmed change is not spent. */
export const isSpendable = (utxo: EsploraUtxo): boolean =>
  Number.isSafeInteger(utxo.value) &&
  utxo.value > 0 &&
  utxo.status.confirmed === true &&
  Number.isInteger(utxo.status.block_height) &&
  (utxo.status.block_height ?? 0) > 0;

export const selectCoins = <T extends EsploraUtxo>(
  available: T[],
  amount: bigint,
  satoshisPerKilobyte: bigint,
): { inputs: T[]; total: bigint; fee: bigint; change: bigint } => {
  const sorted = [...available].sort((left, right) => right.value - left.value);
  const inputs: T[] = [];
  let total = 0n;

  for (const utxo of sorted) {
    if (!isSpendable(utxo)) {
      continue;
    }

    inputs.push(utxo);
    total += BigInt(utxo.value);

    const feeWithChange = estimateFee(inputs.length, 2, satoshisPerKilobyte);

    if (total >= amount + feeWithChange) {
      const change = total - amount - feeWithChange;

      // Change below the dust limit cannot be spent by anybody, so it is
      // handed to the miner instead of written into an output no wallet
      // will ever pick up.
      if (change < DUST_SATOSHIS) {
        return { inputs, total, fee: total - amount, change: 0n };
      }

      return { inputs, total, fee: feeWithChange, change };
    }
  }

  throw new Error('Insufficient relayer balance, including the network fee');
};

/**
 * Esplora's fee estimates, in satoshis per vbyte, keyed by confirmation
 * target. A missing target is not a reason to broadcast at one sat: the
 * fallback is the caller's floor, and the ceiling is there so a fee spike
 * cannot turn a small payout into a large one without a person seeing it.
 */
export const feeRateFor = (
  estimates: Record<string, number>,
  targetBlocks: number,
  floorSatPerVb: number,
  ceilingSatPerVb: number,
): bigint => {
  const candidates = Object.keys(estimates)
    .map((key) => Number(key))
    .filter((blocks) => Number.isFinite(blocks) && blocks >= targetBlocks)
    .sort((left, right) => left - right);

  const chosen = candidates.length > 0 ? estimates[String(candidates[0])] : undefined;
  const rate = Number.isFinite(chosen) && (chosen ?? 0) > 0 ? (chosen as number) : floorSatPerVb;

  return BigInt(Math.ceil(Math.min(Math.max(rate, floorSatPerVb), ceilingSatPerVb)));
};

const sleep = (ms: number): Promise<void> =>
  new Promise((resolve) => setTimeout(resolve, ms));

/**
 * One Esplora call. Reads are retried (a public index rate-limits and times
 * out under load, and a payout makes several calls); the caller passes
 * attempts = 1 for the broadcast, which is not idempotent and is reconciled
 * by txid instead.
 */
export const esplora = async (
  baseUrl: string,
  path: string,
  init?: RequestInit,
  attempts = 3,
): Promise<string> => {
  for (let attempt = 1; ; attempt += 1) {
    let response: Response;

    try {
      response = await fetch(`${baseUrl}${path}`, {
        ...init,
        signal: AbortSignal.timeout(20_000),
      });
    } catch (error) {
      if (attempt >= attempts) {
        throw error;
      }

      await sleep(attempt * 1_000);
      continue;
    }

    const body = (await response.text()).trim();

    if (!response.ok) {
      if (response.status >= 500 && attempt < attempts) {
        await sleep(attempt * 1_000);
        continue;
      }

      throw new Error(`Esplora ${path} returned HTTP ${response.status}: ${body.slice(0, 200)}`);
    }

    return body;
  }
};

const esploraJson = async <T>(
  baseUrl: string,
  path: string,
  attempts = 3,
): Promise<T> => JSON.parse(await esplora(baseUrl, path, undefined, attempts)) as T;

/**
 * A node that already has the transaction says so in the error text rather
 * than in the status code. That is a success — the payout is on its way —
 * and treating it as a failure is how a bridge pays twice.
 */
export const alreadyBroadcast = (message: string): boolean =>
  /txn-already-known|txn-already-in-mempool|already in block chain|transaction already in block chain|duplicate transaction/i.test(
    message,
  );

/** Resolve the pool of relayer keys from env (JSON array, or a single WIF). */
const resolveKeys = (
  network: bitcoin.Network,
): Map<string, ReturnType<typeof ECPair.fromWIF>> => {
  const raw = process.env.UTXO_RELAYER_WIFS?.trim();
  const wifs: string[] = raw
    ? (JSON.parse(raw) as string[])
    : [process.env.UTXO_RELAYER_WIF?.trim() ?? ''];

  const keys = new Map<string, ReturnType<typeof ECPair.fromWIF>>();

  for (const wif of wifs) {
    if (!wif) {
      continue;
    }

    const keyPair = ECPair.fromWIF(wif, network);
    const { address } = bitcoin.payments.p2pkh({
      pubkey: Buffer.from(keyPair.publicKey),
      network,
    });

    if (address) {
      keys.set(address, keyPair);
    }
  }

  if (keys.size === 0) {
    throw new Error('No relayer keys configured (UTXO_RELAYER_WIFS / UTXO_RELAYER_WIF)');
  }

  return keys;
};

const main = async (): Promise<void> => {
  const [chainArgument, recipient, amountArgument, requestId] = process.argv.slice(2);
  const chain = CHAINS[chainArgument as ChainKey];

  if (!chain) {
    throw new Error('Usage: relay-utxo-transfer.ts <bitcoin|litecoin> <recipient> <amount-satoshis> [request-id]');
  }

  if (!recipient || !amountArgument || !/^\d+$/.test(amountArgument)) {
    throw new Error('Usage: relay-utxo-transfer.ts <bitcoin|litecoin> <recipient> <amount-satoshis> [request-id]');
  }

  const apiUrl = (process.env.UTXO_ESPLORA_URL?.trim() || chain.esplora).replace(/\/$/, '');
  const network = chain.network;

  // Throws on an address that is not this chain's — a Litecoin address in a
  // Bitcoin payout would otherwise be caught by nothing until the coins were
  // already gone.
  bitcoin.address.toOutputScript(recipient, network);

  const keys = resolveKeys(network);
  const changeAddress = process.env.UTXO_CHANGE_ADDRESS?.trim() || keys.keys().next().value!;

  bitcoin.address.toOutputScript(changeAddress, network);

  const amount = BigInt(amountArgument);

  if (amount <= 0n) {
    throw new Error('Payout amount must be positive');
  }

  if (amount < DUST_SATOSHIS) {
    throw new Error(`Payout amount is below the dust limit (${DUST_SATOSHIS} satoshis)`);
  }

  if (amount > BigInt(Number.MAX_SAFE_INTEGER)) {
    throw new Error('Payout amount exceeds the safe transaction limit');
  }

  const targetBlocks = Number(process.env.UTXO_FEE_TARGET_BLOCKS ?? 6);
  const floorSatPerVb = Number(process.env.UTXO_MIN_FEE_RATE ?? 1);
  const ceilingSatPerVb = Number(process.env.UTXO_MAX_FEE_RATE ?? 200);
  const estimates = await esploraJson<Record<string, number>>(apiUrl, '/fee-estimates');
  const feeRate = feeRateFor(estimates, targetBlocks, floorSatPerVb, ceilingSatPerVb) * 1000n;

  // Address by address, central wallet first, stopping as soon as what is
  // spendable covers the payout plus a fee sized as if every pooled UTXO were
  // spent — selectCoins picks a subset, so the real fee is never larger. A
  // hundred empty one-time deposit addresses are then never asked about.
  const pool: PoolUtxo[] = [];
  let spendable = 0n;

  for (const address of keys.keys()) {
    const unspent = await esploraJson<EsploraUtxo[]>(
      apiUrl,
      `/address/${encodeURIComponent(address)}/utxo`,
    );

    for (const utxo of unspent) {
      pool.push({ ...utxo, address });

      if (isSpendable(utxo)) {
        spendable += BigInt(utxo.value);
      }
    }

    if (spendable >= amount + estimateFee(pool.length, 2, feeRate)) {
      break;
    }
  }

  if (pool.length > 500) {
    throw new Error('Too many UTXOs across relayer addresses; consolidate before payout');
  }

  const selection = selectCoins(pool, amount, feeRate);
  const psbt = new bitcoin.Psbt({ network });

  for (const input of selection.inputs) {
    // P2PKH inputs are signed over the whole previous transaction, so the
    // index has to hand it over in full; there is no witness shortcut here.
    const previousHex = await esplora(apiUrl, `/tx/${input.txid}/hex`);

    if (!/^[0-9a-fA-F]+$/.test(previousHex)) {
      throw new Error(`Esplora did not return raw transaction ${input.txid}`);
    }

    psbt.addInput({
      hash: input.txid,
      index: input.vout,
      nonWitnessUtxo: Buffer.from(previousHex, 'hex'),
    });
  }

  psbt.addOutput({ address: recipient, value: Number(amount) });

  if (selection.change >= DUST_SATOSHIS) {
    psbt.addOutput({ address: changeAddress, value: Number(selection.change) });
  }

  selection.inputs.forEach((input, index) => {
    const keyPair = keys.get(input.address);

    if (!keyPair) {
      throw new Error(`No key for input address ${input.address}`);
    }

    psbt.signInput(index, keyPair);
  });
  psbt.finalizeAllInputs();

  const transaction = psbt.extractTransaction();
  const txid = transaction.getId();

  // Printed before the broadcast, so the row carries a hash even if this
  // process is killed between sending and reporting. Laravel reads this line
  // as it is written.
  console.log(JSON.stringify({ broadcastTxHash: txid }));

  let txHash = '';

  try {
    const answer = await esplora(
      apiUrl,
      '/tx',
      {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain' },
        body: transaction.toHex(),
      },
      1,
    );

    txHash = /^[0-9a-fA-F]{64}$/.test(answer) ? answer : txid;
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);

    if (alreadyBroadcast(message)) {
      txHash = txid;
    } else {
      // A lost answer is not a lost broadcast: this exact transaction may
      // already be in the mempool. Never rebuild and never send a second one
      // — ask the index whether the txid we already know exists.
      for (let attempt = 0; attempt < 4 && !txHash; attempt += 1) {
        await sleep(10_000);

        try {
          await esploraJson<{ txid?: string }>(apiUrl, `/tx/${txid}`, 1);
          txHash = txid;
        } catch {
          // Not visible yet — keep asking.
        }
      }

      if (!txHash) {
        throw error;
      }
    }
  }

  console.log(
    JSON.stringify({
      txHash,
      chain: chainArgument,
      requestId: requestId ?? null,
      amount: amount.toString(),
      fee: selection.fee.toString(),
      feeRateSatPerVb: (feeRate / 1000n).toString(),
      inputCount: selection.inputs.length,
      spentAddresses: [...new Set(selection.inputs.map((input) => input.address))],
    }),
  );
};

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((error: unknown) => {
    const message = error instanceof Error ? error.message : String(error);
    console.error(message);
    process.exitCode = 1;
  });
}
