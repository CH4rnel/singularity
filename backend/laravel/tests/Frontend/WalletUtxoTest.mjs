import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { HDNodeWallet, Mnemonic, SigningKey, getBytes } from 'ethers';
import { deriveAccounts, walletChain } from '@/lib/wallet';
import { decodeBase58Check, encodeBase58Check } from '@/lib/wallet/base58check';
import { decodeSegwitAddress, encodeSegwitAddress } from '@/lib/wallet/bech32';
import {
    customNetworkId,
    customNetworkTag,
    customWalletChain,
    validateCustomNetwork,
} from '@/lib/wallet/customChains';
import {
    DUST_THRESHOLD,
    derSignature,
    p2wpkhSighash,
    p2wpkhVsize,
    selectCoins,
    toHex,
    utxoAddress,
    utxoOutputScript,
} from '@/lib/wallet/utxo';

/**
 * The Bitcoin family is the one part of this wallet that builds and signs a
 * transaction byte by byte instead of handing it to a node. Nothing here may be
 * checked only against itself: every step is pinned to the BIP that defines it,
 * so a refactor that changes an address or a signature fails here rather than
 * on-chain, where a Bitcoin transfer cannot be recalled.
 */

/** The BIP-39 test phrase every wallet ships in its own suite. */
const PHRASE =
    'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

const hash160 = (bytes) =>
    createHash('ripemd160')
        .update(createHash('sha256').update(bytes).digest())
        .digest();

const hex = (bytes) => Buffer.from(bytes).toString('hex');

const seed = () => getBytes(Mnemonic.fromPhrase(PHRASE).computeSeed());

const network = (overrides = {}) => ({
    coinType: 0,
    hrp: 'bc',
    p2pkhVersion: 0x00,
    p2shVersion: 0x05,
    addressType: 'bech32',
    api: null,
    explorer: null,
    ...overrides,
});

/* ---------------------------------------------------------------- bech32 -- */

