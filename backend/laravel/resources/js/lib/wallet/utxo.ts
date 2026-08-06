import { getBytes, ripemd160, sha256 } from 'ethers';
import type { HDNodeWallet, SigningKey } from 'ethers';
import { decodeBase58Check, encodeBase58Check } from '@/lib/wallet/base58check';
import { decodeSegwitAddress, encodeSegwitAddress } from '@/lib/wallet/bech32';

/**
 * The Bitcoin family as a wallet chain: Bitcoin, Litecoin and the forks a user
 * adds themselves.
 *
 * A UTXO chain has no account balance to read and no `eth_sendTransaction` to
 * delegate to. Everything is done here — coins are selected, a transaction is
 * built byte by byte, each input is signed under BIP-143, and the raw hex is
 * pushed to an Esplora API. That is the price of not asking anyone to hold the
 * key, and it is why this file is pinned to the BIP-84 and BIP-143 test vectors
 * in `tests/Frontend/WalletUtxoTest.mjs`.
 *
 * Only P2WPKH (`bc1…`) is signed. Legacy and P2SH accounts can be derived and
 * received into — the address is real and restorable from the same phrase — but
 * spending them needs a second sighash algorithm, and a wallet that half-signs
 * a Bitcoin transaction is worse than one that says it cannot.
 */

export type UtxoAddressType = 'bech32' | 'p2sh' | 'legacy';

export type UtxoNetwork = {
    /** SLIP-44 coin type — the `m/purpose'/coin'/0'/0/0` slot. */
    coinType: number;
    /** bech32 human-readable part, or null on a chain without segwit. */
    hrp: string | null;
    /** base58check version byte of a legacy `1…`-style address. */
    p2pkhVersion: number;
    /** base58check version byte of a P2SH `3…`-style address. */
    p2shVersion: number;
    /** Which script the wallet's own address uses. */
    addressType: UtxoAddressType;
    /**
     * Esplora-compatible HTTPS API root, e.g. `https://mempool.space/api`.
     *
     * It has to be HTTPS and CORS-open: a browser cannot speak the Electrum
     * protocol, so an `electrum.host:50002` endpoint is not reachable from here
     * however valid it is for a desktop wallet.
     */
    api: string | null;
    /** Human-facing explorer root, for the "view in explorer" links. */
    explorer: string | null;
};

/** BIP-84, BIP-49 and BIP-44 in that order — the purpose decides the script. */
const PURPOSE: Record<UtxoAddressType, number> = {
    bech32: 84,
    p2sh: 49,
    legacy: 44,
};

export const utxoPath = (network: UtxoNetwork): string =>
    `m/${PURPOSE[network.addressType]}'/${network.coinType}'/0'/0/0`;

/* --------------------------------------------------------------- bytes --- */

const hex = (bytes: Uint8Array): string =>
    Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

const fromHex = (value: string): Uint8Array =>
    getBytes(value.startsWith('0x') ? value : `0x${value}`);

const hash160 = (bytes: Uint8Array): Uint8Array =>
    getBytes(ripemd160(sha256(`0x${hex(bytes)}`)));

/** Bitcoin hashes everything twice; the second pass is not decorative. */
const doubleSha = (bytes: readonly number[]): number[] => [
    ...getBytes(sha256(sha256(`0x${hex(Uint8Array.from(bytes))}`))),
];

const u32le = (value: number): number[] => [
    value & 0xff,
    (value >>> 8) & 0xff,
    (value >>> 16) & 0xff,
    (value >>> 24) & 0xff,
];

const u64le = (value: bigint): number[] => {
    const out: number[] = [];
    let rest = value;

    for (let i = 0; i < 8; i++) {
        out.push(Number(rest & 0xffn));
        rest >>= 8n;
    }

    return out;
};

const varint = (value: number): number[] => {
    if (value < 0xfd) {
        return [value];
    }

    if (value <= 0xffff) {
        return [0xfd, value & 0xff, (value >>> 8) & 0xff];
    }

    return [0xfe, ...u32le(value)];
};

