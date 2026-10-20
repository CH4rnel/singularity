'use strict';

// Runs the production renderer and preload in an isolated, offline Electron session.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { app, BrowserWindow, ipcMain, session } = require('electron');
const { LOCAL_START, createLocalHandler, blocksRemoteCode } = require('../src/local-wallet');

const userData = fs.mkdtempSync(path.join(os.tmpdir(), 'cyberia-renderer-test-'));
app.setPath('userData', userData);
app.setPath('sessionData', userData);
app.commandLine.appendSwitch('disable-gpu');

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let window;
// These read-only fixtures exist only in this isolated test, never in a build.
async function fixture(request, options) {
    const url = new URL(typeof request === 'string' ? request : request.url);
    const body = options?.body ? new TextDecoder().decode(options.body) : typeof request === 'string' ? '' : await request.text();
    if (body) {
        const reply = (call) => {
            let result;
            switch (call.method) {
                case 'eth_chainId': result = url.hostname.includes('robinhood') ? '0x1237'
                    : url.hostname.includes('cloudflare') ? '0x1'
                    : url.hostname.includes('binance') ? '0x38'
                    : url.hostname.includes('base.org') ? '0x2105' : '0xc0fe'; break;
                case 'eth_blockNumber': result = '0x100'; break;
                case 'eth_getBalance': result = url.pathname === '/api/rpc/cyberia' ? '0x56bc75e2d63100000' : '0x0'; break;
                case 'getBalance': result = { context: { slot: 123 }, value: 250000000 }; break;
                case 'getTokenAccountsByOwner': result = { context: { slot: 123 }, value: [] }; break;
                case 'getSignaturesForAddress': result = []; break;
                default: throw new Error(`Unexpected test RPC method: ${call.method}`);
            }
            return { jsonrpc: '2.0', id: call.id, result };
        };
        const calls = JSON.parse(body);
        return Response.json(Array.isArray(calls) ? calls.map(reply) : reply(calls));
    }
    if (url.pathname === '/api/wallet/prices') return Response.json({
        prices: { cyberia: 0.0645, ethereum: 2000, robinhood: 1, bnb: 500, base: 2000, solana: 105, bitcoin: 60000, litecoin: 70, monero: 150 },
        tokens: {}, fetchedAt: new Date().toISOString(),
    });
    if (/\/address\/[^/]+$/.test(url.pathname)) return Response.json({
        chain_stats: { funded_txo_sum: 0, spent_txo_sum: 0 },
        mempool_stats: { funded_txo_sum: 0, spent_txo_sum: 0 },
    });
    if (/\/txs$/.test(url.pathname)) return Response.json([]);
    if (url.pathname.includes('/api/v2/')) return Response.json({ items: [], next_page_params: null });
    return Response.json({}, { status: 404 });
}
async function evaluate(source) {
    return window.webContents.executeJavaScript(source, true);
}
async function until(source) {
    const deadline = Date.now() + 20_000;
    while (Date.now() < deadline) {
        if (await evaluate(source)) return;
        await wait(100);
    }
    throw new Error(`Renderer condition timed out: ${source}`);
}

