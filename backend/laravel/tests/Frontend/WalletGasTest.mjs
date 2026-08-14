import assert from 'node:assert/strict';
import test from 'node:test';
import { EVM_CONTRACT_SEND_GAS_CAP, nativeSendGas } from '@/lib/wallet';
import {
    SPONSORED_CHAIN,
    canAskForGas,
    dripCovers,
    sponsorReasonKey,
} from '@/lib/wallet/gas';

/**
 * The pure parts of sponsored fees: when to offer, and when offering would be
 * a lie.
 *
 * Both decisions are made in front of somebody who cannot send anything, which
 * is the worst moment to be wrong in either direction. Offering when the drip
 * would not cover the fee leaves them exactly as stuck, one round trip poorer;
 * not offering when it would leaves the assets in the wallet unmovable for no
 * reason.
 */

const CYBER = 1_000_000_000_000_000_000n;

test('the offer is Cyberia-only, and permanently so', () => {
    assert.equal(SPONSORED_CHAIN, 'cyberia');

    // A fee this wallet cannot pay, on a chain nobody here can sponsor.
    assert.equal(canAskForGas('bnb', CYBER / 1000n, 0n), false);
    assert.equal(canAskForGas('base', CYBER / 1000n, 0n), false);
    assert.equal(canAskForGas('solana', CYBER / 1000n, 0n), false);
    assert.equal(canAskForGas('cyberia', CYBER / 1000n, 0n), true);
});

test('the question is the fee, not the balance', () => {
    const fee = CYBER / 1000n;

    // Enough for the fee: this wallet has no gas problem, whatever else it
    // cannot afford.
    assert.equal(canAskForGas('cyberia', fee, fee), false);
    assert.equal(canAskForGas('cyberia', fee, fee * 100n), false);
    assert.equal(canAskForGas('cyberia', fee, fee - 1n), true);
});

test('a fee that has not been quoted is not a shortfall', () => {
    assert.equal(canAskForGas('cyberia', null, 0n), false);
    assert.equal(canAskForGas('cyberia', CYBER, null), false);
});

test('a drip is only offered when it would actually cover the fee', () => {
    const drip = (CYBER / 100n).toString(); // 0.01 CYBER, the default

    // An ordinary transfer or swap, comfortably inside one drip.
    assert.equal(dripCovers(drip, CYBER / 1000n, 0n), true);

    // A fee larger than the whole drip: saying yes here would leave the user
    // stuck after a transaction was spent on them.
    assert.equal(dripCovers(drip, CYBER, 0n), false);

    // What is already in the account counts towards it.
    assert.equal(dripCovers(drip, CYBER / 50n, CYBER / 100n), true);
});

test('a missing or malformed drip is never assumed to be enough', () => {
    assert.equal(dripCovers(null, CYBER / 1000n, 0n), false);
    assert.equal(dripCovers(undefined, CYBER / 1000n, 0n), false);
    assert.equal(dripCovers('not a number', CYBER / 1000n, 0n), false);
    assert.equal(dripCovers('10000000000000000', null, 0n), false);
});

/**
 * The regression the gas station itself found: the first CYBER ever sent to it
 * was signed for 21000, ran out of gas inside `receive()`, reverted and kept
 * the fee. 21000 is the cost of paying an address that is only an address, and
 * the cost of nothing else — and this chain's `eth_estimateGas` answers 21000
 * for a contract recipient too, so the recipient is read rather than estimated.
 */
test('paying a contract is never signed for a plain transfer', () => {
    assert.equal(nativeSendGas(false), 21_000n);
    assert.equal(nativeSendGas(true), EVM_CONTRACT_SEND_GAS_CAP);
    assert.ok(nativeSendGas(true) > 21_000n);
});

test('the contract limit is generous, because unused gas comes back', () => {
    // A bare payable receive costs a few thousand; the cap is far above every
    // shape of it, and the excess is refunded rather than spent.
    assert.ok(EVM_CONTRACT_SEND_GAS_CAP >= 100_000n);
    assert.equal(nativeSendGas(true, 60_000n), 60_000n);
});

test('every refusal maps to its own message key', () => {
    assert.equal(sponsorReasonKey('holdsNothing'), 'sponsorHoldsNothing');
    assert.equal(sponsorReasonKey('coolingDown'), 'sponsorCoolingDown');
    assert.equal(sponsorReasonKey('empty'), 'sponsorEmpty');
    assert.equal(sponsorReasonKey('dailyCap'), 'sponsorDailyCap');
    assert.equal(sponsorReasonKey('quota'), 'sponsorQuota');
    assert.equal(sponsorReasonKey('unreadable'), 'sponsorUnreadable');
});
