import { sha256 } from 'ethers';

/**
 * Bitcoin's base58check — the encoding behind every legacy (`1…`) and
 * P2SH (`3…`) address.
 *
 * Deliberately *not* the base58 in `lib/monero.ts`: Monero encodes fixed-width
 * 8-byte blocks and checksums with Keccak, Bitcoin encodes the whole payload as
 * one big number and checksums with a double SHA-256. Sharing an implementation
 * between them would be a bug waiting for a payout.
 */

const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

const bytesToHex = (bytes: Uint8Array): string =>
    `0x${Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')}`;

const hexToBytes = (hex: string): Uint8Array => {
    const clean = hex.startsWith('0x') ? hex.slice(2) : hex;
    const out = new Uint8Array(clean.length / 2);

    for (let i = 0; i < out.length; i++) {
        out[i] = parseInt(clean.slice(i * 2, i * 2 + 2), 16);
    }

    return out;
};

/** The four checksum bytes Bitcoin appends: the first of SHA-256(SHA-256(x)). */
const checksum = (payload: Uint8Array): Uint8Array =>
    hexToBytes(sha256(sha256(bytesToHex(payload)))).slice(0, 4);

const encodeBase58 = (bytes: Uint8Array): string => {
    let value = 0n;

    for (const byte of bytes) {
        value = (value << 8n) | BigInt(byte);
    }

    let out = '';

    while (value > 0n) {
        out = ALPHABET[Number(value % 58n)] + out;
        value /= 58n;
    }

    // Leading zero bytes are not part of the number, so they are carried over
    // as explicit '1' characters — this is what makes a version byte of 0x00
    // show up as the leading "1" of a Bitcoin address.
    for (const byte of bytes) {
        if (byte !== 0) {
            break;
        }

        out = `1${out}`;
    }

    return out;
};

const decodeBase58 = (text: string): Uint8Array | null => {
    let value = 0n;

    for (const char of text) {
        const digit = ALPHABET.indexOf(char);

        if (digit === -1) {
            return null;
        }

        value = value * 58n + BigInt(digit);
    }

    const body: number[] = [];

    while (value > 0n) {
        body.unshift(Number(value & 0xffn));
        value >>= 8n;
    }

    let zeros = 0;

    while (zeros < text.length && text[zeros] === '1') {
        zeros++;
    }

    return Uint8Array.from([...new Array<number>(zeros).fill(0), ...body]);
};

/** `version || payload || checksum`, the way an address is written down. */
export const encodeBase58Check = (
    version: number,
    payload: Uint8Array,
): string => {
    const body = Uint8Array.from([version, ...payload]);

    return encodeBase58(Uint8Array.from([...body, ...checksum(body)]));
};

export type Base58CheckPayload = { version: number; payload: Uint8Array };

/** The version byte and payload behind an address, or null if it is corrupt. */
export const decodeBase58Check = (text: string): Base58CheckPayload | null => {
    const raw = decodeBase58(text);

    if (raw === null || raw.length < 5) {
        return null;
    }

    const body = raw.slice(0, -4);
    const expected = checksum(body);

    for (let i = 0; i < 4; i++) {
        if (raw[raw.length - 4 + i] !== expected[i]) {
            return null;
        }
    }

    return { version: body[0], payload: body.slice(1) };
};