/** Transaction ids are written big-endian and serialised little-endian. */
const outpointHash = (txid: string): number[] => [...fromHex(txid)].reverse();

/* ------------------------------------------------------------ addresses -- */

const publicKeyBytes = (node: HDNodeWallet): Uint8Array =>
    fromHex(node.publicKey);

/** The wallet's own address for this network, from the derived public key. */
export const utxoAddress = (
    network: UtxoNetwork,
    node: HDNodeWallet,
): string => {
    const keyHash = hash160(publicKeyBytes(node));

    if (network.addressType === 'bech32') {
        if (network.hrp === null) {
            throw new Error('This network has no bech32 prefix');
        }

        return encodeSegwitAddress(network.hrp, 0, keyHash);
    }

    if (network.addressType === 'legacy') {
        return encodeBase58Check(network.p2pkhVersion, keyHash);
    }

    // A P2SH-wrapped segwit account pays to the hash of its own witness
    // program, not to the key hash — the redeem script is `0x0014 || keyhash`.
    return encodeBase58Check(
        network.p2shVersion,
        hash160(Uint8Array.from([0x00, 0x14, ...keyHash])),
    );
};

/**
 * The output script an address pays to, or null when the string is not an
 * address on this network. Every form the chain accepts is spendable by
 * whoever receives it, so all three are valid destinations even though the
 * wallet only ever signs one of them.
 */
export const utxoOutputScript = (
    network: UtxoNetwork,
    address: string,
): number[] | null => {
    const trimmed = address.trim();

    if (network.hrp !== null) {
        const segwit = decodeSegwitAddress(network.hrp, trimmed);

        if (segwit !== null) {
            return [
                segwit.version === 0 ? 0x00 : 0x50 + segwit.version,
                segwit.program.length,
                ...segwit.program,
            ];
        }
    }

    const base58 = decodeBase58Check(trimmed);

    if (base58 === null || base58.payload.length !== 20) {
        return null;
    }

    if (base58.version === network.p2pkhVersion) {
        return [0x76, 0xa9, 0x14, ...base58.payload, 0x88, 0xac];
    }

    if (base58.version === network.p2shVersion) {
        return [0xa9, 0x14, ...base58.payload, 0x87];
    }

    return null;
};

export const isValidUtxoAddress = (
    network: UtxoNetwork,
    address: string,
): boolean => utxoOutputScript(network, address) !== null;

/* -------------------------------------------------------------- signing -- */

/** SIGHASH_ALL: this signature commits to every input and every output. */
const SIGHASH_ALL = 1;

/** Opt in to replace-by-fee, so an underpriced transfer can still be bumped. */
const SEQUENCE = 0xfffffffd;

/** Half the curve order — signatures above it are re-encoded, never relayed. */
const HALF_ORDER =
    0x7fffffffffffffffffffffffffffffff5d576e7357a4501ddfe92f46681b20a0n;

const CURVE_ORDER =
    0xfffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141n;

const derInteger = (value: bigint): number[] => {
    const bytes: number[] = [];
    let rest = value;

    while (rest > 0n) {
        bytes.unshift(Number(rest & 0xffn));
        rest >>= 8n;
    }

    if (bytes.length === 0) {
        bytes.push(0);
    }

    // DER integers are signed, so a leading byte with the high bit set would
    // read as negative and has to be pushed behind a zero.
    if ((bytes[0] & 0x80) !== 0) {
        bytes.unshift(0);
    }

    return [0x02, bytes.length, ...bytes];
};

/** A DER signature with the sighash byte appended, as a witness item. */
export const derSignature = (
    key: SigningKey,
    digest: readonly number[],
): number[] => {
    const signature = key.sign(`0x${hex(Uint8Array.from(digest))}`);
    const r = BigInt(signature.r);
    // Every relay policy since BIP-62 rejects the high-S twin of a valid
    // signature. ethers already signs low-S; normalising again costs nothing
    // and removes the possibility of a silently unrelayable transaction.
    const rawS = BigInt(signature.s);
    const s = rawS > HALF_ORDER ? CURVE_ORDER - rawS : rawS;
    const body = [...derInteger(r), ...derInteger(s)];

    return [0x30, body.length, ...body, SIGHASH_ALL];
};

