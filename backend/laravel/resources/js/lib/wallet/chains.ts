import {
    Connection,
    Keypair,
    PublicKey,
    SystemProgram,
    Transaction,
} from '@solana/web3.js';
import { HDNodeWallet, JsonRpcProvider, isAddress, keccak256 } from 'ethers';
import { CYBERIA_CHAIN_ID, cyberiaReadRpcUrl } from '@/lib/evmChains';
import { isValidMoneroAddress, moneroStandardAddress } from '@/lib/monero';
import {
    numberToBytesLE,
    scReduce32,
    scalarMultBase,
} from '@/lib/wallet/ed25519';
import { deriveEd25519Key } from '@/lib/wallet/slip10';

/**
 * Chain adapters of the unified HD wallet.
 *
 * One BIP-39 seed feeds every chain here; each adapter owns the part that is
 * genuinely chain-specific — derivation path, curve, address format, and what
 * the chain can actually do from a browser. Adding a chain means adding one
 * entry to WALLET_CHAINS, nothing else: the composable and the page render
 * whatever the registry declares.
 *
 * Secrets never escape an adapter. `derive()` hands back an address and
 * `send()` builds its signer from the seed and drops it; no function here
 * returns a private key to its caller, and none of them log.
 */

export type WalletChainId = 'cyberia' | 'solana' | 'monero';

export type WalletCapabilities = {
    /** The browser can read this chain's balance without extra infrastructure. */
    balance: boolean;
    /** A public explorer can show this address's history. */
    history: boolean;
    /** The wallet can build, sign and broadcast a payment. */
    send: boolean;
};

export type WalletChain = {
    id: WalletChainId;
    label: string;
    symbol: string;
    decimals: number;
    /** BIP-44/SLIP-0010 path, shown in the UI so the wallet is restorable. */
    path: string;
    /** Curve the path is walked on — secp256k1 (BIP-32) or ed25519 (SLIP-0010). */
    curve: 'secp256k1' | 'ed25519';
    capabilities: WalletCapabilities;
    /** Why a capability is missing, when one is. */
    note?: string;
    derive: (seed: Uint8Array) => string;
    isValidAddress: (address: string) => boolean;
    explorerAddressUrl: (address: string) => string | null;
    explorerTxUrl: (hash: string) => string | null;
    /** Smallest-unit balance, or null when the chain cannot be read here. */
    fetchBalance?: (address: string, rpcUrl?: string) => Promise<bigint>;
    /** Broadcasts a payment and resolves to the transaction hash. */
    send?: (
        seed: Uint8Array,
        request: { to: string; amount: bigint; rpcUrl?: string },
    ) => Promise<string>;
};

/** BIP-44 account 0, external chain, first address — MetaMask's default. */
export const EVM_PATH = "m/44'/60'/0'/0/0";

/** SLIP-0010 ed25519, the path Phantom and Solflare use. */
export const SOLANA_PATH = "m/44'/501'/0'/0'";

/**
 * SLIP-0010 ed25519 account 0. Monero's own coin type is 128; the account key
 * becomes the spend secret, matching how hardware wallets derive Monero from a
 * BIP-39 seed. Monero itself has no BIP-44 — this is the interoperable choice.
 */
export const MONERO_PATH = "m/44'/128'/0'";

const CYBERIA_EXPLORER = 'https://explorer.cyberia.church';

const SOLANA_EXPLORER = 'https://solscan.io';

const evmWallet = (seed: Uint8Array): HDNodeWallet =>
    HDNodeWallet.fromSeed(seed).derivePath(EVM_PATH);

const solanaKeypair = (seed: Uint8Array): Keypair =>
    Keypair.fromSeed(deriveEd25519Key(seed, SOLANA_PATH));

/**
 * Monero keys: the derived node key reduced into the scalar field is the
 * secret spend key, and the view key is the reduced Keccak-256 of it — the
 * relation every Monero wallet enforces, so the view key is recoverable from
 * the spend key alone.
 */
const moneroAddress = (seed: Uint8Array): string => {
    const spendSecret = scReduce32(deriveEd25519Key(seed, MONERO_PATH));
    const spendBytes = numberToBytesLE(spendSecret);
    const viewSecret = scReduce32(hexToBytes(keccak256(spendBytes)));

    return moneroStandardAddress(
        scalarMultBase(spendSecret),
        scalarMultBase(viewSecret),
    );
};

const hexToBytes = (hex: string): Uint8Array => {
    const clean = hex.startsWith('0x') ? hex.slice(2) : hex;
    const bytes = new Uint8Array(clean.length / 2);

    for (let i = 0; i < bytes.length; i++) {
        bytes[i] = parseInt(clean.slice(i * 2, i * 2 + 2), 16);
    }

    return bytes;
};

