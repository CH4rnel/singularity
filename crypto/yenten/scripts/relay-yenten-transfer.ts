import * as bitcoin from 'bitcoinjs-lib';
import { ECPairFactory } from 'ecpair';
import * as ecc from 'tiny-secp256k1';

bitcoin.initEccLib(ecc);

const ECPair = ECPairFactory(ecc);
const DUST_SATOSHIS = 546n;

export const YENTEN_NETWORK: bitcoin.Network = {
  messagePrefix: '\x19Yenten Signed Message:\n',
  bech32: 'ytn',
  bip32: {
    public: 0x0488b21e,
    private: 0x0488ade4,
  },
  pubKeyHash: 0x4e,
  scriptHash: 0x0a,
  wif: 0x7b,
};

export type YentenUtxo = {
  txid: string;
  index: number;
  script: string;
  value: number;
  height: number;
};

/** A UTXO tagged with the address (and its signing key) that controls it. */
export type PoolUtxo = YentenUtxo & { address: string };

type ApiEnvelope<T> = {
  result?: T;
  error?: { code?: number; message?: string } | null;
};

export const estimateFee = (
  inputCount: number,
  outputCount: number,
  satoshisPerKilobyte: bigint,
): bigint => {
  const estimatedBytes = BigInt(10 + inputCount * 148 + outputCount * 34);

  return (estimatedBytes * satoshisPerKilobyte + 999n) / 1000n;
};

/** Confirmed, sanely-sized UTXOs only — what selectCoins is willing to spend. */
export const isSpendable = (utxo: YentenUtxo): boolean =>
  Number.isSafeInteger(utxo.value) &&
  utxo.value > 0 &&
  Number.isInteger(utxo.height) &&
  utxo.height > 0;

export const selectCoins = <T extends YentenUtxo>(
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
      let change = total - amount - feeWithChange;

      if (change < DUST_SATOSHIS) {
        return { inputs, total, fee: total - amount, change: 0n };
      }

      return { inputs, total, fee: feeWithChange, change };
    }
  }

  throw new Error('Insufficient YTN relayer balance, including network fee');
};

const sleep = (ms: number): Promise<void> =>
  new Promise((resolve) => setTimeout(resolve, ms));

/**
 * The public light-wallet API is slow under load and a payout makes many
 * calls, so transient failures (timeout, network, 5xx) are retried with
 * backoff. Deterministic answers (4xx, API-level errors) are not. Callers
 * pass attempts = 1 for the non-idempotent /broadcast.
 */