test('bech32 accepts the BIP-173 vectors and rejects a flipped character', () => {
    // Both cases of the same address are the same address; a mixed-case one is
    // no address at all, which is what stops two strings meaning one output.
    const upper = decodeSegwitAddress(
        'bc',
        'BC1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4',
    );
    const lower = decodeSegwitAddress(
        'bc',
        'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
    );

    assert.equal(upper.version, 0);
    assert.equal(
        hex(upper.program),
        '751e76e8199196d454941c45d1b3a323f1433bd6',
    );
    assert.deepEqual(hex(lower.program), hex(upper.program));

    assert.equal(
        decodeSegwitAddress('bc', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5'),
        null,
        'a one-character change has to break the checksum',
    );
    assert.equal(
        decodeSegwitAddress('bc', 'Bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'),
        null,
        'mixed case is ambiguous and therefore invalid',
    );
});

test('a witness version decides which checksum the address must carry', () => {
    // Version 0 is bech32, everything above it is bech32m. Accepting the wrong
    // pairing accepts an address no node will ever pay out.
    assert.notEqual(
        decodeSegwitAddress(
            'bc',
            'bc1pw508d6qejxtdg4y5r3zarvary0c5xw7kw508d6qejxtdg4y5r3zarvary0c5xw7kt5nd6y',
        ),
        null,
    );

    const program = getBytes('0x751e76e8199196d454941c45d1b3a323f1433bd6');

    assert.equal(
        encodeSegwitAddress('bc', 0, program),
        'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
    );
    assert.equal(
        decodeSegwitAddress('ltc', encodeSegwitAddress('bc', 0, program)),
        null,
        'an address is only valid on the chain whose prefix it carries',
    );
});

test('base58check round-trips and refuses a corrupted address', () => {
    const address = '1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabA';
    const decoded = decodeBase58Check(address);

    assert.equal(decoded.version, 0x00);
    assert.equal(decoded.payload.length, 20);
    assert.equal(encodeBase58Check(0x00, decoded.payload), address);
    assert.equal(decodeBase58Check('1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabB'), null);
});

/* ------------------------------------------------------------ derivation -- */

test('Bitcoin addresses match the BIP-84 and BIP-44 published vectors', () => {
    const accounts = deriveAccounts(PHRASE);
    const bitcoin = accounts.find((account) => account.chain === 'bitcoin');

    assert.equal(bitcoin.path, "m/84'/0'/0'/0/0");
    assert.equal(
        bitcoin.address,
        'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu',
        'BIP-84 account 0, first receiving address',
    );

    // The same seed on the legacy purpose is the BIP-44 vector, which pins the
    // base58 path independently of the segwit one.
    const legacy = utxoAddress(
        network({ addressType: 'legacy' }),
        HDNodeWallet.fromSeed(seed()).derivePath("m/44'/0'/0'/0/0"),
    );

    assert.equal(legacy, '1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabA');
});

test('Litecoin is its own coin type, and its address encodes its own key', () => {
    const accounts = deriveAccounts(PHRASE);
    const litecoin = accounts.find((account) => account.chain === 'litecoin');
    const bitcoin = accounts.find((account) => account.chain === 'bitcoin');

    assert.equal(litecoin.path, "m/84'/2'/0'/0/0");
    assert.ok(litecoin.address.startsWith('ltc1q'));
    assert.notEqual(
        litecoin.address,
        bitcoin.address,
        'a different coin type is a different account, not a re-encoding',
    );

    // Independent check of the encoding: the program in the address has to be
    // the RIPEMD-160 of the SHA-256 of the public key at that same path.
    const node = HDNodeWallet.fromSeed(seed()).derivePath("m/84'/2'/0'/0/0");
    const decoded = decodeSegwitAddress('ltc', litecoin.address);

    assert.equal(decoded.version, 0);
    assert.equal(
        hex(decoded.program),
        hex(hash160(Buffer.from(getBytes(node.publicKey)))),
    );
});

test('an address is only valid on the chain it belongs to', () => {
    const bitcoin = walletChain('bitcoin');
    const litecoin = walletChain('litecoin');

    assert.ok(
        bitcoin.isValidAddress('bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu'),
    );
    assert.ok(bitcoin.isValidAddress('1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabA'));
    assert.ok(
        !bitcoin.isValidAddress('ltc1qcr8te4kr609gcawutmrza0j4xv80jy8zsxvpk3'),
    );
    assert.ok(
        !litecoin.isValidAddress('bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu'),
    );
    assert.ok(
        !bitcoin.isValidAddress('0x9c4A7fD2E51b0aB83c6De19F4a7B2c85D0e3F714'),
        'an EVM address is not a Bitcoin address, however familiar it looks',
    );
});

test('every address form maps to the script that actually pays it', () => {
    const btc = network();

    assert.equal(
        toHex(
            utxoOutputScript(btc, 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu'),
        ),
        '0014c0cebcd6c3d3ca8c75dc5ec62ebe55330ef910e2',
    );
    assert.equal(
        toHex(utxoOutputScript(btc, '1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabA')),
        '76a914d986ed01b7a22225a70edbf2ba7cfb63a15cb3aa88ac',
    );
    assert.equal(utxoOutputScript(btc, 'not-an-address'), null);
});

/* --------------------------------------------------------------- signing -- */

/**
 * BIP-143's own native-P2WPKH example. The BIP prints the outpoints in their
 * serialised form, so the display txids below are those bytes reversed — which
 * is exactly the conversion the wallet has to get right when it reads a txid
 * back out of an explorer.
 */
const BIP143 = {
    inputs: [
        {
            txid: '9f96ade4b41d5433f4eda31e1738ec2b36f6e7d1420d94a6af99801a88f7f7ff',
            vout: 0,
            value: 625000000n,
        },
        {
            txid: '8ac60eb9575db5b2d987e29f301b5b819ea83a5c6579d282d189cc04b8e151ef',
            vout: 1,
            value: 600000000n,
        },
    ],
    outputs: [
        {
            script: [
                ...getBytes(
                    '0x76a9148280b37df378db99f66f85c95a783a76ac7a6d5988ac',
                ),
            ],
            value: 112340000n,
        },
        {
            script: [
                ...getBytes(
                    '0x76a9143bde42dbee7e4dbe6a21b2d50ce2f0167faa815988ac',
                ),
            ],
            value: 223450000n,
        },
    ],
    sequences: [0xffffffee, 0xffffffff],
    version: 1,
    locktime: 0x11,
    publicKey:
        '0x025476c2e83188368da1ff3e292e7acafcdb3566bb0ad253f62fc70f07aeee6357',
    privateKey:
        '0x619c335025c7f4012e556c2a58b2506e30b8511b53ade95ea316fd8c3286feb9',
    digest: 'c37af31116d1b27caf68aae9e3ac82f1477929014d5b917657d0eb49478cb670',
    signature:
        '304402203609e17b84f6a7d30c80bfa610b5b4542f32a8a0d5447a12fb1366d7f01cc44a0220573a954c4518331561406f90300e8f3358f51928d43c212a8caed02de67eebee01',
};

test('the signing digest is the one BIP-143 publishes', () => {
    const keyHash = hash160(Buffer.from(getBytes(BIP143.publicKey)));

    assert.equal(
        toHex(p2wpkhSighash(new Uint8Array(keyHash), BIP143, 1)),
        BIP143.digest,
    );
});

test('the DER signature over that digest is the one BIP-143 publishes', () => {
    const signature = derSignature(new SigningKey(BIP143.privateKey), [
        ...getBytes(`0x${BIP143.digest}`),
    ]);

    assert.equal(toHex(signature), BIP143.signature);
});

/* -------------------------------------------------------- coin selection -- */

const utxo = (value, confirmed = true) => ({
    txid: '9f96ade4b41d5433f4eda31e1738ec2b36f6e7d1420d94a6af99801a88f7f7ff',
    vout: 0,
    value: BigInt(value),
    confirmed,
});

test('coin selection pays the fee it charges, at the rate it was given', () => {
    const selection = selectCoins([utxo(1_000_000)], 100_000n, 10);

    assert.equal(selection.inputs.length, 1);
    assert.equal(selection.fee, BigInt(p2wpkhVsize(1, 2)) * 10n);
    assert.equal(
        selection.inputs[0].value - 100_000n - selection.fee,
        selection.change,
        'every satoshi is either spent, paid as fee, or returned as change',
    );
});

test('confirmed coins are spent before coins still in the mempool', () => {
    const selection = selectCoins(
        [utxo(50_000, false), utxo(40_000, true)],
        20_000n,
        1,
    );

    assert.equal(selection.inputs[0].confirmed, true);
});

test('change too small to spend is paid to the miner, never written as dust', () => {
    // One coin, a rate that leaves a few hundred satoshis over: writing that as
    // an output produces a transaction relays refuse and coins nobody can move.
    const fee = BigInt(p2wpkhVsize(1, 2)) * 5n;
    const amount = 100_000n;
    const selection = selectCoins(
        [utxo(amount + fee + (DUST_THRESHOLD - 1n))],
        amount,
        5,
    );

    assert.equal(selection.change, 0n);
    assert.ok(
        selection.fee >= BigInt(p2wpkhVsize(1, 1)) * 5n,
        'the folded-in change still covers a one-output transaction',
    );
});

test('a balance that cannot cover the amount and its fee is refused outright', () => {
    assert.throws(() => selectCoins([utxo(100_000)], 100_000n, 20));
});

/* -------------------------------------------------------- custom networks -- */

test('a network is refused until it can actually produce an address', () => {
    const base = {
        kind: 'utxo',
        id: 'utxo-btg-156',
        name: 'Bitcoin Gold',
        symbol: 'BTG',
        coinType: 156,
        addressType: 'bech32',
        hrp: 'btg',
        p2pkhVersion: 0x26,
        p2shVersion: 0x17,
        api: 'https://explorer.example/api',
        explorer: null,
    };

    assert.equal(validateCustomNetwork(base, []), null);
    assert.equal(validateCustomNetwork({ ...base, hrp: '' }, []), 'prefix');
    assert.equal(validateCustomNetwork({ ...base, name: 'B' }, []), 'name');
    assert.equal(validateCustomNetwork({ ...base, symbol: '' }, []), 'symbol');
    assert.equal(
        validateCustomNetwork({ ...base, explorer: 'javascript:alert(1)' }, []),
        'explorer',
    );
    assert.equal(
        validateCustomNetwork({ ...base, api: 'electrum.example:50002' }, []),
        'api',
        'a browser cannot reach an Electrum server, so the endpoint must be HTTPS',
    );
    assert.equal(
        validateCustomNetwork(base, [{ id: 'utxo-btg-156' }]),
        'duplicate',
    );
});

test('an EVM chain already in the wallet cannot be added a second time', () => {
    const draft = {
        kind: 'evm',
        id: customNetworkId('evm', 'POL', 137),
        name: 'Polygon',
        symbol: 'POL',
        chainId: 137,
        rpcUrl: 'https://polygon-rpc.com',
        explorer: null,
    };

    assert.equal(validateCustomNetwork(draft, []), null);
    assert.equal(
        validateCustomNetwork(
            { ...draft, rpcUrl: 'http://polygon-rpc.com' },
            [],
        ),
        'rpc',
        'plain HTTP is blocked as mixed content and would never load',
    );
    assert.equal(
        validateCustomNetwork(draft, [{ id: 'other', chainId: 137 }]),
        'duplicate',
        'the same chain id twice would be two cards holding one balance',
    );
});

test('an explorer URL is refused unless it is safe to make into a link', () => {
    const draft = {
        kind: 'evm',
        id: customNetworkId('evm', 'POL', 137),
        name: 'Polygon',
        symbol: 'POL',
        chainId: 137,
        rpcUrl: 'https://polygon-rpc.com',
        explorer: 'javascript:alert(1)',
    };

    assert.equal(
        validateCustomNetwork(draft, []),
        'explorer',
        'the explorer becomes an href, so a script URL must never reach it',
    );
    assert.equal(
        validateCustomNetwork(
            { ...draft, explorer: 'https://polygonscan.com' },
            [],
        ),
        null,
    );
});

test('a user-added tile stays distinguishable from the coin it forks', () => {
    // "Bitcoin Gold" taking the first two of its ticker would read BT, the same
    // tile Bitcoin already owns two rows above it in the portfolio.
    assert.equal(customNetworkTag('Bitcoin Gold', 'BTG'), 'BG');
    assert.notEqual(
        customNetworkTag('Bitcoin Gold', 'BTG'),
        walletChain('bitcoin').mark.tag,
    );
    assert.equal(customNetworkTag('Dogecoin', 'DOGE'), 'DO');
    assert.equal(customNetworkTag('', ''), 'NA');
});

test('a user-added network is marked unverified wherever it is drawn', () => {
    const chain = customWalletChain({
        kind: 'evm',
        id: 'evm-pol-137',
        name: 'Polygon',
        symbol: 'POL',
        chainId: 137,
        rpcUrl: 'https://polygon-rpc.com',
        explorer: null,
    });

    assert.equal(chain.custom, true);
    assert.equal(chain.mark.unverified, true);
    assert.equal(chain.mark.hue, 'var(--cw-net-custom)');
    assert.equal(
        chain.path,
        "m/44'/60'/0'/0/0",
        'a user-added EVM chain is the same key, not a new one',
    );

    for (const builtin of ['cyberia', 'bitcoin', 'solana', 'monero']) {
        assert.notEqual(walletChain(builtin).mark.unverified, true);
    }
});
