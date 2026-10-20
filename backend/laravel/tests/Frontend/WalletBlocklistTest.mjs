import assert from 'node:assert/strict';
import test from 'node:test';
import {
    BLOCKLIST_LIMIT,
    blockKey,
    isBlocked,
    parseBlocklist,
    withBlocked,
    withoutBlocked,
} from '@/lib/wallet/blocklist';

/**
 * The device's blocklist: one spelling per address, nothing stored that is not
 * an address, and a block that can always be undone.
 */

const A = '0x54BB4f4a1C0E3fC8bD1B79A1C2b7d3b6E1a9d7f6';
const B = '0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9';

test('an address is compared in one spelling', () => {
    assert.equal(blockKey(A), A.toLowerCase());
    assert.equal(blockKey(`  ${A.toUpperCase().replace('0X', '0x')} `), A.toLowerCase());
    assert.equal(blockKey('vitalik.eth'), null);
    assert.equal(blockKey(null), null);
    assert.equal(isBlocked(withBlocked([], A), A.toLowerCase()), true);
});

test('blocking twice keeps one entry, and unblocking removes it', () => {
    const once = withBlocked([], A);
    const twice = withBlocked(once, A.toLowerCase());

    assert.deepEqual(twice, [A.toLowerCase()]);
    assert.deepEqual(withoutBlocked(withBlocked(twice, B), A), [B.toLowerCase()]);
    assert.equal(isBlocked(withoutBlocked(twice, A), A), false);
});

test('what was stored is read back defensively', () => {
    assert.deepEqual(parseBlocklist(null), []);
    assert.deepEqual(parseBlocklist('not json'), []);
    assert.deepEqual(parseBlocklist('{"a":1}'), []);
    assert.deepEqual(
        parseBlocklist(JSON.stringify([A, 'junk', 42, A.toLowerCase(), B])),
        [A.toLowerCase(), B.toLowerCase()],
    );
});

test('the list stays a size somebody can undo', () => {
    let list = [];

    for (let index = 0; index < BLOCKLIST_LIMIT + 5; index++) {
        list = withBlocked(list, `0x${index.toString(16).padStart(40, '0')}`);
    }

    assert.equal(list.length, BLOCKLIST_LIMIT);
    // The oldest entries are the ones that go.
    assert.equal(isBlocked(list, `0x${'0'.repeat(40)}`), false);
});
