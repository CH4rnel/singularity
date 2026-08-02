import { computeHmac, getBytes } from 'ethers';

/**
 * SLIP-0010 hierarchical derivation over ed25519.
 *
 * BIP-32 is defined for secp256k1 only, so ed25519 chains (Solana, Monero)
 * use SLIP-0010: same seed, same path notation, HMAC-SHA512 all the way down,
 * but hardened-only — an ed25519 public key cannot be derived from a parent
 * public key. Passing a non-hardened segment is a programming error and
 * throws rather than silently deriving something no other wallet can restore.
 *
 * secp256k1 chains keep using ethers' BIP-32 (`HDNodeWallet`); this module is
 * the ed25519 half of the same tree, both fed by one BIP-39 seed.
 */

const ED25519_DOMAIN = new TextEncoder().encode('ed25519 seed');

const HARDENED_OFFSET = 0x80000000;

export type Slip10Node = {
    /** 32-byte secret key material. Never log, never persist, never render. */
    key: Uint8Array;
    chainCode: Uint8Array;
};

const hmacSha512 = (key: Uint8Array, data: Uint8Array): Uint8Array =>
    getBytes(computeHmac('sha512', key, data));

const splitNode = (digest: Uint8Array): Slip10Node => ({
    key: digest.slice(0, 32),
    chainCode: digest.slice(32, 64),
});

export const masterNode = (seed: Uint8Array): Slip10Node =>
    splitNode(hmacSha512(ED25519_DOMAIN, seed));

/**
 * SLIP-0010 CKDpriv for ed25519: HMAC-SHA512(chainCode, 0x00 || key || i),
 * with the index always hardened.
 */
export const deriveChild = (node: Slip10Node, index: number): Slip10Node => {
    const data = new Uint8Array(37);
    const hardened = (index + HARDENED_OFFSET) >>> 0;

    data.set(node.key, 1);
    data[33] = (hardened >>> 24) & 0xff;
    data[34] = (hardened >>> 16) & 0xff;
    data[35] = (hardened >>> 8) & 0xff;
    data[36] = hardened & 0xff;

    return splitNode(hmacSha512(node.chainCode, data));
};

/**
 * Parse a hardened-only path such as `m/44'/501'/0'/0'` into its indices.
 * Both `'` and `h` mark a hardened segment; anything else is rejected.
 */
export const parseHardenedPath = (path: string): number[] =>
    path
        .replace(/^m\//, '')
        .split('/')
        .filter((segment) => segment.length > 0)
        .map((segment) => {
            const match = segment.match(/^(\d+)['h]$/);

            if (!match) {
                throw new Error(
                    `ed25519 derivation is hardened-only, got "${segment}"`,
                );
            }

            return Number(match[1]);
        });

/** 32-byte private key at a hardened SLIP-0010 path below a BIP-39 seed. */
export const deriveEd25519Key = (
    seed: Uint8Array,
    path: string,
): Uint8Array => {
    let node = masterNode(seed);

    for (const index of parseHardenedPath(path)) {
        node = deriveChild(node, index);
    }

    return node.key;
};
