import assert from 'node:assert/strict';
import test from 'node:test';
import {
    LOADING_CAPACITY,
    capacityAllowsSigning,
    capacityVerdict,
    parseCapacity,
    toRawUnits,
    unreadableCapacity,
} from '@/lib/bridgeCapacity';

/**
 * What the bridge wizard is allowed to conclude before it opens a wallet.
 *
 * The server is the gate — it reserves capacity under a lock and refuses there.
 * But the interface decides whether somebody is walked into a signature, and
 * it used to do that on `available: null`, which meant BOTH "the relayer mints
 * here, no ceiling" and "the read failed". Under the second one the wizard
 * behaved exactly as it did under the first, and bridge request #68 walked
 * straight through it.
 *
 * Every case below is one way the screen can be wrong about a number.
 */

const available = (raw, decimals, human = null) => ({
    state: 'available',
    available: human,
    availableRaw: raw,
    decimals,
    reason: null,
});

test('a capacity that is still loading blocks the way to a signature', () => {
    assert.equal(capacityVerdict(LOADING_CAPACITY, '1'), 'loading');
    assert.equal(capacityAllowsSigning(LOADING_CAPACITY, '1'), false);
});

test('a failed read blocks, and is a different answer from "still loading"', () => {
    const failed = unreadableCapacity('rpc down');

    assert.equal(capacityVerdict(failed, '1'), 'unavailable');
    assert.equal(capacityAllowsSigning(failed, '1'), false);

    // Both stop, but they are different sentences to the person waiting.
    assert.notEqual(
        capacityVerdict(failed, '1'),
        capacityVerdict(LOADING_CAPACITY, '1'),
    );
});

test('a genuinely unlimited destination allows any amount', () => {
    const unlimited = parseCapacity({ state: 'unlimited', available: null });

    assert.equal(unlimited.state, 'unlimited');
    assert.equal(capacityAllowsSigning(unlimited, '1000000'), true);
});

test('a chain whose reserves are held by hand permits without claiming a number', () => {
    const unmeasured = parseCapacity({
        state: 'unmeasured',
        available: null,
        reason: 'manual reserves',
    });

    assert.equal(capacityAllowsSigning(unmeasured, '5'), true);
    assert.equal(unmeasured.available, null);
});

test('an amount over a known ceiling is refused, and exactly the ceiling is not', () => {
    const capacity = available('1000000000', 9); // 1.0 SOL

    assert.equal(capacityVerdict(capacity, '1'), 'ok');
    assert.equal(capacityVerdict(capacity, '0.999999999'), 'ok');
    assert.equal(capacityVerdict(capacity, '1.000000001'), 'exceeded');
    assert.equal(capacityVerdict(capacity, '2'), 'exceeded');
});

test('the comparison is exact at 6, 9 and 18 decimals', () => {
    // A double cannot be trusted at the boundary, which is the only place this
    // decision is ever made.
    assert.equal(toRawUnits('0.492836888', 9), 492836888n);
    assert.equal(toRawUnits('1', 6), 1000000n);
    assert.equal(toRawUnits('0.1', 18), 100000000000000000n);

    // Excess precision truncates down, matching TokenAmount::toRaw — never up,
    // which would ask the relayer for a unit that was not typed.
    assert.equal(toRawUnits('1.9999999999', 6), 1999999n);

    // The incident's numbers: 97870923 lamports against a 492836888 payout.
    const hotWallet = available('97870923', 9);
    assert.equal(capacityVerdict(hotWallet, '0.492836888'), 'exceeded');

    // 18-dec, one wei over.
    const wrapper = available('1000000000000000000', 18);
    assert.equal(capacityVerdict(wrapper, '1'), 'ok');
    assert.equal(capacityVerdict(wrapper, '1.000000000000000001'), 'exceeded');
});

test('an empty or nonsense amount is idle, not an approval', () => {
    const capacity = available('1000000000', 9);

    for (const amount of ['', '.', '0', 'abc', '-1']) {
        assert.notEqual(
            capacityVerdict(capacity, amount),
            'ok',
            `"${amount}" must not read as a deliverable amount`,
        );
    }
});

test('a malformed capacity payload is unavailable, never an open door', () => {
    for (const payload of [
        null,
        undefined,
        'unlimited',
        {},
        { state: 'whatever' },
        // The dangerous one: says available, carries no number.
        { state: 'available', available: '5.0' },
        { state: 'available', available_raw: 'lots', decimals: 9 },
        { state: 'available', available_raw: '5000000', decimals: -1 },
    ]) {
        const parsed = parseCapacity(payload);

        assert.equal(
            parsed.state,
            'unavailable',
            `${JSON.stringify(payload)} must parse as unavailable`,
        );
        assert.equal(capacityAllowsSigning(parsed, '1'), false);
    }
});

test('a well-formed available payload keeps its raw number and decimals', () => {
    const parsed = parseCapacity({
        state: 'available',
        available: '5.000000',
        available_raw: '5000000',
        decimals: 6,
        reason: null,
    });

    assert.equal(parsed.state, 'available');
    assert.equal(parsed.availableRaw, '5000000');
    assert.equal(parsed.decimals, 6);
    assert.equal(capacityAllowsSigning(parsed, '5'), true);
    assert.equal(capacityAllowsSigning(parsed, '5.000001'), false);
});

test('a stale ceiling cannot be re-read as unlimited', () => {
    // The shape of the old bug: a screen holding a number, then a refresh that
    // fails. The refreshed state must block, not revert to "no limit".
    let capacity = available('1000000', 6);
    assert.equal(capacityAllowsSigning(capacity, '1'), true);

    capacity = unreadableCapacity('capacity request failed');
    assert.equal(capacityAllowsSigning(capacity, '1'), false);
});
