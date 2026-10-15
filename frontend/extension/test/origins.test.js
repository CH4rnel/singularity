import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    accountsFor,
    grantOrigin,
    grantedOrigins,
    hostOf,
    isConnected,
    matchPattern,
    normaliseOrigin,
    permissionPattern,
    revokeOrigin,
} from '../src/shared/origins.js';

const ADDRESS = '0x9c4A0Bd45178aE0d6C1b37F9042e5A8Db02F714a';

test('an origin is scheme plus host, and nothing else', () => {
    assert.equal(
        normaliseOrigin('https://dapp.example/pool/cyber-usdc?a=1'),
        'https://dapp.example',
    );
    assert.equal(normaliseOrigin('http://localhost:5173/'), 'http://localhost:5173');
    assert.equal(hostOf('https://dapp.example/anything'), 'dapp.example');
});

test('http and https of the same host are two different places', () => {
    const grants = grantOrigin({}, 'https://dapp.example', [ADDRESS]);

    assert.equal(isConnected(grants, 'https://dapp.example', [ADDRESS]), true);
    assert.equal(isConnected(grants, 'http://dapp.example', [ADDRESS]), false);
});

test('nothing without a web origin can be granted to', () => {
    for (const input of ['file:///home/lain/dapp.html', 'chrome-extension://abc/popup.html', '', null, 'not a url']) {
        assert.equal(normaliseOrigin(input), null);
        assert.equal(matchPattern(input), null);
    }
});

test('a grant names the accounts and only those', () => {
    const grants = grantOrigin({}, 'https://cyberia.church/dao', [ADDRESS, ADDRESS]);

    assert.deepEqual(grantedOrigins(grants), ['https://cyberia.church']);
    assert.deepEqual(accountsFor(grants, 'https://cyberia.church', [ADDRESS]), [ADDRESS]);
    assert.deepEqual(accountsFor(grants, 'https://dapp.example', [ADDRESS]), []);
});

test('an account the vault no longer holds is never reported to a page', () => {
    const grants = grantOrigin({}, 'https://cyberia.church', [ADDRESS]);

    assert.deepEqual(accountsFor(grants, 'https://cyberia.church', []), []);
    assert.equal(isConnected(grants, 'https://cyberia.church', ['0xdead']), false);
});

test('a grant survives being re-granted, and dies when revoked', () => {
    const first = grantOrigin({}, 'https://cyberia.church', [ADDRESS], 1_000);
    const second = grantOrigin(first, 'https://cyberia.church', [ADDRESS], 2_000);

    assert.equal(second['https://cyberia.church'].grantedAt, 1_000);
    assert.equal(second['https://cyberia.church'].lastSeen, 2_000);
    assert.deepEqual(grantedOrigins(revokeOrigin(second, 'https://cyberia.church')), []);
});

test('the match pattern injects into that origin and no other', () => {
    assert.equal(matchPattern('https://dapp.example'), 'https://dapp.example/*');
});

test('a site can be connected only where the manifest lets the extension ask', async () => {
    const manifest = JSON.parse(
        await (await import('node:fs/promises')).readFile(new URL('../manifest.json', import.meta.url), 'utf8'),
    );

    assert.equal(permissionPattern('https://dapp.example/pool?a=1'), 'https://dapp.example/*');
    // Gecko refuses a port in a permission pattern; the grant keeps it.
    assert.equal(permissionPattern('http://localhost:5173/'), 'http://localhost/*');
    // Nothing in optional_host_permissions covers plain http elsewhere.
    assert.equal(permissionPattern('http://example.com/'), null);
    assert.equal(permissionPattern('about:addons'), null);
    assert.equal(permissionPattern('moz-extension://abc/popup.html'), null);

    assert.ok(manifest.optional_host_permissions.includes('https://*/*'));
    assert.ok(manifest.optional_host_permissions.includes('http://localhost/*'));
});
