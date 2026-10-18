import assert from 'node:assert/strict';
import test from 'node:test';
import { isFirstTime, lookalikeOf } from '@/lib/wallet/addressSafety';

/**
 * The two recipient checks on the send review: is this an address the wallet
 * has paid before, and does it imitate one it knows. Address poisoning lives in
 * the gap between "the ends match" and "it is the same address".
 */

const MINE = '0x25F999438A0CD3b661D7f4b572Ab639c4Bb3a9F2';
const POISON = '0x25F999999999999999999999999999999999a9F2';

test('an address sharing both ends of a known one, but not the middle, is a look-alike', () => {
    assert.equal(lookalikeOf(POISON, [MINE]), MINE);
    assert.equal(lookalikeOf(POISON.toLowerCase(), [MINE]), MINE);
});

test('the known address itself is not its own look-alike, and strangers are not flagged', () => {
    assert.equal(lookalikeOf(MINE, [MINE]), null);
    assert.equal(
        lookalikeOf('0x2222222222222222222222222222222222222222', [MINE]),
        null,
    );
    assert.equal(lookalikeOf('0x25F9', [MINE]), null);
});

test('non-EVM addresses are compared the same way', () => {
    const sol = 'GKJgrC14dQ8vXbNMaRq5dVnz3sw7tVz1uAryc96yCA';
    const fake = 'GKJgZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ6yCA';

    assert.equal(lookalikeOf(fake, [sol]), sol);
});

test('a first-time recipient is one this wallet has no transfer to', () => {
    assert.equal(isFirstTime(MINE, []), true);
    assert.equal(isFirstTime(MINE, [MINE.toLowerCase()]), false);
});
