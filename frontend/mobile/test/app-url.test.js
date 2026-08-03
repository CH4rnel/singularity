'use strict';

/**
 * The URL resolver decides what the app renders and which hosts stay inside the
 * WebView, so it is worth a test that runs without Android or Xcode.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { DEFAULT_APP_URL, allowNavigation, resolveAppUrl } = require('../src/app-url');

test('falls back to the production URL', () => {
    assert.equal(resolveAppUrl({}), DEFAULT_APP_URL);
});

test('accepts an http override for LAN builds', () => {
    assert.equal(resolveAppUrl({ CYBERIA_APP_URL: 'http://192.168.1.10:8000/' }), 'http://192.168.1.10:8000');
});

test('rejects a malformed or non-http override', () => {
    assert.equal(resolveAppUrl({ CYBERIA_APP_URL: 'nope' }), DEFAULT_APP_URL);
    assert.equal(resolveAppUrl({ CYBERIA_APP_URL: 'file:///etc/passwd' }), DEFAULT_APP_URL);
});

test('allows the app host, its subdomains, and the OAuth providers', () => {
    const hosts = allowNavigation('https://cyberia.church');

    assert.ok(hosts.includes('cyberia.church'));
    assert.ok(hosts.includes('*.cyberia.church'));
    assert.ok(hosts.includes('x.com'));
    assert.equal(hosts.includes('*'), false);
});

test('tracks the override host instead of the production one', () => {
    assert.deepEqual(allowNavigation('http://192.168.1.10:8000').slice(0, 2), [
        '192.168.1.10',
        '*.192.168.1.10',
    ]);
});

test('keeps the encrypted wallet vault out of Android backups', () => {
    const manifest = fs.readFileSync(
        path.join(__dirname, '..', 'android', 'app', 'src', 'main', 'AndroidManifest.xml'),
        'utf8',
    );

    assert.match(manifest, /android:allowBackup="false"/);
});
