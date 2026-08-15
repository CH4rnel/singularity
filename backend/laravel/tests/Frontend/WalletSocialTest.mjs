import assert from 'node:assert/strict';
import test from 'node:test';
import { poolQuote } from '@/lib/wallet/launchpad';
import { tally } from '@/lib/wallet/social';

/**
 * The two pieces of arithmetic behind the wallet's launchpad and DAO screens.
 *
 * Both are places where getting it backwards produces a confident, plausible,
 * wrong number rather than a visible failure — an inverted price and a bar that
 * counts heads instead of weight — so both are pinned here.
 */

const ether = (whole) => BigInt(whole) * 10n ** 18n;

test('a pool price is read from the side the token is actually on', () => {
    const token = '0xAAAAaaaAAAaaAAAaAAAAaAAaAaaAaaAAAAAaaaAA';
    const native = '0xBBBBbbbBBBbbBBBbBBBBbBBbBbbBbbBBBBBbbbBB';

    // 1,000,000 of the token against 10 CYBER: one token is 0.00001 CYBER.
    const first = poolQuote(token, token, [ether(1_000_000), ether(10)]);
    const second = poolQuote(native, token, [ether(10), ether(1_000_000)]);

    assert.ok(first);
    assert.ok(second);
    assert.equal(first.price, 0.00001);
    assert.equal(first.liquidity, ether(10));

    // Reserves are ordered by address, so the same pool arrives either way
    // round. Both orderings have to produce the same price.
    assert.equal(second.price, first.price);
    assert.equal(second.liquidity, first.liquidity);
});

test('token0 is compared without caring about checksum casing', () => {
    const token = '0xAAAAaaaAAAaaAAAaAAAAaAAaAaaAaaAAAAAaaaAA';

    assert.equal(
        poolQuote(token.toLowerCase(), token, [ether(100), ether(1)])?.price,
        0.01,
    );
});

test('an empty side is a pool with no price, not a price of zero', () => {
    const token = '0xAAAAaaaAAAaaAAAaAAAAaAAaAaaAaaAAAAAaaaAA';

    assert.equal(poolQuote(token, token, [0n, ether(10)]), null);
});

test('a tally weighs voting power, not voters', () => {
    // Two votes of 10 for, one vote of 100 against: counting voters calls this
    // proposal passing 2:1, and counting power says it is losing 1:5.
    const result = tally('20', '100');

    assert.equal(result.cast, 120);
    assert.ok(Math.abs(result.for - 16.6667) < 0.001);
    assert.ok(Math.abs(result.against - 83.3333) < 0.001);
    assert.equal(Math.round(result.for + result.against), 100);
});

test('a proposal nobody voted on is empty, not evenly split', () => {
    const result = tally('0', '0');

    assert.deepEqual(result, { for: 0, against: 0, cast: 0 });
});

test('a tally survives the decimal strings the database returns', () => {
    // voting_power is decimal(*,18), so it arrives as "10.000000000000000000".
    const result = tally('10.000000000000000000', '30.000000000000000000');

    assert.equal(result.cast, 40);
    assert.equal(result.for, 25);
    assert.equal(result.against, 75);
});
