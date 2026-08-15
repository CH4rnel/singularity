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
    revokeOrigin,
} from '../src/shared/origins.js';

const ADDRESS = '0x9c4A0Bd45178aE0d6C1b37F9042e5A8Db02F714a';

test('an origin is scheme plus host, and nothing else', () => {
    assert.equal(
        normaliseOrigin('https://swap.cyberia.church/pool/cyber-usdc?a=1'),
        'https://swap.cyberia.church',
    );
    assert.equal(normaliseOrigin('http://localhost:5173/'), 'http://localhost:5173');
    assert.equal(hostOf('https://swap.cyberia.church/anything'), 'swap.cyberia.church');
});

test('http and https of the same host are two different places', () => {
    const grants = grantOrigin({}, 'https://swap.cyberia.church', [ADDRESS]);

    assert.equal(isConnected(grants, 'https://swap.cyberia.church', [ADDRESS]), true);
    assert.equal(isConnected(grants, 'http://swap.cyberia.church', [ADDRESS]), false);
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
    assert.deepEqual(accountsFor(grants, 'https://swap.cyberia.church', [ADDRESS]), []);
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
    assert.equal(matchPattern('https://swap.cyberia.church'), 'https://swap.cyberia.church/*');
});
