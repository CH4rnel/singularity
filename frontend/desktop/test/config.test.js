'use strict';

/**
 * Pure-logic tests for the navigation rules. They run under plain Node
 * (`npm test`) because they must not need a display or an Electron binary.
 */

const assert = require('node:assert/strict');
const { test } = require('node:test');
const {
    DEFAULT_APP_URL,
    isExternallyOpenable,
    isNavigable,
    resolveAppUrl,
} = require('../src/config');

const HOST = 'cyberia.church';

test('falls back to the production URL', () => {
    assert.equal(resolveAppUrl({}, []), DEFAULT_APP_URL);
});

test('reads the URL from the environment and the command line', () => {
    assert.equal(resolveAppUrl({ CYBERIA_APP_URL: 'https://staging.test/' }, []), 'https://staging.test');
    assert.equal(
        resolveAppUrl({ CYBERIA_APP_URL: 'https://staging.test' }, ['--url=http://localhost:8000']),
        'http://localhost:8000',
    );
});

test('rejects a malformed or non-http override', () => {
    assert.equal(resolveAppUrl({ CYBERIA_APP_URL: 'not a url' }, []), DEFAULT_APP_URL);
    assert.equal(resolveAppUrl({ CYBERIA_APP_URL: 'file:///etc/passwd' }, []), DEFAULT_APP_URL);
});

test('keeps the app and its subdomains inside the window', () => {
    assert.ok(isNavigable('https://cyberia.church/swap', HOST));
    assert.ok(isNavigable('https://explorer.cyberia.church/tx/0x1', HOST));
});

test('keeps the OAuth providers inside the window', () => {
    assert.ok(isNavigable('https://x.com/i/oauth2/authorize?x=1', HOST));
});

test('sends everything else to the system browser', () => {
    assert.equal(isNavigable('https://evil.example/cyberia.church', HOST), false);
    assert.equal(isNavigable('https://cyberia.church.evil.example/', HOST), false);
    assert.equal(isNavigable('wc:topic@2?relay-protocol=irn', HOST), false);
    assert.equal(isNavigable('file:///etc/passwd', HOST), false);
    assert.equal(isNavigable('javascript:alert(1)', HOST), false);
});

test('hands wallet and messenger schemes to the operating system', () => {
    assert.ok(isExternallyOpenable('wc:topic@2?relay-protocol=irn'));
    assert.ok(isExternallyOpenable('metamask://wc?uri=wc%3Atopic'));
    assert.ok(isExternallyOpenable('tg://resolve?domain=cyberia'));
    assert.ok(isExternallyOpenable('https://explorer.cyberia.church/'));
});

test('never hands executable or local schemes to the operating system', () => {
    assert.equal(isExternallyOpenable('javascript:alert(1)'), false);
    assert.equal(isExternallyOpenable('data:text/html,<script>alert(1)</script>'), false);
    assert.equal(isExternallyOpenable('file:///etc/passwd'), false);
    assert.equal(isExternallyOpenable('not a url'), false);
    assert.equal(isExternallyOpenable(`https://cyberia.church/${'a'.repeat(2048)}`), false);
});