export type UtxoInput = {
    txid: string;
    vout: number;
    /** Satoshis held by the output being spent — BIP-143 signs over it. */
    value: bigint;
};

export type UtxoOutput = { script: number[]; value: bigint };

export type UtxoDraft = {
    inputs: readonly UtxoInput[];
    outputs: readonly UtxoOutput[];
    /** nSequence per input — one entry each, in input order. */
    sequences: readonly number[];
    version: number;
    locktime: number;
};

/**
 * BIP-143 digest for one input of a P2WPKH spend.
 *
 * Unlike the pre-segwit algorithm this commits to the *value* of every input,
 * which is what lets a signer that never saw the funding transactions still
 * know what it is authorising — and what makes the digest reproducible against
 * the BIP's own published vector.
 */
export const p2wpkhSighash = (
    keyHash: Uint8Array,
    draft: UtxoDraft,
    index: number,
): number[] => {
    const { inputs, outputs, sequences, version, locktime } = draft;

    return doubleSha([
        ...u32le(version),
        ...doubleSha(
            inputs.flatMap((input) => [
                ...outpointHash(input.txid),
                ...u32le(input.vout),
            ]),
        ),
        ...doubleSha(sequences.flatMap((sequence) => u32le(sequence))),
        ...outpointHash(inputs[index].txid),
        ...u32le(inputs[index].vout),
        // scriptCode of a P2WPKH input is the P2PKH script it stands in for.
        0x19,
        0x76,
        0xa9,
        0x14,
        ...keyHash,
        0x88,
        0xac,
        ...u64le(inputs[index].value),
        ...u32le(sequences[index]),
        ...doubleSha(
            outputs.flatMap((output) => [
                ...u64le(output.value),
                ...varint(output.script.length),
                ...output.script,
            ]),
        ),
        ...u32le(locktime),
        ...u32le(SIGHASH_ALL),
    ]);
};

export const toHex = (bytes: readonly number[]): string =>
    hex(Uint8Array.from(bytes));

/** A signed P2WPKH transaction, as raw hex ready to broadcast. */
export const buildP2wpkhTransaction = (
    key: SigningKey,
    publicKey: Uint8Array,
    inputs: readonly UtxoInput[],
    outputs: readonly UtxoOutput[],
    version = 2,
    locktime = 0,
): string => {
    const draft: UtxoDraft = {
        inputs,
        outputs,
        sequences: inputs.map(() => SEQUENCE),
        version,
        locktime,
    };
    const keyHash = hash160(publicKey);

    const witnesses = inputs.map((_, index) => [
        derSignature(key, p2wpkhSighash(keyHash, draft, index)),
        [...publicKey],
    ]);

    return hex(
        Uint8Array.from([
            ...u32le(version),
            // Marker and flag: what tells a node this transaction has witnesses.
            0x00,
            0x01,
            ...varint(inputs.length),
            ...inputs.flatMap((input) => [
                ...outpointHash(input.txid),
                ...u32le(input.vout),
                // An empty scriptSig — the signature lives in the witness.
                0x00,
                ...u32le(SEQUENCE),
            ]),
            ...varint(outputs.length),
            ...outputs.flatMap((output) => [
                ...u64le(output.value),
                ...varint(output.script.length),
                ...output.script,
            ]),
            ...witnesses.flatMap((items) => [
                ...varint(items.length),
                ...items.flatMap((item) => [...varint(item.length), ...item]),
            ]),
            ...u32le(locktime),
        ]),
    );
};

/* ----------------------------------------------------------- fee sizing -- */

