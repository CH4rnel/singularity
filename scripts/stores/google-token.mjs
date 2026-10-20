#!/usr/bin/env node
/**
 * An OAuth access token for a Google service account, for one scope.
 *
 *   GOOGLE_SERVICE_ACCOUNT_JSON='{…}' node scripts/stores/google-token.mjs \
 *       https://www.googleapis.com/auth/chromewebstore
 *
 * The store workflow needs exactly this and nothing more: a JWT signed with the
 * account's key, exchanged at Google's token endpoint (RFC 7523). A library
 * for it would be the largest dependency in the job, so it is forty lines of
 * `node:crypto`. The token is printed on stdout and nothing else, so the caller
 * can mask it before it reaches a log.
 */
import { createSign } from 'node:crypto';

const scope = process.argv[2];
const raw = process.env.GOOGLE_SERVICE_ACCOUNT_JSON ?? '';

if (!scope || raw.trim() === '') {
    console.error('usage: GOOGLE_SERVICE_ACCOUNT_JSON=… google-token.mjs <scope>');
    process.exit(2);
}

const account = JSON.parse(raw);
const tokenUri = account.token_uri ?? 'https://oauth2.googleapis.com/token';
const now = Math.floor(Date.now() / 1000);

const encode = (value) => Buffer.from(JSON.stringify(value)).toString('base64url');
const unsigned = `${encode({ alg: 'RS256', typ: 'JWT' })}.${encode({
    iss: account.client_email,
    scope,
    aud: tokenUri,
    iat: now,
    exp: now + 3600,
})}`;
const signature = createSign('RSA-SHA256').update(unsigned).sign(account.private_key, 'base64url');

const response = await fetch(tokenUri, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
        grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        assertion: `${unsigned}.${signature}`,
    }),
});

const answer = await response.json().catch(() => ({}));

if (!response.ok || typeof answer.access_token !== 'string') {
    console.error(`Google refused the service account (${response.status}): ${answer.error_description ?? answer.error ?? 'no detail'}`);
    process.exit(1);
}

process.stdout.write(answer.access_token);
