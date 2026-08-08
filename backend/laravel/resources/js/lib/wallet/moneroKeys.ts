import { getBytes, keccak256 } from 'ethers';
import { moneroStandardAddress, moneroSubaddress } from '@/lib/monero';
import {
    addPoints,
    numberToBytesLE,
    scReduce32,
    scalarMultBase,
    scalarMultPoint,
} from '@/lib/wallet/ed25519';
import { deriveEd25519Key } from '@/lib/wallet/slip10';

/**
 * Monero keys for the unified wallet, and the one place its account numbering
 * differs from every other chain here.
 *
 * Monero has no BIP-44 account level to walk: a wallet is *one* key pair, and
 * what its own UI calls "account 3" is subaddress (3, 0) of that same pair —
 * a distinct address that the same view key still scans for. Deriving a fresh
 * spend key per account instead would produce addresses that a Monero wallet
 * restored from this phrase would never show and never see paid, so the
 * account index becomes the major subaddress index and nothing else changes.
 *
 * Secrets stay inside this module: every export returns an address.
 */

/**
 * SLIP-0010 ed25519 account 0. Monero's own coin type is 128; the account key
 * becomes the spend secret, matching how hardware wallets derive Monero from a
 * BIP-39 seed. Monero itself has no BIP-44 — this is the interoperable choice.
 */
export const MONERO_PATH = "m/44'/128'/0'";

/** The domain-separating prefix Monero hashes a subaddress index under. */
const SUBADDRESS_PREFIX = Uint8Array.from([
    ...new TextEncoder().encode('SubAddr'),
    0,
]);

type MoneroSecrets = { spend: bigint; view: bigint };

/**
 * The wallet's spend/view secret pair.
 *
 * The derived node key reduced into the scalar field is the secret spend key,
 * and the view key is the reduced Keccak-256 of it — the relation every Monero
 * wallet enforces, so the view key is recoverable from the spend key alone.
 */
const secrets = (seed: Uint8Array): MoneroSecrets => {
    const spend = scReduce32(deriveEd25519Key(seed, MONERO_PATH));
    const view = scReduce32(getBytes(keccak256(numberToBytesLE(spend))));

    return { spend, view };
};

const u32le = (value: number): Uint8Array =>
    Uint8Array.from([
        value & 0xff,
        (value >>> 8) & 0xff,
        (value >>> 16) & 0xff,
        (value >>> 24) & 0xff,
    ]);

/** Monero's `Hs`: a Keccak-256 digest reduced into the scalar field. */
const hashToScalar = (data: Uint8Array): bigint =>
    scReduce32(getBytes(keccak256(data)));

/**
 * The subaddress secret `m = Hs("SubAddr" || 0 || a || major || minor)`, which
 * offsets the public spend key into a new, unlinkable one.
 */
const subaddressScalar = (
    view: bigint,
    major: number,
    minor: number,
): bigint => {
    const data = new Uint8Array(SUBADDRESS_PREFIX.length + 40);

    data.set(SUBADDRESS_PREFIX, 0);
    data.set(numberToBytesLE(view), SUBADDRESS_PREFIX.length);
    data.set(u32le(major), SUBADDRESS_PREFIX.length + 32);
    data.set(u32le(minor), SUBADDRESS_PREFIX.length + 36);

    return hashToScalar(data);
};

/**
 * The address of one account of this wallet: the primary `4…` address at index
 * 0, and the `8…` subaddress (index, 0) above it — exactly what the Monero CLI
 * shows after `account switch <index>` on a wallet restored from this seed.
 */
export const moneroAccountAddress = (
    seed: Uint8Array,
    index: number,
): string => {
    const { spend, view } = secrets(seed);
    const spendPublic = scalarMultBase(spend);

    if (index === 0) {
        return moneroStandardAddress(spendPublic, scalarMultBase(view));
    }

    const offset = subaddressScalar(view, index, 0);
    const subSpend = addPoints(spendPublic, scalarMultBase(offset));
    const subView = subSpend === null ? null : scalarMultPoint(view, subSpend);

    if (subSpend === null || subView === null) {
        // Unreachable for keys this module derived — both points came out of
        // the group. Refusing beats handing back a receiving address that no
        // wallet on the other side can scan for.
        throw new Error('Could not derive that Monero subaddress');
    }

    return moneroSubaddress(subSpend, subView);
};