/**
 * Virtual size of a P2WPKH spend, in vbytes.
 *
 * 10.5 for the envelope (the segwit marker weighs 2 units, i.e. half a vbyte),
 * ~68 per input and 31 per output. It is an estimate, but a tight one: the only
 * variable left is whether a DER signature lands on 71 or 72 bytes.
 */
export const p2wpkhVsize = (inputs: number, outputs: number): number =>
    Math.ceil(10.5 + 68 * inputs + 31 * outputs);

/**
 * Below this an output costs more to spend than it is worth, and relays refuse
 * to forward it. Change under the threshold is given to the miner instead.
 */
export const DUST_THRESHOLD = 546n;

/* --------------------------------------------------------------- esplora - */

export type EsploraUtxo = {
    txid: string;
    vout: number;
    value: bigint;
    confirmed: boolean;
};

const esploraJson = async <T>(api: string, path: string): Promise<T> => {
    const response = await fetch(`${api}${path}`);

    if (!response.ok) {
        throw new Error(`Node returned ${response.status}`);
    }

    return (await response.json()) as T;
};

type EsploraAddressStats = {
    funded_txo_sum: number;
    spent_txo_sum: number;
};

/**
 * Confirmed balance plus whatever the mempool has already moved. A wallet that
 * ignored the mempool would show an incoming payment as missing for ten
 * minutes, which reads as a lost transfer.
 */
export const esploraBalance = async (
    api: string,
    address: string,
): Promise<bigint> => {
    const stats = await esploraJson<{
        chain_stats: EsploraAddressStats;
        mempool_stats: EsploraAddressStats;
    }>(api, `/address/${address}`);

    const net = (entry: EsploraAddressStats): bigint =>
        BigInt(entry.funded_txo_sum) - BigInt(entry.spent_txo_sum);

    return net(stats.chain_stats) + net(stats.mempool_stats);
};

export const esploraUtxos = async (
    api: string,
    address: string,
): Promise<EsploraUtxo[]> => {
    const utxos = await esploraJson<
        {
            txid: string;
            vout: number;
            value: number;
            status: { confirmed: boolean };
        }[]
    >(api, `/address/${address}/utxo`);

    return utxos.map((utxo) => ({
        txid: utxo.txid,
        vout: utxo.vout,
        value: BigInt(utxo.value),
        confirmed: utxo.status.confirmed,
    }));
};

type EsploraTx = {
    txid: string;
    fee: number;
    status: { confirmed: boolean; block_height?: number; block_time?: number };
    vin: { prevout: { scriptpubkey_address?: string; value: number } | null }[];
    vout: { scriptpubkey_address?: string; value: number }[];
};

/**
 * Recent transfers, as the net satoshi change of our own address.
 *
 * A UTXO transaction has no "from" and no "to" — it consumes outputs and
 * creates new ones, several of which are usually our own change. Reading the
 * net delta is the only way to state what actually happened to the balance.
 */
export const esploraHistory = async (
    api: string,
    address: string,
): Promise<
    {
        hash: string;
        direction: 'in' | 'out';
        amount: bigint;
        timestamp: number | null;
        status: 'confirmed' | 'pending';
        counterparty: string | null;
        meta: string | null;
    }[]
> => {
    const transactions = await esploraJson<EsploraTx[]>(
        api,
        `/address/${address}/txs`,
    );

    return transactions
        .map((tx) => {
            const spent = tx.vin.reduce(
                (sum, input) =>
                    input.prevout?.scriptpubkey_address === address
                        ? sum + BigInt(input.prevout.value)
                        : sum,
                0n,
            );
            const received = tx.vout.reduce(
                (sum, output) =>
                    output.scriptpubkey_address === address
                        ? sum + BigInt(output.value)
                        : sum,
                0n,
            );
            const delta = received - spent;
            const outgoing = delta < 0n;
            const counterparty = outgoing
                ? (tx.vout.find(
                      (output) => output.scriptpubkey_address !== address,
                  )?.scriptpubkey_address ?? null)
                : (tx.vin.find(
                      (input) =>
                          input.prevout?.scriptpubkey_address !== address,
                  )?.prevout?.scriptpubkey_address ?? null);

            return {
                hash: tx.txid,
                direction: (outgoing ? 'out' : 'in') as 'in' | 'out',
                amount: delta,
                timestamp: tx.status.block_time ?? null,
                status: (tx.status.confirmed ? 'confirmed' : 'pending') as
                    | 'confirmed'
                    | 'pending',
                counterparty,
                meta: tx.status.confirmed
                    ? `block ${tx.status.block_height}`
                    : 'in mempool',
            };
        })
        .filter((tx) => tx.amount !== 0n);
};

