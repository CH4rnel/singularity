/**
 * bech32 and bech32m, the address encodings of every segwit output.
 *
 * Written out here rather than pulled in as a dependency because the wallet's
 * whole claim is that key material never leaves the browser: the fewer packages
 * that sit between a seed and an address, the fewer supply-chain hands are on
 * the one string a user is asked to trust. It is ~80 lines of BIP-173/BIP-350
 * and it is pinned to the specs' own test vectors in `tests/Frontend/`.
 *
 * The checksum is the point. A bech32 address that lost a character does not
 * decode, so a mistyped recipient is caught here instead of on-chain, where a
 * Bitcoin transfer cannot be recalled.
 */

const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

/** Constant the checksum must equal — the only difference between the two. */
const CONSTANT = { bech32: 1, bech32m: 0x2bc830a3 } as const;

export type Bech32Variant = keyof typeof CONSTANT;

const polymod = (values: number[]): number => {
    let checksum = 1;

    for (const value of values) {
        const top = checksum >>> 25;
        checksum = ((checksum & 0x1ffffff) << 5) ^ value;

        for (let bit = 0; bit < 5; bit++) {
            if ((top >>> bit) & 1) {
                checksum ^= GENERATOR[bit];
            }
        }
    }

    return checksum;
};

const expandHrp = (hrp: string): number[] => {
    const high: number[] = [];
    const low: number[] = [];

    for (let i = 0; i < hrp.length; i++) {
        high.push(hrp.charCodeAt(i) >> 5);
        low.push(hrp.charCodeAt(i) & 31);
    }

    return [...high, 0, ...low];
};

/**
 * Regroup a byte stream into `to`-bit words. Segwit programs are 8-bit bytes
 * carried as 5-bit characters, and the padding rules differ by direction —
 * padding out is allowed, padding in must be zero and short.
 */
const convertBits = (
    data: readonly number[],
    from: number,
    to: number,
    pad: boolean,
): number[] | null => {
    let accumulator = 0;
    let bits = 0;
    const out: number[] = [];
    const max = (1 << to) - 1;

    for (const value of data) {
        if (value < 0 || value >> from !== 0) {
            return null;
        }

        accumulator = (accumulator << from) | value;
        bits += from;

        while (bits >= to) {
            bits -= to;
            out.push((accumulator >> bits) & max);
        }
    }

    if (pad) {
        if (bits > 0) {
            out.push((accumulator << (to - bits)) & max);
        }
    } else if (bits >= from || ((accumulator << (to - bits)) & max) !== 0) {
        return null;
    }

    return out;
};

const encodeRaw = (
    hrp: string,
    words: readonly number[],
    variant: Bech32Variant,
): string => {
    const values = [...expandHrp(hrp), ...words];
    const checksum = polymod([...values, 0, 0, 0, 0, 0, 0]) ^ CONSTANT[variant];
    const tail: number[] = [];

    for (let i = 0; i < 6; i++) {
        tail.push((checksum >> (5 * (5 - i))) & 31);
    }

    return `${hrp}1${[...words, ...tail]
        .map((word) => CHARSET[word])
        .join('')}`;
};

const decodeRaw = (
    address: string,
): { hrp: string; words: number[]; variant: Bech32Variant } | null => {
    // Mixed case is forbidden outright: it would let two different strings
    // carry the same address, which is exactly the ambiguity bech32 removes.
    if (
        address !== address.toLowerCase() &&
        address !== address.toUpperCase()
    ) {
        return null;
    }

    const lower = address.toLowerCase();
    const split = lower.lastIndexOf('1');

    if (split < 1 || split + 7 > lower.length || lower.length > 90) {
        return null;
    }

    const hrp = lower.slice(0, split);
    const words: number[] = [];

    for (const char of lower.slice(split + 1)) {
        const value = CHARSET.indexOf(char);

        if (value === -1) {
            return null;
        }

        words.push(value);
    }

    const checksum = polymod([...expandHrp(hrp), ...words]);
    const variant = (Object.keys(CONSTANT) as Bech32Variant[]).find(
        (candidate) => checksum === CONSTANT[candidate],
    );

    return variant ? { hrp, words: words.slice(0, -6), variant } : null;
};

/** A segwit output as an address, e.g. version 0 + 20 bytes → `bc1…`. */
export const encodeSegwitAddress = (
    hrp: string,
    version: number,
    program: Uint8Array,
): string => {
    const words = convertBits([...program], 8, 5, true);

    if (words === null) {
        throw new Error('Witness program is not encodable');
    }

    return encodeRaw(
        hrp,
        [version, ...words],
        version === 0 ? 'bech32' : 'bech32m',
    );
};

export type SegwitAddress = { version: number; program: Uint8Array };

/**
 * The witness version and program behind an address, or null when the string
 * is not a valid segwit address for `hrp`. Every rejection here is a transfer
 * that never leaves the wallet.
 */
export const decodeSegwitAddress = (
    hrp: string,
    address: string,
): SegwitAddress | null => {
    const decoded = decodeRaw(address);

    if (decoded === null || decoded.hrp !== hrp || decoded.words.length === 0) {
        return null;
    }

    const version = decoded.words[0];
    const program = convertBits(decoded.words.slice(1), 5, 8, false);

    if (
        version > 16 ||
        program === null ||
        program.length < 2 ||
        program.length > 40
    ) {
        return null;
    }

    // Version 0 is checksummed with bech32 and only defines the 20- and
    // 32-byte programs; everything above it is bech32m. Accepting the wrong
    // pairing would accept an address no node will ever pay out.
    if (version === 0) {
        if (
            decoded.variant !== 'bech32' ||
            (program.length !== 20 && program.length !== 32)
        ) {
            return null;
        }
    } else if (decoded.variant !== 'bech32m') {
        return null;
    }

    return { version, program: Uint8Array.from(program) };
};
