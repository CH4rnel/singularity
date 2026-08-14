import assert from 'node:assert/strict';
import test from 'node:test';
import {
    STAKE_GAS_CAP,
    canStake,
    canUnstake,
    earnChainFor,
    hasEarn,
    poolShare,
} from '@/lib/wallet/earn';

/**
 * The parts of farming that decide what gets signed.
 *
 * A stake is a plain ERC-20 deposit, so the arithmetic is small — and that is
 * exactly why it is pinned here: the two numbers on this screen are somebody's
 * whole position, and an off-by-a-share renders a stake as a fraction of
 * itself without anything failing.
 */

const ONE = 1_000_000_000_000_000_000n;

test('a share of a pool is a claim on both reserves', () => {
    const { share, amounts } = poolShare(ONE, ONE * 4n, [
        ONE * 1000n,
        2_000_000_000n,
    ]);

    assert.equal(share, 0.25);
    assert.equal(amounts[0], ONE * 250n);
    // The second side has six decimals, and nothing here knows that or needs
    // to: a quarter of the reserve is a quarter of the reserve.
    assert.equal(amounts[1], 500_000_000n);
});

test('an unfunded pool has no share rather than a division by zero', () => {
    assert.deepEqual(poolShare(ONE, 0n, [0n, 0n]), {
        share: 0,
        amounts: [0n, 0n],
    });
    assert.deepEqual(poolShare(0n, ONE, [ONE, ONE]), {
        share: 0,
        amounts: [0n, 0n],
    });
});

test('a dust position keeps a real share instead of rounding to nothing', () => {
    // A millionth of a large pool: computed as a float division of two bigints
    // this is either zero or Infinity, which is why it goes through integers.
    const { share, amounts } = poolShare(ONE, ONE * 1_000_000n, [
        ONE * 1_000_000n,
        ONE * 2_000_000n,
    ]);

    assert.ok(share > 0);
    assert.equal(amounts[0], ONE);
    assert.equal(amounts[1], ONE * 2n);
});

test('staking refuses in the vocabulary the user can act on', () => {
    assert.equal(canStake(ONE, ONE * 2n), 'ok');
    assert.equal(canStake(0n, ONE), 'empty');
    assert.equal(canStake(ONE * 3n, ONE), 'tooMuch');

    // The whole balance is stakeable — the fee is paid in the coin, not in LP.
    assert.equal(canStake(ONE, ONE), 'ok');
});

test('unstaking tells "nothing staked" apart from "too much"', () => {
    assert.equal(canUnstake(ONE, ONE * 2n), 'ok');
    assert.equal(canUnstake(ONE, 0n), 'nothingStaked');
    assert.equal(canUnstake(ONE * 3n, ONE), 'tooMuch');
    assert.equal(canUnstake(0n, ONE), 'empty');
});

test('the farm registry answers for Cyberia and refuses to guess', () => {
    assert.equal(hasEarn(49406), true);
    assert.equal(hasEarn(1), false);
    assert.equal(hasEarn(undefined), false);

    assert.equal(earnChainFor(49406).chainId, 49406);
    // Never a silent fallback to another chain's chef: staking into the wrong
    // farm is a transfer of LP to a contract that does not know it.
    assert.throws(() => earnChainFor(1));
});

test('the gas ceiling is a promise, not a guess', () => {
    // A MasterChef deposit updates one pool and one user record and pays out
    // the accrued reward on the way — well inside this, and the unused part
    // comes back.
    assert.ok(STAKE_GAS_CAP >= 200_000n);
});
