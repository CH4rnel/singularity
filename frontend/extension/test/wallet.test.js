/**
 * The rules that would be expensive to get wrong: which chain is which, what a
 * page may call without a human, what the relay does to the browser, and what
 * the manifest is allowed to ask for.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFile } from 'node:fs/promises';
import { CHAINS, DEFAULT_CHAIN_ID, chainById, chainIdHex, parseChainId } from '../src/shared/chains.js';
import { APPROVAL_METHODS, PASSTHROUGH_METHODS, rpcError } from '../src/shared/protocol.js';
import { permissionsFor, proxyConfigFor } from '../src/background/relay.js';
import { addressFor, isValidPhrase, normalisePhrase, pathFor } from '../src/background/keyring.js';

const manifest = JSON.parse(await readFile(new URL('../manifest.json', import.meta.url), 'utf8'));

/** The BIP-39 test vector every wallet ships in its own test suite. */
const VECTOR_PHRASE =
    'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

test('49406 is 0xC0FE, and the hex is always derived', () => {
    assert.equal(chainIdHex(49_406), '0xc0fe');
    assert.equal(DEFAULT_CHAIN_ID, 49_406);
    assert.equal(chainById('49406').name, 'Cyberia');
    assert.equal(chainById(1), null, 'a chain the wallet does not carry is not a chain');
});

test('a chain id from a page is read in either notation, or refused', () => {
    assert.equal(parseChainId('0xc0fe'), 49_406);
    assert.equal(parseChainId('49406'), 49_406);
    assert.equal(parseChainId(8453), 8453);
    assert.equal(parseChainId('mainnet'), null);
    assert.equal(parseChainId(''), null);
    assert.equal(parseChainId(-1), null);
});

test('every chain RPC is a host the manifest was allowed to reach', () => {
    for (const chain of CHAINS) {
        const host = new URL(chain.rpc).host;
        const allowed = manifest.host_permissions.some((pattern) => pattern.includes(host));

        assert.ok(allowed, `${chain.name} RPC ${host} is missing from host_permissions`);
    }
});

test('the provider is never injected everywhere', () => {
    assert.ok(!('content_scripts' in manifest), 'injection is registered per granted origin');
    assert.ok(
        !manifest.host_permissions.some((pattern) => pattern.includes('<all_urls>') || pattern === 'https://*/*'),
        'a wallet that can read every page can fingerprint every page',
    );
    assert.deepEqual(manifest.optional_permissions, ['proxy', 'privacy']);
});

test('no method both bypasses the human and reaches the signer', () => {
    for (const method of APPROVAL_METHODS) {
        assert.ok(!PASSTHROUGH_METHODS.has(method), `${method} must stop at a person`);
    }

    assert.ok(!PASSTHROUGH_METHODS.has('eth_sendTransaction'));
    assert.ok(!PASSTHROUGH_METHODS.has('eth_requestAccounts'));
    assert.equal(rpcError('userRejected').code, 4001);
    assert.equal(rpcError('unauthorized').code, 4100);
});

test('a relay is applied only when it points somewhere', () => {
    assert.equal(proxyConfigFor({ mode: 'direct' }), null);
    assert.equal(proxyConfigFor({ mode: 'socks5', host: '', port: '1080' }), null);
    assert.equal(proxyConfigFor({ mode: 'socks5', host: '127.0.0.1', port: '70000' }), null);

    assert.deepEqual(proxyConfigFor({ mode: 'socks5', host: '127.0.0.1', port: '10808' }).rules.singleProxy, {
        scheme: 'socks5',
        host: '127.0.0.1',
        port: 10_808,
    });

    // Tor and I2P ignore whatever is typed in the SOCKS fields: they are the
    // ports those daemons listen on, not a preference.
    assert.deepEqual(proxyConfigFor({ mode: 'tor', host: '10.0.0.1', port: '1' }).rules.singleProxy, {
        scheme: 'socks5',
        host: '127.0.0.1',
        port: 9050,
    });
    assert.equal(proxyConfigFor({ mode: 'i2p' }).rules.singleProxy.port, 4444);
});

test('localhost stays direct, so a relay never hides a node on this machine', () => {
    assert.deepEqual(proxyConfigFor({ mode: 'tor' }).rules.bypassList, ['<local>']);
});

test('a relay profile declares the permissions it needs', () => {
    assert.deepEqual(permissionsFor({ mode: 'tor' }), ['proxy']);
    assert.deepEqual(permissionsFor({ mode: 'direct', blockWebrtc: true }), ['privacy']);
    assert.deepEqual(permissionsFor({ mode: 'direct' }), []);
});

test('accounts derive exactly where the wallet on the site derives them', () => {
    // The same published BIP-39 vector, and the same expected address, as
    // `backend/laravel/tests/Frontend/WalletDerivationTest.mjs`. This is the
    // promise that importing your site phrase here finds the same funds; if it
    // ever fails, the extension is deriving someone else's accounts.
    assert.equal(pathFor(0), "m/44'/60'/0'/0/0");
    assert.equal(pathFor(3), "m/44'/60'/0'/0/3");
    assert.equal(addressFor(VECTOR_PHRASE, 0), '0x9858EfFD232B4033E47d90003D41EC34EcaEda94');
    assert.notEqual(addressFor(VECTOR_PHRASE, 1), addressFor(VECTOR_PHRASE, 0));
});

test('a phrase is checked, not merely counted', () => {
    assert.equal(isValidPhrase(VECTOR_PHRASE), true);
    assert.equal(isValidPhrase(VECTOR_PHRASE.replace('about', 'abandon')), false, 'checksum word');
    assert.equal(isValidPhrase(`  ${VECTOR_PHRASE.toUpperCase()} `), true);
    assert.equal(normalisePhrase('  ABANDON   about '), 'abandon about');
});
