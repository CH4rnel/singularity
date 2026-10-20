#!/usr/bin/env node
/**
 * Upload a package to an existing Chrome Web Store item and submit it.
 *
 *   CWS_TOKEN=… CWS_PUBLISHER_ID=… CWS_EXTENSION_ID=… \
 *       node scripts/stores/chrome-web-store.mjs Cyberia-extension.zip [staged|default]
 *
 * Chrome Web Store API v2 (v1.1 stopped answering on 15 October 2026). The
 * token is a service account's, for the `chromewebstore` scope
 * (`google-token.mjs`); that service account has to be added under *Account →
 * Service accounts* in the developer dashboard first. v2 cannot create an item
 * or change its visibility, so the listing stays a dashboard job: this only
 * moves the package.
 *
 * `staged` (the default here) submits for review and, once approved, waits
 * for somebody to press Publish in the dashboard; `default` goes live the
 * moment review passes. A full review is what a version with new permissions
 * gets either way.
 */
import { readFile } from 'node:fs/promises';

const [zipPath, mode = 'staged'] = process.argv.slice(2);
const { CWS_TOKEN: token, CWS_PUBLISHER_ID: publisher, CWS_EXTENSION_ID: item } = process.env;

if (!zipPath || !token || !publisher || !item) {
    console.error('usage: CWS_TOKEN=… CWS_PUBLISHER_ID=… CWS_EXTENSION_ID=… chrome-web-store.mjs <zip> [staged|default]');
    process.exit(2);
}

const name = `publishers/${publisher}/items/${item}`;
const API = 'https://chromewebstore.googleapis.com';
const headers = { Authorization: `Bearer ${token}` };

const call = async (url, init = {}) => {
    const response = await fetch(url, { ...init, headers: { ...headers, ...init.headers } });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(`${init.method ?? 'GET'} ${url} → ${response.status}: ${JSON.stringify(body.error ?? body)}`);
    }

    return body;
};

const upload = await call(`${API}/upload/v2/${name}:upload?uploadType=media`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/zip' },
    body: await readFile(zipPath),
});
console.log(`uploaded: ${upload.uploadState ?? 'unknown state'} ${upload.crxVersion ?? ''}`.trim());

// A large package is processed asynchronously; publishing before it is done
// would submit the previous one.
let state = upload.uploadState;
for (let attempt = 0; state === 'UPLOAD_IN_PROGRESS' && attempt < 24; attempt++) {
    await new Promise((resolve) => setTimeout(resolve, 5000));
    const status = await call(`${API}/v2/${name}:fetchStatus`);
    const text = JSON.stringify(status);
    state = text.includes('UPLOAD_IN_PROGRESS') ? 'UPLOAD_IN_PROGRESS' : text.includes('FAILED') ? 'FAILED' : 'SUCCEEDED';
}

if (state === 'UPLOAD_IN_PROGRESS' || state === 'FAILED' || /FAIL/.test(String(state))) {
    console.error(`the package did not finish processing (${state}); nothing was submitted`);
    process.exit(1);
}

const published = await call(`${API}/v2/${name}:publish`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ publishType: mode === 'default' ? 'DEFAULT_PUBLISH' : 'STAGED_PUBLISH' }),
});

console.log(`submitted for review: ${published.state ?? 'unknown state'}`);
for (const warning of published.warningInfo?.warnings ?? []) {
    console.log(`::warning title=Chrome Web Store::${warning.reason}: ${warning.description}`);
}
