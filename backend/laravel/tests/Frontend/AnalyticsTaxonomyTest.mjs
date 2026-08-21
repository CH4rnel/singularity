import assert from 'node:assert/strict';
import test from 'node:test';
import {
    parseAttribution,
    firstTouch,
    sessionExpired,
} from '@/lib/analytics/attribution';
import { errorCode } from '@/lib/analytics/errors';
import {
    ANALYTICS_EVENTS,
    MEANINGFUL_EVENTS,
    sanitizeProperties,
} from '@/lib/analytics/taxonomy';

/**
 * The pure half of the analytics client.
 *
 * Three things are pinned here and all three are the kind of bug that is
 * invisible until a decision has already been made on the number: what may
 * leave the device, where a user is recorded as having come from, and where
 * one visit ends.
 */

/* ------------------------------------------------------------- privacy -- */

test('a property nobody allowed does not leave the device', () => {
    const clean = sanitizeProperties({
        chain: 'cyberia',
        amount_usd: 42.18,
        // Every one of these is a call site handing over the wrong variable,
        // which is the failure mode an allowlist exists for.
        seed: 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
        password: 'hunter2',
        to: '0x1234567890123456789012345678901234567890',
        privateKey: `0x${'a'.repeat(64)}`,
        txHash: `0x${'f'.repeat(64)}`,
    });

    assert.deepEqual(clean, { chain: 'cyberia', amount_usd: 42.18 });
});

test('a secret put into an allowlisted field is refused there too', () => {
    // The one hole an allowlist of keys leaves: the right field, wrong value.
    assert.deepEqual(sanitizeProperties({ route: `0x${'bee5'.repeat(16)}` }), {});
    assert.deepEqual(
        sanitizeProperties({ asset: 'abandon abandon abandon abandon abandon abandon' }),
        {},
    );

    // And the ordinary values still pass.
    assert.deepEqual(sanitizeProperties({ route: 'CYBER → WCYBER → USDC' }), {
        route: 'CYBER → WCYBER → USDC',
    });
    assert.deepEqual(sanitizeProperties({ asset: 'USDC' }), { asset: 'USDC' });
});

test('numbers are clamped and rounded, never trusted', () => {
    const clean = sanitizeProperties({
        amount_usd: -5,
        duration_ms: 99_999_999,
        price_impact: 1e9,
        hops: 3.7,
        slippage: Number.NaN,
        pid: Number.POSITIVE_INFINITY,
    });

    assert.equal(clean.amount_usd, 0);
    assert.equal(clean.duration_ms, 3_600_000);
    assert.equal(clean.price_impact, 100);
    assert.equal(clean.hops, 4);
    // Not a number is dropped, never stored as zero.
    assert.equal('slippage' in clean, false);
    assert.equal('pid' in clean, false);
});

test('an unrecognised error code becomes unknown rather than a message', () => {
    assert.deepEqual(
        sanitizeProperties({ error_code: 'insufficient funds for gas * price + value' }),
        { error_code: 'unknown' },
    );
});

/* ------------------------------------------------------------ taxonomy -- */

test('activation is settlement, and the list says so', () => {
    for (const event of MEANINGFUL_EVENTS) {
        assert.ok(
            ANALYTICS_EVENTS.includes(event),
            `${event} is meaningful but not a known event`,
        );
    }

    // The ones that look like activity and are not: opening a screen, asking
    // for a price, and broadcasting something nobody has confirmed yet.
    for (const event of [
        'app_opened',
        'session_started',
        'swap_opened',
        'swap_quote_received',
        'transaction_submitted',
        'wallet_created',
        'wallet_funded',
        'gas_sponsorship_completed',
    ]) {
        assert.equal(
            MEANINGFUL_EVENTS.includes(event),
            false,
            `${event} must not count as activation`,
        );
    }
});

test('no event name is listed twice', () => {
    assert.equal(new Set(ANALYTICS_EVENTS).size, ANALYTICS_EVENTS.length);
});

/* --------------------------------------------------------------- errors -- */

test('the gas rule wins over the funds rule, because the message contains both', () => {
    // "insufficient funds for gas * price + value" is an out-of-gas failure
    // and contains the word "funds"; the wrong order files every empty tank
    // as a user who could not afford the amount.
    assert.equal(
        errorCode(new Error('insufficient funds for gas * price + value')),
        'insufficient_gas',
    );
    assert.equal(
        errorCode(new Error('transfer amount exceeds balance')),
        'insufficient_funds',
    );
});