export const apiRequest = async <T>(
  apiUrl: string,
  path: string,
  init?: RequestInit,
  attempts = 3,
): Promise<T> => {
  for (let attempt = 1; ; attempt += 1) {
    let response: Response;

    try {
      response = await fetch(`${apiUrl}${path}`, {
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

    if (!response.ok) {
      if (response.status >= 500 && attempt < attempts) {
        await sleep(attempt * 1_000);
        continue;
      }

      throw new Error(`Yenten API returned HTTP ${response.status}`);
    }

    const body = (await response.json()) as ApiEnvelope<T>;

    if (body.error || body.result === undefined) {
      throw new Error(body.error?.message ?? 'Yenten API returned no result');
    }

    return body.result;
  }
};

/** Resolve the pool of relayer keys from env (JSON array, or single WIF). */
const resolveKeys = (): Map<string, ReturnType<typeof ECPair.fromWIF>> => {
  const raw = process.env.YENTEN_RELAYER_WIFS?.trim();
  const wifs: string[] = raw
    ? (JSON.parse(raw) as string[])
    : [process.env.YENTEN_RELAYER_WIF?.trim() ?? ''];

  const keys = new Map<string, ReturnType<typeof ECPair.fromWIF>>();

  for (const wif of wifs) {
    if (!wif) {
      continue;
    }

    const keyPair = ECPair.fromWIF(wif, YENTEN_NETWORK);
    const { address } = bitcoin.payments.p2pkh({
      pubkey: Buffer.from(keyPair.publicKey),
      network: YENTEN_NETWORK,
    });

    if (address) {
      keys.set(address, keyPair);
    }
  }

  if (keys.size === 0) {
    throw new Error('No Yenten relayer keys configured (YENTEN_RELAYER_WIFS / YENTEN_RELAYER_WIF)');
  }

  return keys;
};

const main = async (): Promise<void> => {
  const [recipient, amountArgument, requestId] = process.argv.slice(2);
  const apiUrl = (process.env.YENTEN_API_URL ?? 'https://api.yentencoin.info').replace(/\/$/, '');

  if (!recipient || !amountArgument || !/^\d+$/.test(amountArgument)) {
    throw new Error('Usage: relay-yenten-transfer.ts <recipient> <amount-satoshis> [request-id]');
  }

  bitcoin.address.toOutputScript(recipient, YENTEN_NETWORK);

  const keys = resolveKeys();
  const changeAddress =
    process.env.YENTEN_CHANGE_ADDRESS?.trim() || keys.keys().next().value!;

  const amount = BigInt(amountArgument);

  if (amount <= 0n) {
    throw new Error('Yenten payout amount must be positive');
  }

  if (amount > BigInt(Number.MAX_SAFE_INTEGER)) {
    throw new Error('Yenten payout amount exceeds the safe transaction limit');
  }

  const feeResult = await apiRequest<{ feerate?: number }>(apiUrl, '/fee');
  const feeRate = BigInt(Math.max(1000, Math.ceil(feeResult.feerate ?? 1000)));

  // Pool UTXOs address by address — the central wallet comes first in the
  // key list — and stop as soon as spendable funds cover the payout plus a
  // fee sized as if every pooled UTXO were spent (selectCoins picks a
  // subset, so its real fee is never larger). Dozens of empty one-time
  // deposit addresses are then never queried at all.
  const pool: PoolUtxo[] = [];
  let spendable = 0n;

  for (const address of keys.keys()) {
    const unspent = await apiRequest<YentenUtxo[]>(
      apiUrl,
      `/unspent/${encodeURIComponent(address)}?amount=0`,
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

  // The recipient receives exactly `amount` (the net amount the bridge
  // promised after retaining its fee); the network fee is paid by the pool.
  const selection = selectCoins(pool, amount, feeRate);
  const psbt = new bitcoin.Psbt({ network: YENTEN_NETWORK });

  for (const input of selection.inputs) {
    const previous = await apiRequest<{ hex?: string }>(apiUrl, `/transaction/${input.txid}`);

    if (!previous.hex || !/^[0-9a-fA-F]+$/.test(previous.hex)) {
      throw new Error(`Yenten API did not return raw transaction ${input.txid}`);
    }

    psbt.addInput({
      hash: input.txid,
      index: input.index,
      nonWitnessUtxo: Buffer.from(previous.hex, 'hex'),
    });
  }

  psbt.addOutput({ address: recipient, value: Number(amount) });

  if (selection.change >= DUST_SATOSHIS) {
    psbt.addOutput({ address: changeAddress, value: Number(selection.change) });
  }

  // Each input is signed by the key that controls its source address.
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
  const form = new URLSearchParams({ raw: transaction.toHex() });
  let txHash: string;

  try {
    txHash = await apiRequest<string>(apiUrl, '/broadcast', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form,
    }, 1);
  } catch (error) {
    // A lost response does not mean a lost broadcast: the payout may
    // already be in the mempool. Never rebroadcast or rebuild — confirm by
    // txid so an accepted payout is reported as success instead of leaving
    // a request marked failed after the coins have moved.
    txHash = '';

    for (let attempt = 0; attempt < 4 && !txHash; attempt += 1) {
      await sleep(10_000);

      try {
        await apiRequest<{ hex?: string }>(apiUrl, `/transaction/${txid}`, undefined, 1);
        txHash = txid;
      } catch {
        // Not visible yet — keep polling.
      }
    }

    if (!txHash) {
      throw error;
    }
  }

  console.log(JSON.stringify({
    txHash,
    requestId: requestId ?? null,
    amount: amount.toString(),
    fee: selection.fee.toString(),
    inputCount: selection.inputs.length,
    spentAddresses: [...new Set(selection.inputs.map((input) => input.address))],
  }));
};

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((error: unknown) => {
    const message = error instanceof Error ? error.message : String(error);
    console.error(message);
    process.exitCode = 1;
  });
}
