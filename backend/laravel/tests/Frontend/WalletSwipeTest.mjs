import assert from 'node:assert/strict';
import test from 'node:test';
import {
    SWIPE_EDGE_PX,
    SWIPE_MAX_MS,
    SWIPE_MIN_PX,
    swipeIntent,
} from '@/lib/wallet/swipe';

/**
 * When a drag is a navigation.
 *
 * The thresholds are the feature: too loose and the wallet changes screens
 * while somebody scrolls a list of their own money, too tight and the gesture
 * is one nobody can perform. Each test below is one of the four ways a finger
 * can mean nothing.
 */

const WIDTH = 390;

const drag = (from, to, ms = 200, y = 0, toY = 0) =>
    swipeIntent({ x: from, y, at: 0 }, { x: to, y: toY, at: ms }, WIDTH);

test('a flick across the screen is back or forward, by direction', () => {
    // Left to right is how every phone says "back".
    assert.equal(drag(120, 260), 'back');
    assert.equal(drag(260, 120), 'forward');
});

test('a short drag is a tap that wobbled', () => {
    assert.equal(drag(180, 180 + SWIPE_MIN_PX - 1), null);
    assert.equal(drag(180, 180 + SWIPE_MIN_PX + 1), 'back');
});

test('a diagonal belongs to the list being scrolled', () => {
    // 80 across and 60 down: sideways, but not by enough to be meant.
    assert.equal(drag(150, 230, 200, 0, 60), null);
    // The same distance across with a tenth of the wander is a swipe.
    assert.equal(drag(150, 230, 200, 0, 6), 'back');
});

test('a finger resting on the screen never moves it', () => {
    assert.equal(drag(120, 300, SWIPE_MAX_MS + 1), null);
    assert.equal(drag(120, 300, SWIPE_MAX_MS - 1), 'back');
});

test('the edges belong to the operating system', () => {
    // iOS reads a swipe from the left edge as its own back gesture, and two
    // different screens answering one movement is worse than no gesture.
    assert.equal(drag(SWIPE_EDGE_PX - 1, 200), null);
    assert.equal(drag(WIDTH - SWIPE_EDGE_PX + 1, 200), null);
    assert.equal(drag(SWIPE_EDGE_PX + 1, 200), 'back');
});
