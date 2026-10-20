import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const desktop = fileURLToPath(new URL('..', import.meta.url));
const version = JSON.parse(readFileSync(path.join(desktop, 'package.json'), 'utf8')).version;
const binary = path.resolve(process.argv[2] || path.join(desktop, `dist/local-wallet-${version}/linux-unpacked/cyberia-desktop`));
const profile = mkdtempSync(path.join(tmpdir(), 'cyberia-package-test-'));
mkdirSync(path.join(profile, 'cache'));
const portProbe = createServer();
await new Promise((resolve) => portProbe.listen(0, '127.0.0.1', resolve));
const port = portProbe.address().port;
await new Promise((resolve) => portProbe.close(resolve));
const child = spawn(binary, [`--remote-debugging-port=${port}`, '--remote-debugging-address=127.0.0.1', '--autostart', '--no-proxy', '--disable-gpu'], {
    env: { ...process.env, XDG_CONFIG_HOME: profile, XDG_CACHE_HOME: path.join(profile, 'cache') },
    stdio: ['ignore', 'pipe', 'pipe'],
});
let logs = '';
child.stdout.on('data', (data) => { logs = (logs + data).slice(-12_000); });
child.stderr.on('data', (data) => { logs = (logs + data).slice(-12_000); });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let socket;
try {
    const deadline = Date.now() + 25_000;
    let page;
    while (Date.now() < deadline) {
        if (child.exitCode !== null) throw new Error(`Packaged app exited early: ${logs}`);
        try {
            const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
            page = pages.find((candidate) => candidate.type === 'page' && candidate.url === 'https://wallet.cyberia.invalid/wallet');
            if (page) break;
        } catch { /* The inspector is not listening yet. */ }
        await sleep(200);
    }
    assert.ok(page, `The packaged local wallet did not open: ${logs}`);
    socket = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    const pending = new Map();
    let id = 0;
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (!message.id) return;
        const callback = pending.get(message.id);
        if (callback) { pending.delete(message.id); callback(message); }
    };
    const command = (method, params = {}) => new Promise((resolve, reject) => {
        const request = ++id;
        const timer = setTimeout(() => { pending.delete(request); reject(new Error(`Inspector timeout: ${method}`)); }, 25_000);
        pending.set(request, (message) => {
            clearTimeout(timer);
            if (message.error) reject(new Error(message.error.message));
            else resolve(message.result);
        });
        socket.send(JSON.stringify({ id: request, method, params }));
    });
    const evaluate = async (expression) => {
        const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
        return result.result.value;
    };
    let setup;
    for (let tries = 0; tries < 100; tries++) {
        setup = await evaluate(`({
            local: location.origin === 'https://wallet.cyberia.invalid',
            setup: Boolean(document.querySelector('[data-desktop-setup]')),
            native: window.cyberiaNative?.localWallet === true,
            version: window.cyberiaNative?.version === ${JSON.stringify(version)},
            noNode: typeof window.require === 'undefined',
            emptyVault: localStorage.getItem('cyberia.wallet.vault.v1') === null,
            localScripts: [...document.scripts].every((script) => !script.src || new URL(script.src).origin === location.origin)
        })`);
        if (setup.setup) break;
        await sleep(100);
    }
    assert.ok(Object.values(setup).every(Boolean), JSON.stringify(setup));
    const source = await evaluate("fetch('/source.json').then((response) => response.json())");
    assert.deepEqual(source, JSON.parse(readFileSync(path.join(desktop, 'wallet-dist/source.json'), 'utf8')));
    console.log('PASS: packaged app opens the bundled wallet in an isolated profile');
    const services = await evaluate(`(async () => {
        const read = async (url, body) => {
            try {
                const response = await fetch(url, { signal: AbortSignal.timeout(15000), ...(body ? {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
                } : {}) });
                const data = await response.json();
                return { status: response.status, ok: response.ok && (body ? data.result !== undefined : typeof data.prices === 'object') };
            } catch (error) { return { ok: false, error: error.name }; }
        };
        return {
            prices: await read('/api/wallet/prices'),
            cyberia: await read('https://rpc.cyberia.church', { jsonrpc: '2.0', id: 1, method: 'eth_blockNumber', params: [] }),
            solana: await read('/api/solana/rpc', { jsonrpc: '2.0', id: 2, method: 'getSlot', params: [] })
        };
    })()`);
    console.log('Public service reads:', JSON.stringify(services));
    assert.ok(Object.values(services).every((service) => service.ok), 'A public service read failed.');
    console.log('PASS: packaged wallet reads prices, Cyberia RPC and the Solana relay');
} finally {
    socket?.close();
    child.kill('SIGTERM');
}