app.whenReady().then(async () => {
    const ses = session.fromPartition('cyberia-offline-renderer-test');
    let networkAttempts = 0;
    let fixturesEnabled = false;
    ses.protocol.handle('https', createLocalHandler({
        root: process.env.CYBERIA_TEST_RENDERER || path.join(__dirname, '..', 'wallet-dist'),
        siteUrl: 'https://cyberia.church',
        fetchRemote: async (request, options) => {
            networkAttempts++;
            if (fixturesEnabled) return fixture(request, options);
            throw new Error('The network is disconnected for this test.');
        },
    }));
    ses.webRequest.onBeforeRequest((details, callback) => callback({ cancel: blocksRemoteCode(details) }));
    ipcMain.on('shell:info', (event) => {
        event.returnValue = { shell: 'desktop', localWallet: true, version: 'test', startup: {} };
    });
    window = new BrowserWindow({
        show: false, width: 1440, height: 910,
        webPreferences: {
            session: ses, contextIsolation: true, nodeIntegration: false, sandbox: true,
            offscreen: true, backgroundThrottling: false,
            preload: path.join(__dirname, '..', 'src/preload.js'),
        },
    });
    await window.loadURL(`${LOCAL_START}?section=browse`);
    await until('Boolean(document.querySelector("[data-desktop-setup]"))');
    assert.equal(await evaluate('localStorage.getItem("cyberia.wallet.vault.v1")'), null);
    assert.equal(networkAttempts, 0, 'setup must not depend on the network');
    console.log('PASS: local wallet boots offline without creating an unencrypted vault');
    assert.equal(await evaluate(`new Promise((resolve) => {
        const script = document.createElement('script');
        script.src = 'https://remote.invalid/remote.js';
        script.onload = () => resolve('loaded');
        script.onerror = () => resolve('blocked');
        document.head.append(script);
    })`), 'blocked');
    assert.equal(networkAttempts, 0, 'remote scripts must be blocked before reaching the network');
    console.log('PASS: remote JavaScript is blocked by the renderer policy');
    assert.equal(await evaluate('document.querySelector("[data-desktop-create]").disabled'), true);
    await evaluate(`for (const selector of ['[data-desktop-password]', '[data-desktop-password-again]']) {
        const input = document.querySelector(selector); input.value = 'test-only password for renderer';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }`);
    await until('!document.querySelector("[data-desktop-create]").disabled');
    await evaluate('document.querySelector("[data-desktop-create]").click()');
    await until('!document.querySelector("[data-desktop-setup]")');
    assert.equal(await evaluate(`(() => {
        const record = JSON.parse(localStorage.getItem('cyberia.wallet.vault.v1'));
        return record.kdf === 'PBKDF2-SHA-256' && typeof record.ciphertext === 'string' && !record.contents && !record.phrase;
    })()`), true);
    assert.equal(await evaluate('Boolean(window.cyberiaNative?.localWallet)'), true);
    assert.equal(await evaluate('typeof window.require'), 'undefined');
    console.log('PASS: offline creation stores only the encrypted vault');
    await until('Boolean(document.querySelector("input[inputmode=url]"))');
    await evaluate(`{
        const input = document.querySelector('input[inputmode=url]');
        input.value = 'https://example.test';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }`);
    await wait(100);
    await evaluate('document.querySelector("input[inputmode=url]").closest("form").requestSubmit()');
    await until('Boolean(document.querySelector("a.cw-btn[href^=\\"https://example.test\\"]"))');
    assert.equal(await evaluate('document.querySelectorAll("iframe").length'), 0);
    console.log('PASS: the dapp browser offers an external link instead of a remote frame');
    await evaluate(`{
        localStorage.setItem('cyberia.wallet.networks.v2', JSON.stringify({ solana: true }));
        history.replaceState(null, '', '/wallet');
    }`);
    await window.webContents.reload();
    await until('Boolean(document.querySelector("input[autocomplete=current-password]"))');
    assert.equal(await evaluate('Boolean(document.querySelector("[data-desktop-setup]"))'), false);
    console.log('PASS: restarting the renderer requires the vault password');
    await wait(300);
    const screenshot = path.join(userData, 'wallet-locked.png');
    fs.writeFileSync(screenshot, (await window.webContents.capturePage()).toPNG());
    console.log(`Locked screenshot: ${screenshot}`);
    fixturesEnabled = true;
    await evaluate(`{
        const input = document.querySelector('input[autocomplete=current-password]');
        input.value = 'test-only password for renderer';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }`);
    await wait(100);
    await evaluate('document.querySelector("input[autocomplete=current-password]").closest("form").requestSubmit()');
    await until('Boolean(document.querySelector(".cw-total"))');
    await until('document.querySelectorAll(".cw-asset").length >= 2');
    assert.equal(await evaluate(`Math.abs(document.querySelector('.cw-window').getBoundingClientRect().height - innerHeight) < 2`), true, 'the wallet must fill the viewport');
    assert.equal(await evaluate(`document.querySelector('.cw-shell').getBoundingClientRect().bottom >= innerHeight - 24`), true, 'the shell must reach the bottom margin');
    assert.equal(await evaluate('document.querySelectorAll("nav.cw-rail .cw-rail-item").length'), 5);
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cw-btn')).borderRadius`), '16px');
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cw-raster')).display`), 'none');
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cw-masthead')).display`), 'none');
    await evaluate('document.fonts.ready');
    assert.equal(await evaluate("document.fonts.check('16px Manrope')"), true);
    await wait(500);
    fs.writeFileSync(path.join(userData, 'wallet-portfolio.png'), (await window.webContents.capturePage()).toPNG());
    console.log(`PASS: current wallet layout has the five-item rail, rounded controls and no scanlines`);
    console.log(`Portfolio screenshot: ${path.join(userData, 'wallet-portfolio.png')}`);
    window.destroy();
    app.exit(0);
}).catch((error) => {
    console.error(error.message);
    if (window && !window.isDestroyed()) window.destroy();
    app.exit(1);
});
