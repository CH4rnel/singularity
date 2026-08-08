import { Keypair } from '@solana/web3.js';
import type { BaseWallet } from 'ethers';
import { HDNodeWallet, SigningKey, Wallet } from 'ethers';
import { decodeBase58 } from '@/lib/wallet/base58check';
import { deriveEd25519Key } from '@/lib/wallet/slip10';

/**
 * Where an account's key comes from.
 *
 * Until now there was one answer — the vault's seed — and every adapter took a
 * `Uint8Array` seed directly. Two things broke that: a wallet holds more than
 * one account off the same seed, and a key can arrive from outside it
 * altogether. Both are the *same* question to an adapter ("give me the signer
 * for this account"), so it is asked once, here, instead of forking every
 * `derive`, `send` and `signMessage` in the registry.
 *
 * A watch-only account never produces a source: it has no key, which is why it
 * never reaches an adapter's signing path at all.
 *
 * Nothing here is stored or logged. A source is built at the moment it is
 * needed and dropped with the call that needed it.
 */
export type WalletKeySource =
    | {
          kind: 'seed';
          seed: Uint8Array;
          /** BIP-44 account number — 0 is the wallet as it has always been. */
          index: number;
      }
    | {
          kind: 'key';
          /**
           * The key as its own chain writes it down: 0x-hex on EVM, base58 on
           * Solana, WIF on a Bitcoin fork. Kept in the chain's own encoding so
           * that what the user pasted is what gets checked.
           */
          secret: string;
      };

export const seedSource = (seed: Uint8Array, index = 0): WalletKeySource => ({
    kind: 'seed',
    seed,
    index,
});

export const keySource = (secret: string): WalletKeySource => ({
    kind: 'key',
    secret,
});

/* ------------------------------------------------------------------- evm --- */

/** BIP-44 account 0, external chain, first address — MetaMask's default. */
export const EVM_PATH = "m/44'/60'/0'/0/0";

/**
 * Account `index` on an EVM chain.
 *
 * The index moves the *address* segment, not the account one, because that is
 * where MetaMask, Rabby and Trust put it: a phrase restored there shows these
 * same addresses in this same order. Being interoperable is the whole reason
 * to follow a standard nobody enforces.
 */
export const evmPath = (index: number): string => `m/44'/60'/0'/0/${index}`;

const evmPrivateKey = (secret: string): string => {
    const clean = secret.trim();
    const prefixed = clean.startsWith('0x') ? clean : `0x${clean}`;

    if (!/^0x[0-9a-fA-F]{64}$/.test(prefixed)) {
        throw new Error('An EVM private key is 64 hex characters');
    }

    // SigningKey rejects a key outside the curve order, which 64 valid hex
    // characters can still be. Constructing it here makes that failure happen
    // before anything is stored rather than at the first attempt to spend.
    new SigningKey(prefixed);

    return prefixed;
};

/** The signer for an EVM account, from the seed or from an imported key. */
export const evmSigner = (source: WalletKeySource): BaseWallet =>
    source.kind === 'seed'
        ? HDNodeWallet.fromSeed(source.seed).derivePath(evmPath(source.index))
        : new Wallet(evmPrivateKey(source.secret));

/** The address an imported EVM key controls, or a throw saying why it is not one. */
export const evmAddressFromKey = (secret: string): string =>
    new Wallet(evmPrivateKey(secret)).address;

/* ---------------------------------------------------------------- solana --- */

/** SLIP-0010 ed25519, the path Phantom and Solflare use. */
export const SOLANA_PATH = "m/44'/501'/0'/0'";

/**
 * Account `index` on Solana. Here the index *is* the account segment — that is
 * where Phantom puts it, and its "Account 2" is this path at 1.
 */
export const solanaPath = (index: number): string => `m/44'/501'/${index}'/0'`;

/**
 * A Solana secret key as wallets export it: base58 over the 64-byte
 * expanded key, or over the 32-byte seed it expands from. The JSON byte array
 * `solana-keygen` writes is accepted too, since that is what a file on disk
 * actually contains.
 */
const solanaSecret = (secret: string): Uint8Array => {
    const trimmed = secret.trim();

    if (trimmed.startsWith('[')) {
        const parsed: unknown = JSON.parse(trimmed);

        if (
            !Array.isArray(parsed) ||
            parsed.some((b) => typeof b !== 'number')
        ) {
            throw new Error('That is not a Solana keypair file');
        }

        return Uint8Array.from(parsed as number[]);
    }

    const decoded = decodeBase58(trimmed);

    if (decoded === null) {
        throw new Error('A Solana secret key is base58');
    }

    return decoded;
};

export const solanaKeypair = (source: WalletKeySource): Keypair => {
    if (source.kind === 'seed') {
        return Keypair.fromSeed(
            deriveEd25519Key(source.seed, solanaPath(source.index)),
        );
    }

    const bytes = solanaSecret(source.secret);

    if (bytes.length === 64) {
        return Keypair.fromSecretKey(bytes);
    }

    if (bytes.length === 32) {
        return Keypair.fromSeed(bytes);
    }

    throw new Error('A Solana secret key is 32 or 64 bytes');
};

export const solanaAddressFromKey = (secret: string): string =>
    solanaKeypair(keySource(secret)).publicKey.toBase58();
