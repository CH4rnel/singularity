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

export const selectCoins = (
  available: YentenUtxo[],
  amount: bigint,
  satoshisPerKilobyte: bigint,
): { inputs: YentenUtxo[]; total: bigint; fee: bigint; change: bigint } => {
  const sorted = [...available].sort((left, right) => right.value - left.value);
  const inputs: YentenUtxo[] = [];
  let total = 0n;

  for (const utxo of sorted) {
    if (
      !Number.isSafeInteger(utxo.value) ||
      utxo.value <= 0 ||
      !Number.isInteger(utxo.height) ||
      utxo.height <= 0
    ) {
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

const apiRequest = async <T>(apiUrl: string, path: string, init?: RequestInit): Promise<T> => {
  const response = await fetch(`${apiUrl}${path}`, {
    ...init,
    signal: AbortSignal.timeout(20_000),
  });

  if (!response.ok) {
    throw new Error(`Yenten API returned HTTP ${response.status}`);
  }

  const body = (await response.json()) as ApiEnvelope<T>;

  if (body.error || body.result === undefined) {
    throw new Error(body.error?.message ?? 'Yenten API returned no result');
  }

  return body.result;
};

const main = async (): Promise<void> => {
  const [recipient, amountArgument, requestId] = process.argv.slice(2);
  const wif = process.env.YENTEN_RELAYER_WIF?.trim();
  const configuredAddress = process.env.YENTEN_RELAYER_ADDRESS?.trim();
  const apiUrl = (process.env.YENTEN_API_URL ?? 'https://api.yentencoin.info').replace(/\/$/, '');

  if (!recipient || !amountArgument || !/^\d+$/.test(amountArgument)) {
    throw new Error('Usage: relay-yenten-transfer.ts <recipient> <amount-satoshis> [request-id]');
  }

  if (!wif) {
    throw new Error('YENTEN_RELAYER_WIF is not configured');
  }

  bitcoin.address.toOutputScript(recipient, YENTEN_NETWORK);

  const keyPair = ECPair.fromWIF(wif, YENTEN_NETWORK);
  const payment = bitcoin.payments.p2pkh({ pubkey: Buffer.from(keyPair.publicKey), network: YENTEN_NETWORK });
  const relayerAddress = payment.address;

  if (!relayerAddress) {
    throw new Error('Could not derive Yenten relayer address from WIF');
  }

  if (configuredAddress && configuredAddress !== relayerAddress) {
    throw new Error('YENTEN_RELAYER_WIF does not match BRIDGE_YENTEN_DEPOSIT_ADDRESS');
  }

  const amount = BigInt(amountArgument);

  if (amount <= 0n) {
    throw new Error('Yenten payout amount must be positive');
  }

  if (amount > BigInt(Number.MAX_SAFE_INTEGER)) {
    throw new Error('Yenten payout amount exceeds the safe transaction limit');
  }

  const feeResult = await apiRequest<{ feerate?: number }>(apiUrl, '/fee');
  const feeRate = BigInt(Math.max(1000, Math.ceil(feeResult.feerate ?? 1000)));
  const unspent = await apiRequest<YentenUtxo[]>(
    apiUrl,
    `/unspent/${encodeURIComponent(relayerAddress)}?amount=0`,
  );

  if (unspent.length > 500) {
    throw new Error('Yenten relayer wallet has too many UTXOs; consolidate it before payout');
  }
  const selection = selectCoins(unspent, amount, feeRate);
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
    psbt.addOutput({ address: relayerAddress, value: Number(selection.change) });
  }

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
    requestId: requestId ?? null,
    amount: amount.toString(),
    fee: selection.fee.toString(),
    inputCount: selection.inputs.length,
  }));
};

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((error: unknown) => {
    const message = error instanceof Error ? error.message : String(error);
    console.error(message);
    process.exitCode = 1;
  });
}