/** Relay floor: nothing under one satoshi per vbyte is forwarded at all. */
const MIN_FEE_RATE = 1;

/** Confirmation targets, in blocks, behind slow / normal / fast. */
const FEE_TARGETS = { slow: '144', normal: '6', fast: '2' } as const;

export type UtxoFeeRates = { slow: number; normal: number; fast: number };

/** Satoshis per vbyte at each tier, as the node's own estimator reports them. */
export const esploraFeeRates = async (api: string): Promise<UtxoFeeRates> => {
    const estimates = await esploraJson<Record<string, number>>(
        api,
        '/fee-estimates',
    );

    const rate = (target: string): number =>
        Math.max(MIN_FEE_RATE, Math.ceil(estimates[target] ?? MIN_FEE_RATE));

    return {
        slow: rate(FEE_TARGETS.slow),
        normal: rate(FEE_TARGETS.normal),
        fast: rate(FEE_TARGETS.fast),
    };
};

export const esploraBroadcast = async (
    api: string,
    raw: string,
): Promise<string> => {
    const response = await fetch(`${api}/tx`, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain' },
        body: raw,
    });
    const body = (await response.text()).trim();

    if (!response.ok) {
        throw new Error(
            body || `Node rejected the transaction (${response.status})`,
        );
    }

    return body;
};

export const esploraConfirmed = async (
    api: string,
    hash: string,
): Promise<boolean> => {
    const tx = await esploraJson<{ status: { confirmed: boolean } }>(
        api,
        `/tx/${hash}`,
    );

    return tx.status.confirmed;
};

/* ------------------------------------------------------- coin selection -- */

export type CoinSelection = {
    inputs: EsploraUtxo[];
    /** What the transaction actually pays in fees, at the chosen rate. */
    fee: bigint;
    /** Change back to our own address, already dust-checked. */
    change: bigint;
};

/**
 * Pick inputs largest-first until the amount and its own fee are covered.
 *
 * Largest-first keeps the input count — and so the fee — down, at the cost of
 * leaving small outputs behind. For a wallet whose alternative is failing to
 * build a transaction at all, that is the right trade.
 */
export const selectCoins = (
    utxos: readonly EsploraUtxo[],
    amount: bigint,
    feeRate: number,
): CoinSelection => {
    // Confirmed coins first: spending an unconfirmed output chains this
    // transfer's fate to one this wallet no longer controls.
    const candidates = [...utxos].sort((a, b) =>
        a.confirmed !== b.confirmed
            ? Number(b.confirmed) - Number(a.confirmed)
            : b.value < a.value
              ? -1
              : b.value > a.value
                ? 1
                : 0,
    );

    const inputs: EsploraUtxo[] = [];
    let gathered = 0n;

    for (const utxo of candidates) {
        inputs.push(utxo);
        gathered += utxo.value;

        const withChange =
            BigInt(p2wpkhVsize(inputs.length, 2)) * BigInt(feeRate);

        if (gathered >= amount + withChange) {
            const change = gathered - amount - withChange;

            // Change too small to ever be spent is handed to the miner rather
            // than written as an output no relay would forward. The result is
            // still above the one-output fee, since it was sized for two.
            if (change < DUST_THRESHOLD) {
                return { inputs, fee: gathered - amount, change: 0n };
            }

            return { inputs, fee: withChange, change };
        }
    }

    throw new Error('Not enough coins to cover the amount and its fee');
};
