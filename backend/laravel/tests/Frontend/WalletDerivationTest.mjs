import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import test from 'node:test';
import { Keypair } from '@solana/web3.js';
import { Mnemonic, getBytes, keccak256 } from 'ethers';
import { moneroAddressKind, moneroStandardAddress } from '@/lib/monero';
import {
    WALLET_CHAINS,
    createMnemonic,
    deriveAccounts,
    deriveAddress,
    formatUnits,
    parseUnits,
} from '@/lib/wallet';
import {
    bytesToNumberLE,
    numberToBytesLE,
    scReduce32,
    scalarMultBase,
} from '@/lib/wallet/ed25519';
import { deriveEd25519Key, masterNode } from '@/lib/wallet/slip10';

/**
 * The unified wallet's derivation is the one thing that must never drift: a
 * wrong path or a wrong curve sends funds to an address nobody can restore.
 * Each chain is therefore pinned to a published vector or to an independent
 * implementation, not to whatever this code happens to produce today.
 */

// The BIP-39 test phrase every wallet ships in its own test suite.
const PHRASE =
    'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

const hex = (bytes) => Buffer.from(bytes).toString('hex');

test('ed25519 matches the RFC 8032 test vector', () => {
    const secret = Buffer.from(
        '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60',
        'hex',
    );
    const digest = createHash('sha512').update(secret).digest();

    digest[0] &= 248;
    digest[31] &= 127;
    digest[31] |= 64;

    assert.equal(
        hex(scalarMultBase(bytesToNumberLE(digest.subarray(0, 32)))),
        'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a',
    );
});

test('ed25519 agrees with @solana/web3.js on random keys', () => {
    for (let i = 0; i < 5; i++) {
        const seed = randomBytes(32);
        const digest = createHash('sha512').update(seed).digest();

        digest[0] &= 248;
        digest[31] &= 127;
        digest[31] |= 64;

        assert.equal(
            hex(scalarMultBase(bytesToNumberLE(digest.subarray(0, 32)))),
            hex(Keypair.fromSeed(seed).publicKey.toBytes()),
        );
    }
});

test('SLIP-0010 master node matches the published ed25519 vector', () => {
    const node = masterNode(
        Buffer.from('000102030405060708090a0b0c0d0e0f', 'hex'),
    );

    assert.equal(
        hex(node.key),
        '2b4be7f19ee27bbf30c667b642d5f4aa69fd169872f8fc3059c08ebae2eb19e7',
    );
    assert.equal(
        hex(node.chainCode),
        '90046a93de5380a72b5e45010748567d5ea02bbf6522f979e05c0d8d8ca9fffb',
    );
});

test('ed25519 derivation refuses a non-hardened path', () => {
    assert.throws(
        () => deriveEd25519Key(Buffer.alloc(64), "m/44'/501'/0'/0"),
        /hardened-only/,
    );
});

test('EVM account matches the BIP-44 vector for the test phrase', () => {
    assert.equal(
        deriveAddress(PHRASE, 'cyberia'),
        '0x9858EfFD232B4033E47d90003D41EC34EcaEda94',
    );
});

const bip39Seed = () => getBytes(Mnemonic.fromPhrase(PHRASE).computeSeed());

test('Solana account is the SLIP-0010 key seen through @solana/web3.js', () => {
    const expected = Keypair.fromSeed(
        deriveEd25519Key(bip39Seed(), "m/44'/501'/0'/0'"),
    ).publicKey.toBase58();

    assert.equal(deriveAddress(PHRASE, 'solana'), expected);
});

test('Monero account is a checksummed standard mainnet address', () => {
    const address = deriveAddress(PHRASE, 'monero');

    assert.equal(moneroAddressKind(address), 'standard');
    assert.equal(address.length, 95);
    assert.equal(address[0], '4');
    // Deterministic, and bound to the phrase it came from.
    assert.equal(deriveAddress(PHRASE, 'monero'), address);
    assert.notEqual(deriveAddress(createMnemonic(), 'monero'), address);
});

test('Monero view key is the reduced Keccak of the spend key', () => {
    const spend = scReduce32(deriveEd25519Key(bip39Seed(), "m/44'/128'/0'"));
    const view = scReduce32(getBytes(keccak256(numberToBytesLE(spend))));

    assert.equal(
        moneroStandardAddress(scalarMultBase(spend), scalarMultBase(view)),
        deriveAddress(PHRASE, 'monero'),
    );
});

test('every chain derives from the one phrase and exposes no secrets', () => {
    const accounts = deriveAccounts(PHRASE);

    assert.deepEqual(
        accounts.map((account) => account.chain),
        WALLET_CHAINS.map((chain) => chain.id),
    );

    for (const account of accounts) {
        assert.ok(account.address.length > 0);
        assert.deepEqual(
            Object.keys(account).filter((key) =>
                /key|secret|seed|phrase|mnemonic/i.test(key),
            ),
            [],
        );
    }
});

test('each account validates its own family and rejects the others', () => {
    const accounts = deriveAccounts(PHRASE);

    for (const account of accounts) {
        const chain = WALLET_CHAINS.find((c) => c.id === account.chain);

        assert.equal(chain.isValidAddress(account.address), true);

        // A chain accepts every address of its own family — all EVM networks
        // really do share one address — and no address of any other.
        for (const other of accounts.filter(
            (candidate) => candidate.family !== account.family,
        )) {
            assert.equal(chain.isValidAddress(other.address), false);
        }
    }
});

test('every EVM network is the same address, and only that address', () => {
    const accounts = deriveAccounts(PHRASE);
    const evm = accounts.filter((account) => account.family === 'evm');
    const [first, ...rest] = evm;

    // The point of BIP-44 coin type 60: one key, one string, many networks.
    assert.ok(evm.length >= 4, 'expected several EVM networks');
    assert.equal(first.path, "m/44'/60'/0'/0/0");

    for (const account of rest) {
        assert.equal(account.address, first.address);
        assert.equal(account.path, first.path);
    }

    // Each of them still points at its own chain and explorer.
    const chainIds = evm.map(
        (account) => WALLET_CHAINS.find((c) => c.id === account.chain).chainId,
    );

    assert.equal(new Set(chainIds).size, evm.length);
    assert.equal(
        new Set(evm.map((account) => account.explorerUrl)).size,
        evm.length,
    );
});

test('amounts round-trip through the smallest unit', () => {
    assert.equal(parseUnits('1.5', 18), 1_500_000_000_000_000_000n);
    assert.equal(parseUnits('0.000000001', 9), 1n);
    assert.equal(formatUnits(1_500_000_000_000_000_000n, 18), '1.5');
    assert.equal(formatUnits(0n, 12), '0');
    assert.throws(() => parseUnits('0.0000000001', 9), /At most 9 decimals/);
    assert.throws(() => parseUnits('1e18', 18), /decimal number/);
});