const cyberiaProvider = (rpcUrl?: string): JsonRpcProvider =>
    new JsonRpcProvider(rpcUrl || cyberiaReadRpcUrl(), {
        chainId: CYBERIA_CHAIN_ID,
        name: 'cyberia',
    });

export const WALLET_CHAINS: readonly WalletChain[] = [
    {
        id: 'cyberia',
        label: 'Cyberia',
        symbol: 'CYBER',
        decimals: 18,
        path: EVM_PATH,
        curve: 'secp256k1',
        capabilities: { balance: true, history: true, send: true },
        note: 'The same address works on every EVM chain the bridge supports.',
        derive: (seed) => evmWallet(seed).address,
        isValidAddress: (address) => isAddress(address),
        explorerAddressUrl: (address) =>
            `${CYBERIA_EXPLORER}/address/${address}`,
        explorerTxUrl: (hash) => `${CYBERIA_EXPLORER}/tx/${hash}`,
        fetchBalance: async (address, rpcUrl) =>
            cyberiaProvider(rpcUrl).getBalance(address),
        send: async (seed, { to, amount, rpcUrl }) => {
            const signer = evmWallet(seed).connect(cyberiaProvider(rpcUrl));
            const tx = await signer.sendTransaction({ to, value: amount });

            return tx.hash;
        },
    },
    {
        id: 'solana',
        label: 'Solana',
        symbol: 'SOL',
        decimals: 9,
        path: SOLANA_PATH,
        curve: 'ed25519',
        capabilities: { balance: true, history: true, send: true },
        derive: (seed) => solanaKeypair(seed).publicKey.toBase58(),
        isValidAddress: (address) => {
            try {
                return new PublicKey(address).toBytes().length === 32;
            } catch {
                return false;
            }
        },
        explorerAddressUrl: (address) =>
            `${SOLANA_EXPLORER}/account/${address}`,
        explorerTxUrl: (hash) => `${SOLANA_EXPLORER}/tx/${hash}`,
        fetchBalance: async (address, rpcUrl) => {
            const connection = new Connection(
                rpcUrl || 'https://api.mainnet-beta.solana.com',
                'confirmed',
            );

            return BigInt(await connection.getBalance(new PublicKey(address)));
        },
        send: async (seed, { to, amount, rpcUrl }) => {
            const keypair = solanaKeypair(seed);
            const connection = new Connection(
                rpcUrl || 'https://api.mainnet-beta.solana.com',
                'confirmed',
            );
            const transaction = new Transaction().add(
                SystemProgram.transfer({
                    fromPubkey: keypair.publicKey,
                    toPubkey: new PublicKey(to),
                    lamports: Number(amount),
                }),
            );

            return connection.sendTransaction(transaction, [keypair]);
        },
    },
    {
        id: 'monero',
        label: 'Monero',
        symbol: 'XMR',
        decimals: 12,
        path: MONERO_PATH,
        curve: 'ed25519',
        // Monero balances are not public: finding your own outputs means
        // scanning every block with the view key, which needs a node the app
        // does not run. The address is derived here, spending happens in a
        // Monero wallet restored from this same seed phrase.
        capabilities: { balance: false, history: false, send: false },
        note: 'Receive-only here: Monero balances and payments require a view-key scan against a Monero node, which the browser cannot do.',
        derive: moneroAddress,
        isValidAddress: isValidMoneroAddress,
        explorerAddressUrl: () => null,
        explorerTxUrl: (hash) => `https://xmrchain.net/tx/${hash}`,
    },
];

export const walletChain = (id: WalletChainId): WalletChain => {
    const chain = WALLET_CHAINS.find((candidate) => candidate.id === id);

    if (!chain) {
        throw new Error(`Unknown wallet chain "${id}"`);
    }

    return chain;
};

/** Smallest-unit amount for a decimal string typed by a human. */
export const parseUnits = (value: string, decimals: number): bigint => {
    const [whole, fraction = ''] = value.trim().split('.');

    if (!/^\d*$/.test(whole) || !/^\d*$/.test(fraction)) {
        throw new Error('Amount must be a decimal number');
    }

    if (fraction.length > decimals) {
        throw new Error(`At most ${decimals} decimals`);
    }

    return BigInt(`${whole || '0'}${fraction.padEnd(decimals, '0')}`);
};

/** Human amount for a smallest-unit balance, trimmed to `precision` digits. */
export const formatUnits = (
    value: bigint,
    decimals: number,
    precision = 6,
): string => {
    const base = 10n ** BigInt(decimals);
    const whole = value / base;
    const fraction = (value % base).toString().padStart(decimals, '0');
    const trimmed = fraction.slice(0, precision).replace(/0+$/, '');

    return trimmed ? `${whole}.${trimmed}` : whole.toString();
};
