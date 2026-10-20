'use strict';

const assert = require('node:assert/strict');
const { test } = require('node:test');
const { mkdtemp, writeFile, mkdir, rm } = require('node:fs/promises');
const { tmpdir } = require('node:os');
const path = require('node:path');
const { LOCAL_ORIGIN, LOCAL_START, CSP, createLocalHandler, blocksRemoteCode, isLocalWallet, localStartUrl, publicUrl } = require('../src/local-wallet');

test('the wallet origin is exact and external links return to the public site', () => {
    assert.equal(isLocalWallet(LOCAL_START), true);
    for (const url of ['https://wallet.cyberia.invalid.evil.test/wallet', 'https://user@wallet.cyberia.invalid/wallet', 'http://wallet.cyberia.invalid/wallet', 'https://cyberia.church/wallet']) {
        assert.equal(isLocalWallet(url), false);
    }
    assert.equal(localStartUrl('https://cyberia.church/wallet?section=security'), `${LOCAL_START}?section=security`);
    assert.equal(localStartUrl('https://cyberia.church/login'), LOCAL_START);
    assert.equal(publicUrl(`${LOCAL_ORIGIN}/profile`, 'https://cyberia.church'), 'https://cyberia.church/profile');
});

test('remote executable resources and frames are refused while RPC and images work', () => {
    for (const resourceType of ['mainFrame', 'subFrame', 'script', 'stylesheet', 'object']) {
        assert.equal(blocksRemoteCode({ url: 'https://cyberia.church/asset', resourceType }), true);
        assert.equal(blocksRemoteCode({ url: `${LOCAL_ORIGIN}/assets/app.js`, resourceType }), false);
    }
    assert.equal(blocksRemoteCode({ url: 'https://rpc.cyberia.church', resourceType: 'xhr' }), false);
    assert.equal(blocksRemoteCode({ url: 'https://example.test/token.png', resourceType: 'image' }), false);
    assert.match(CSP, /script-src 'self' 'wasm-unsafe-eval'/);
    assert.doesNotMatch(CSP, /script-src[^;]*(?:https:|'unsafe-inline'|'unsafe-eval')/);
    assert.match(CSP, /frame-src 'none'/);
});

test('local assets boot without a server and missing assets never fall back to the network', async (t) => {
    const root = await mkdtemp(path.join(tmpdir(), 'cyberia-assets-'));
    t.after(() => rm(root, { recursive: true, force: true }));
    await writeFile(path.join(root, 'index.html'), '<h1>Local wallet</h1>');
    await mkdir(path.join(root, 'assets'));
    await writeFile(path.join(root, 'assets/app.js'), 'export const local = true;');
    const handle = createLocalHandler({ root, siteUrl: 'https://cyberia.church', fetchRemote: () => { throw new Error('Network must not be used'); } });
    const page = await handle(new Request(LOCAL_START));
    assert.equal(page.status, 200);
    assert.equal(await page.text(), '<h1>Local wallet</h1>');
    assert.equal(page.headers.get('Content-Security-Policy'), CSP);
    assert.equal((await handle(new Request(`${LOCAL_ORIGIN}/assets/app.js`))).status, 200);
    assert.equal((await handle(new Request(`${LOCAL_ORIGIN}/missing.js`))).status, 404);
    assert.equal((await handle(new Request(`${LOCAL_ORIGIN}/%2e%2e%2fsecret`))).status, 400);
    assert.equal((await handle(new Request(`${LOCAL_ORIGIN}/assets%5c..%5csecret`))).status, 400);
});

test('the gateway forwards signed RPC payloads to a fixed backend and returns only JSON', async () => {
    const calls = [];
    let body = '{"result":"ok"}';
    let contentType = 'application/json';
    const handle = createLocalHandler({
        root: tmpdir(), siteUrl: 'https://cyberia.church',
        csrfToken: async () => 'test-csrf',
        fetchRemote: async (url, init) => {
            calls.push({ url, init });
            return new Response(body, { headers: { 'content-type': contentType } });
        },
    });
    const request = () => new Request(`${LOCAL_ORIGIN}/api/solana/rpc`, {
        method: 'POST', body: '{"method":"getSlot"}',
        headers: { Origin: LOCAL_ORIGIN, Cookie: 'local=private', 'X-Inertia': 'true', 'X-XSRF-TOKEN': '' },
    });
    assert.deepEqual(await (await handle(request())).json(), { result: 'ok' });
    assert.equal(calls[0].url, 'https://cyberia.church/api/solana/rpc');
    assert.equal(new TextDecoder().decode(calls[0].init.body), '{"method":"getSlot"}');
    assert.equal(calls[0].init.headers.has('cookie'), false);
    assert.equal(calls[0].init.headers.has('origin'), false);
    assert.equal(calls[0].init.headers.has('x-inertia'), false);
    assert.equal(calls[0].init.headers.get('X-XSRF-TOKEN'), 'test-csrf');
    assert.equal(calls[0].init.redirect, 'error');
    contentType = 'text/html';
    body = '<script>malicious()</script>';
    assert.equal((await handle(request())).status, 502);
    contentType = 'application/json';
    assert.equal((await handle(request())).status, 502);
});

test('account refresh extracts data without rendering server HTML or choosing a component', async () => {
    let page = { component: 'Wallet', props: { auth: { user: { id: 7 } }, arbitrary: '<script>remote()</script>' } };
    const handle = createLocalHandler({
        root: tmpdir(), siteUrl: 'https://cyberia.church',
        fetchRemote: async () => new Response(`<script>remote()</script><script data-page="app" type="application/json">${JSON.stringify(page).replaceAll('<', '\\u003c')}</script>`),
    });
    const request = () => new Request(`${LOCAL_START}`, { headers: { 'X-Inertia': 'true' } });
    const response = await handle(request());
    const local = await response.json();
    assert.equal(response.headers.get('X-Inertia'), 'true');
    assert.equal(local.component, 'Wallet');
    assert.equal(local.version, 'desktop-local-v1');
    assert.equal(local.props.auth.user.id, 7);
    assert.equal(Object.hasOwn(local.props, 'arbitrary'), false);
    page = { component: 'RemoteCode', props: {} };
    assert.equal((await handle(request())).status, 502);
});
