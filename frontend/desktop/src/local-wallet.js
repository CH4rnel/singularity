'use strict';

const fs = require('node:fs/promises');
const path = require('node:path');

// This origin exists only inside the app's session; it is never resolved by DNS.
const LOCAL_ORIGIN = 'https://wallet.cyberia.invalid';
const LOCAL_START = `${LOCAL_ORIGIN}/wallet`;
const CSP = [
    "default-src 'none'",
    "script-src 'self' 'wasm-unsafe-eval'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' https: data: blob:",
    "font-src 'self'",
    "connect-src 'self' https: wss: http://localhost:* http://127.0.0.1:*",
    "media-src 'self' https: blob: cyberia-stream:",
    "worker-src 'self' blob:",
    "frame-src 'none'",
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'none'",
    "frame-ancestors 'none'",
].join('; ');

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.json': 'application/json',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.webp': 'image/webp',
    '.ico': 'image/x-icon',
    '.woff2': 'font/woff2',
    '.ttf': 'font/ttf',
    '.wasm': 'application/wasm',
};

function isLocalWallet(target) {
    try {
        const url = new URL(target);
        return url.origin === LOCAL_ORIGIN && !url.username && !url.password;
    } catch {
        return false;
    }
}

function localStartUrl(target) {
    const url = new URL(target);
    return url.pathname === '/wallet' || url.pathname === '/'
        ? `${LOCAL_START}${url.search}${url.hash}`
        : LOCAL_START;
}

function publicUrl(target, siteUrl) {
    const url = new URL(target, LOCAL_ORIGIN);
    return isLocalWallet(url.href)
        ? `${new URL(siteUrl).origin}${url.pathname}${url.search}${url.hash}`
        : url.href;
}

function blocksRemoteCode({ url, resourceType }) {
    if (isLocalWallet(url) || url.startsWith('file:') || url.startsWith('devtools:')) {
        return false;
    }
    return ['mainFrame', 'subFrame', 'script', 'stylesheet', 'object'].includes(resourceType);
}

function jsonError(message, status) {
    return Response.json({ message }, { status });
}

/** Local files and a JSON-only gateway. Remote HTML never enters this origin. */
function createLocalHandler({ root, siteUrl, fetchRemote, csrfToken = async () => '' }) {
    const directory = path.resolve(root);
    const site = new URL(siteUrl).origin;

    return async (request) => {
        try {
            if (!isLocalWallet(request.url)) {
                return await fetchRemote(request, { bypassCustomProtocolHandlers: true });
            }
            const url = new URL(request.url);
            const pathname = decodeURIComponent(url.pathname);

            // An account refresh may read server data, but always renders our local Wallet component.
            if (pathname === '/wallet' && request.headers.get('x-inertia') === 'true') {
                const response = await fetchRemote(`${site}/wallet`, {
                    headers: { Accept: 'text/html' }, credentials: 'include', redirect: 'error',
                    bypassCustomProtocolHandlers: true,
                });
                if (!response.ok) return jsonError('The account could not be refreshed.', 502);
                const html = await response.text();
                const embedded = html.match(/<script\b[^>]*\bdata-page(?:\s*=\s*(?:"[^"]*"|'[^']*'))?[^>]*>([\s\S]*?)<\/script>/i);
                const page = embedded ? JSON.parse(embedded[1]) : null;
                if (page?.component !== 'Wallet' || !page.props) return jsonError('Invalid account response.', 502);
                const props = { errors: {} };
                for (const key of ['auth', 'moneroPayoutAddress', 'quotes', 'lain', 'ipfs', 'sponsor', 'bridge']) {
                    if (Object.hasOwn(page.props, key)) props[key] = page.props[key];
                }
                return Response.json({
                    component: 'Wallet', url: '/wallet', version: 'desktop-local-v1', props,
                    clearHistory: false, encryptHistory: false, rememberedState: {}, flash: {},
                }, { headers: { 'X-Inertia': 'true', 'Content-Security-Policy': CSP, 'X-Content-Type-Options': 'nosniff' } });
            }
            const api = /^\/(?:api\/|bridge\/|wallets\/|login\/web3(?:\/|$)|logout(?:\/|$))/.test(pathname);

            if (api) {
                const headers = new Headers(request.headers);
                // Cookie and CSRF state belongs to the backend's origin, not the bundle.
                for (const name of ['host', 'origin', 'referer', 'cookie', 'content-length', 'x-inertia', 'x-inertia-version']) {
                    headers.delete(name);
                }
                headers.set('Accept', 'application/json');
                const method = request.method.toUpperCase();
                if (!['GET', 'HEAD'].includes(method) &&
                    (headers.has('x-xsrf-token') || /^\/(?:login\/|logout)/.test(pathname))) {
                    const token = await csrfToken();
                    if (token) headers.set('X-XSRF-TOKEN', token);
                }
                const response = await fetchRemote(`${site}${url.pathname}${url.search}`, {
                    method,
                    headers,
                    body: ['GET', 'HEAD'].includes(method) ? undefined : await request.arrayBuffer(),
                    credentials: 'include',
                    redirect: 'error',
                    bypassCustomProtocolHandlers: true,
                });
                if (response.status === 204) return new Response(null, { status: 204 });
                if (!/^application\/(?:[\w.-]+\+)?json(?:;|$)/i.test(response.headers.get('content-type') || '')) {
                    return jsonError('The service returned a non-JSON response.', 502);
                }
                // Parsing also prevents mislabeled HTML/JavaScript from being served.
                return Response.json(await response.json(), { status: response.status });
            }

            if (!['GET', 'HEAD'].includes(request.method)) return jsonError('Method not allowed.', 405);
            const filename = pathname === '/' || pathname === '/wallet' ? 'index.html' : pathname.slice(1);
            const target = path.resolve(directory, filename);
            const relative = path.relative(directory, target);
            if (!relative || relative.startsWith('..') || path.isAbsolute(relative) || filename.includes('\\') || filename.includes('\0')) {
                return jsonError('Invalid asset path.', 400);
            }
            const body = await fs.readFile(target);
            return new Response(request.method === 'HEAD' ? null : body, {
                headers: {
                    'Content-Type': MIME[path.extname(target)] || 'application/octet-stream',
                    'Content-Security-Policy': CSP,
                    'X-Content-Type-Options': 'nosniff',
                    'Cache-Control': 'no-store',
                    'Referrer-Policy': 'no-referrer',
                },
            });
        } catch (error) {
            if (error.code === 'ENOENT' || error.code === 'EISDIR') return jsonError('Asset not found.', 404);
            return jsonError('The service is unavailable.', 502);
        }
    };
}

module.exports = { LOCAL_ORIGIN, LOCAL_START, CSP, isLocalWallet, localStartUrl, publicUrl, blocksRemoteCode, createLocalHandler };
