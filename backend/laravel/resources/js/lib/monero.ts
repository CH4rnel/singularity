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
 * Decoding covers pasted addresses (bridge payouts, profile). Encoding exists
 * for the one address the client builds itself: the Monero account of the
 * unified HD wallet, whose keys are derived in the browser and never leave it.
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

/** Encoded length of a partial trailing block, indexed by its byte count. */
const ENCODED_BLOCK_SIZES = [0, 2, 3, 5, 6, 7, 9, 10, 11];

const FULL_BLOCK_SIZE = 8;

const encodeBlock = (block: Uint8Array): string => {
    const bytes = Array.from(block);
    let encoded = '';

    while (bytes.some((byte) => byte > 0)) {
        let remainder = 0;

        for (let i = 0; i < bytes.length; i++) {
            const value = remainder * 256 + bytes[i];

            bytes[i] = Math.floor(value / 58);
            remainder = value % 58;
        }

        encoded = B58_ALPHABET[remainder] + encoded;
    }

    return encoded.padStart(ENCODED_BLOCK_SIZES[block.length], '1');
};

/**
 * Monero base58: fixed-width groups of 8-byte blocks, not the Bitcoin/Solana
 * stream encoding. Mirrors MoneroAddressCodec::encode().
 */
export const encodeMoneroBase58 = (bytes: Uint8Array): string => {
    let encoded = '';

    for (let i = 0; i < bytes.length; i += FULL_BLOCK_SIZE) {
        encoded += encodeBlock(bytes.subarray(i, i + FULL_BLOCK_SIZE));
    }

    return encoded;
};

/**
 * Standard mainnet address for a spend/view public key pair: network byte,
 * the two keys, and the four leading bytes of the payload's Keccak-256.
 */
export const moneroStandardAddress = (
    spendPublicKey: Uint8Array,
    viewPublicKey: Uint8Array,
): string => {
    const payload = new Uint8Array(65);

    payload[0] = 0x12;
    payload.set(spendPublicKey, 1);
    payload.set(viewPublicKey, 33);

    const checksum = keccak256(payload).slice(2, 10);
    const address = new Uint8Array(69);

    address.set(payload);

    for (let i = 0; i < 4; i++) {
        address[65 + i] = parseInt(checksum.slice(i * 2, i * 2 + 2), 16);
    }

    return encodeMoneroBase58(address);
};

/** Human label for the address kind, for UI badges. */
export const moneroKindLabel = (kind: MoneroAddressKind): string =>
    ({
        standard: 'standard',
        integrated: 'integrated (payment id)',
        subaddress: 'subaddress',
    })[kind];
