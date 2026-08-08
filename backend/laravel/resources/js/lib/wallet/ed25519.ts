/**
 * Minimal ed25519 group arithmetic — just enough to turn a secret scalar into
 * the public point a Monero address is built from.
 *
 * EVM keys come from ethers and Solana keys from @solana/web3.js, but Monero
 * needs something neither exposes: multiplication of the base point by a *raw*
 * reduced scalar. Monero's spend and view secrets are `sc_reduce32` outputs,
 * not RFC 8032 clamped hashes, so `Keypair.fromSeed()` cannot produce them.
 *
 * This is a public-key primitive only: no signing, no variable-time secret
 * comparisons in a place where they would matter, and nothing here ever logs.
 * Correctness is pinned in tests/Frontend/WalletDerivationTest.mjs against the
 * RFC 8032 vector and, independently, against @solana/web3.js.
 */

const P = (1n << 255n) - 19n;

/** Group order l, the modulus Monero scalars are reduced by. */
export const L = (1n << 252n) + 27742317777372353535851937790883648493n;

/** Curve constant d = -121665/121666 (mod p). */
const D =
    37095705934669439343138083508754565189542113879843219016388785533085940283555n;

/** sqrt(-1) mod p = 2^((p-1)/4), the ambiguity decompression has to resolve. */
const SQRT_M1 =
    19681161376707505956807079304988542015446066515923890162744021073123829784752n;

const mod = (value: bigint): bigint => ((value % P) + P) % P;

/** Extended homogeneous coordinates (x, y, z, t) with x*y = z*t. */
type Point = { x: bigint; y: bigint; z: bigint; t: bigint };

const BASE_X =
    15112221349535400772501151409588531511454012693041857206046113283949847762202n;
const BASE_Y =
    46316835694926478169428394003475163141307993866256225615783033603165251855960n;

const NEUTRAL: Point = { x: 0n, y: 1n, z: 1n, t: 0n };
const BASE: Point = {
    x: BASE_X,
    y: BASE_Y,
    z: 1n,
    t: mod(BASE_X * BASE_Y),
};

/**
 * Twisted Edwards addition (add-2008-hwcd-3, a = -1). The formula is unified:
 * it also doubles, so one routine covers the whole ladder.
 */
const add = (p: Point, q: Point): Point => {
    const a = mod((p.y - p.x) * (q.y - q.x));
    const b = mod((p.y + p.x) * (q.y + q.x));
    const c = mod(p.t * 2n * D * q.t);
    const d = mod(p.z * 2n * q.z);
    const e = b - a;
    const f = d - c;
    const g = d + c;
    const h = b + a;

    return { x: mod(e * f), y: mod(g * h), t: mod(e * h), z: mod(f * g) };
};

const power = (base: bigint, exponent: bigint): bigint => {
    let result = 1n;
    let acc = mod(base);
    let left = exponent;

    while (left > 0n) {
        if (left & 1n) {
            result = mod(result * acc);
        }

        acc = mod(acc * acc);
        left >>= 1n;
    }

    return result;
};

/** Compressed point encoding: 32-byte little-endian y, x's sign in bit 255. */
const encodePoint = (point: Point): Uint8Array => {
    const inverseZ = power(point.z, P - 2n);
    const x = mod(point.x * inverseZ);
    const y = mod(point.y * inverseZ);
    const encoded = numberToBytesLE(y);

    encoded[31] |= Number(x & 1n) << 7;

    return encoded;
};

export const bytesToNumberLE = (bytes: Uint8Array): bigint => {
    let value = 0n;

    for (let i = bytes.length - 1; i >= 0; i--) {
        value = (value << 8n) | BigInt(bytes[i]);
    }

    return value;
};

export const numberToBytesLE = (value: bigint, length = 32): Uint8Array => {
    const bytes = new Uint8Array(length);
    let left = value;

    for (let i = 0; i < length; i++) {
        bytes[i] = Number(left & 0xffn);
        left >>= 8n;
    }

    return bytes;
};

/** Public point for a secret scalar, compressed to 32 bytes. */
export const scalarMultBase = (scalar: bigint): Uint8Array =>
    encodePoint(multiply(scalar, BASE));

const multiply = (scalar: bigint, point: Point): Point => {
    let result = NEUTRAL;
    let addend = point;
    let left = scalar;

    while (left > 0n) {
        if (left & 1n) {
            result = add(result, addend);
        }

        addend = add(addend, addend);
        left >>= 1n;
    }

    return result;
};

/**
 * Monero's `sc_reduce32`: read 32 bytes as a little-endian integer and reduce
 * it into the scalar field. Every Monero secret key is the output of this.
 */
export const scReduce32 = (bytes: Uint8Array): bigint =>
    bytesToNumberLE(bytes) % L;

/**
 * Recover the point behind a 32-byte compressed encoding, or null when those
 * bytes are not on the curve.
 *
 * Only the y coordinate and x's sign bit are transmitted; x comes back out of
 * the curve equation x² = (y² − 1)/(dy² + 1). The candidate root can be off by
 * a factor of sqrt(-1), which is what the second branch fixes — and a value
 * that satisfies neither branch was never a point, so it is rejected rather
 * than turned into a plausible-looking address.
 */
const decodePoint = (bytes: Uint8Array): Point | null => {
    if (bytes.length !== 32) {
        return null;
    }

    const sign = bytes[31] >> 7;
    const y = mod(bytesToNumberLE(bytes) & ((1n << 255n) - 1n));
    const u = mod(y * y - 1n);
    const v = mod(D * y * y + 1n);

    let x = mod(u * power(v, 3n) * power(mod(u * power(v, 7n)), (P - 5n) / 8n));

    if (mod(v * x * x) === mod(-u)) {
        x = mod(x * SQRT_M1);
    }

    if (mod(v * x * x) !== u) {
        return null;
    }

    if (x === 0n && sign === 1) {
        return null;
    }

    if (Number(x & 1n) !== sign) {
        x = mod(P - x);
    }

    return { x, y, z: 1n, t: mod(x * y) };
};

/**
 * Sum of two compressed points, compressed again — Monero's `D = B + mG`.
 * Returns null when either input is not a valid encoding.
 */
export const addPoints = (
    left: Uint8Array,
    right: Uint8Array,
): Uint8Array | null => {
    const a = decodePoint(left);
    const b = decodePoint(right);

    return a === null || b === null ? null : encodePoint(add(a, b));
};

/**
 * A compressed point multiplied by a secret scalar — Monero's `C = aD`.
 * Returns null when the point is not a valid encoding.
 */
export const scalarMultPoint = (
    scalar: bigint,
    point: Uint8Array,
): Uint8Array | null => {
    const decoded = decodePoint(point);

    return decoded === null ? null : encodePoint(multiply(scalar, decoded));
};