test('a changed mind is never a defect', () => {
    assert.equal(errorCode({ code: 4001 }), 'user_rejected');
    assert.equal(errorCode({ code: 'ACTION_REJECTED' }), 'user_rejected');
    assert.equal(errorCode(new Error('MetaMask Tx Signature: User denied transaction signature.')), 'user_rejected');
});

test('an error nobody recognised is a code, never a message', () => {
    const message = 'Something specific about 0xabc and 12.5 USDC on node-3';

    assert.equal(errorCode(new Error(message)), 'unknown');
    assert.equal(errorCode(null), 'unknown');
    assert.equal(errorCode(undefined), 'unknown');
    assert.equal(errorCode({}), 'unknown');
});

test('the common wallet failures each land somewhere useful', () => {
    const cases = [
        ['UniswapV2Router: INSUFFICIENT_OUTPUT_AMOUNT', 'slippage'],
        ['No route found for this pair', 'no_route'],
        ['execution reverted: ERC20: transfer amount exceeds allowance', 'allowance'],
        ['replacement transaction underpriced', 'nonce'],
        ['This account is watch-only and cannot sign', 'watch_only'],
        ['Failed to fetch', 'rpc_unreachable'],
        ['Waiting for the receipt timed out', 'timeout'],
        ['UniswapV2Router: EXPIRED', 'quote_expired'],
    ];

    for (const [message, expected] of cases) {
        assert.equal(errorCode(new Error(message)), expected, message);
    }
});

/* -------------------------------------------------------- attribution -- */

test('utm parameters are the campaign when they are there', () => {
    const attribution = parseAttribution(
        'https://cyberia.church/wallet?utm_source=twitter&utm_medium=social&utm_campaign=launch&utm_content=thread',
        '',
    );

    assert.deepEqual(attribution, {
        source: 'twitter',
        medium: 'social',
        campaign: 'launch',
        content: 'thread',
        landing_path: '/wallet',
    });
});

test('a referrer is reduced to its origin and never kept whole', () => {
    const attribution = parseAttribution(
        'https://cyberia.church/wallet',
        'https://news.ycombinator.com/item?id=12345',
    );

    // The site that sent them, not the page they were reading.
    assert.equal(attribution.referrer, 'https://news.ycombinator.com');
    assert.equal(attribution.source, 'news.ycombinator.com');
    assert.equal(attribution.medium, 'referral');
});

test('a link from our own pages is navigation, not acquisition', () => {
    const attribution = parseAttribution(
        'https://cyberia.church/wallet',
        'https://cyberia.church/download',
    );

    assert.equal(attribution.referrer, undefined);
    assert.equal(attribution.source, undefined);
});

test('a tag on the URL outranks the site that linked here', () => {
    const attribution = parseAttribution(
        'https://cyberia.church/wallet?utm_source=newsletter',
        'https://t.co/abc',
    );

    assert.equal(attribution.source, 'newsletter');
    // The referrer is still recorded; it just does not become the source.
    assert.equal(attribution.referrer, 'https://t.co');
});

test('the Telegram start parameter is the only campaign a Mini App can carry', () => {
    // Inside a chat there is no URL bar to put a utm_source in and no
    // referrer to read; `t.me/<bot>/app?startapp=<value>` is the whole channel.
    const attribution = parseAttribution(
        'https://cyberia.church/wallet#tgWebAppPlatform=android',
        '',
        'spring_airdrop',
    );

    assert.equal(attribution.source, 'telegram');
    assert.equal(attribution.medium, 'mini_app');
    assert.equal(attribution.campaign, 'spring_airdrop');
});

test('first touch means the first one, and a later campaign cannot take it', () => {
    const first = { source: 'twitter', campaign: 'launch' };
    const later = { source: 'retargeting', campaign: 'winback' };

    assert.deepEqual(firstTouch(first, later), first);
    // Including when the first touch was untagged: "direct" is an answer.
    assert.deepEqual(firstTouch({}, later), {});
    // And nothing stored yet takes whatever this visit says.
    assert.deepEqual(firstTouch(null, later), later);
});

/* ----------------------------------------------------------- sessions -- */

test('a session ends after thirty minutes of silence', () => {
    const timeout = 30 * 60_000;
    const now = 1_700_000_000_000;

    assert.equal(sessionExpired(now, now - 60_000, timeout), false);
    assert.equal(sessionExpired(now, now - (29 * 60_000), timeout), false);
    assert.equal(sessionExpired(now, now - timeout, timeout), true);
    assert.equal(sessionExpired(now, now - (60 * 60_000), timeout), true);
    // Nothing stored is a new session, not an eternal one.
    assert.equal(sessionExpired(now, null, timeout), true);
});
