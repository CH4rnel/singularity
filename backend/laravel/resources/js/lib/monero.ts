import { keccak256 } from 'ethers';

/**
 * Native Monero address decoding, mirroring App\Services\Monero\MoneroAddressCodec.
 *
 * Monero is its own chain with its own address format — never an EVM address
 * and never base58 in the Bitcoin/Solana sense. An address is
 * `network byte + spend key + view key (+ 8-byte payment id) + Keccak-256
 * checksum`, encoded in 8-byte blocks that each become a fixed-width base58
 * group. Because the checksum is part of the address, a mistyped character is
 * detectable in the browser instead of on-chain, which matters: an XMR payout
 * cannot be recalled and cannot be traced to ask for it back.
 *
 * Only decoding lives here — the client never has to build an address.
 */

const B58_ALPHABET =
    '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

/** Decoded size of a base58 group, indexed by that group's character count. */
const BLOCK_BYTES: Record<number, number> = {
    2: 1,
    3: 2,
    5: 3,
    6: 4,
    7: 5,
    9: 6,
    10: 7,
    11: 8,
};

const FULL_ENCODED_BLOCK_SIZE = 11;

export type MoneroAddressKind = 'standard' | 'integrated' | 'subaddress';

/** Mainnet network bytes and the payload length each one must decode to. */
const KINDS: Record<number, { kind: MoneroAddressKind; length: number }> = {
    0x12: { kind: 'standard', length: 65 },
    0x13: { kind: 'integrated', length: 73 },
    0x2a: { kind: 'subaddress', length: 65 },
};

const decodeBlock = (block: string): Uint8Array | null => {
    const size = BLOCK_BYTES[block.length];

    if (!size) {
        return null;
    }

    const bytes = new Uint8Array(size);

    for (const char of block) {
        let carry = B58_ALPHABET.indexOf(char);

        if (carry < 0) {
            return null;
        }

        for (let i = size - 1; i >= 0; i--) {
            const value = bytes[i] * 58 + carry;
            bytes[i] = value & 0xff;
            carry = value >> 8;
        }

        // The block held a number too large for its byte width.
        if (carry !== 0) {
            return null;
        }
    }

    return bytes;
};

const decode = (address: string): Uint8Array | null => {
    const bytes: number[] = [];

    for (let i = 0; i < address.length; i += FULL_ENCODED_BLOCK_SIZE) {
        const block = decodeBlock(
            address.slice(i, i + FULL_ENCODED_BLOCK_SIZE),
        );

        if (block === null) {
            return null;
        }

        bytes.push(...block);
    }

    return Uint8Array.from(bytes);
};

/**
 * The kind of mainnet Monero address this is, or null when the string is not
 * one (bad charset, wrong length, failed checksum, or a testnet/stagenet
 * network byte — the bridge is mainnet-only).
 */
export const moneroAddressKind = (
    address: string,
): MoneroAddressKind | null => {
    const raw = decode(address.trim());

    if (raw === null || raw.length < 5) {
        return null;
    }

    const payload = raw.subarray(0, raw.length - 4);
    const checksum = raw.subarray(raw.length - 4);
    const expected = keccak256(payload).slice(2, 10);

    if (
        expected !==
        Array.from(checksum)
            .map((byte) => byte.toString(16).padStart(2, '0'))
            .join('')
    ) {
        return null;
    }

    const kind = KINDS[payload[0]];

    return kind && payload.length === kind.length ? kind.kind : null;
};

export const isValidMoneroAddress = (address: string): boolean =>
    moneroAddressKind(address) !== null;

/** Human label for the address kind, for UI badges. */
export const moneroKindLabel = (kind: MoneroAddressKind): string =>
    ({
        standard: 'standard',
        integrated: 'integrated (payment id)',
        subaddress: 'subaddress',
    })[kind];
