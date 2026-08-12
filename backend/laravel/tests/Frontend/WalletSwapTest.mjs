import assert from 'node:assert/strict';
import test from 'node:test';
import { LIQUIDITY_CHAINS } from '@/lib/liquidityChains';
import { applySlippage, priceImpactPct, swapPaths } from '@/lib/wallet/swap';
import { wrapDirection } from '@/lib/wallet/wrap';

/**
 * The parts of trading that decide where the money goes, without a chain.
 *
 * Three of these are the whole difference between a swap that works and one
 * that quietly loses value: which paths get priced at all (a pair with no
 * direct pool is a hop away, not "no liquidity"), what floor is signed into
 * the transaction, and whether a coin-and-its-own-wrapper pair is recognised
 * as a wrap instead of being sent to a router that has no pool for it.
 */

const CYBERIA = LIQUIDITY_CHAINS.find((chain) => chain.chainId === 49406);

const WCYBER = '0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9';
const USDC = '0xdc25597B19799010047F17e9591EFE08EFd40077';
const LAIN = '0x05cd1AFd5b2DF3CCA6cEAb80CbC21168ec981E8B';
const ASH = '0x992Fca0a89DD95afb17751f6CC233Adb9B089df5';

/** LAIN–WCYBER–USDC, plus an unrelated pool nothing routes through. */
const EDGES = [
    [LAIN, WCYBER],
    [WCYBER, USDC],
    [ASH, '0x0000000000000000000000000000000000000dead'],
];

test('a pair with no direct pool still routes, through the token they share', () => {
    const paths = swapPaths(EDGES, LAIN, USDC, CYBERIA.hubs);

    // The direct pair is always tried — the router prices it and it costs one
    // failed call to learn there is no pool.
    assert.deepEqual(paths[0], [LAIN.toLowerCase(), USDC.toLowerCase()]);
    assert.ok(
        paths.some(
            (path) =>
                path.length === 3 &&
                path[1] === WCYBER.toLowerCase() &&
                path[2] === USDC.toLowerCase(),
        ),
        'the WCYBER hop must be among the candidates',
    );
});

test('every path is lowercased, starts at the input and ends at the output', () => {
    for (const path of swapPaths(EDGES, LAIN, USDC, CYBERIA.hubs)) {
        assert.equal(path[0], LAIN.toLowerCase());
        assert.equal(path[path.length - 1], USDC.toLowerCase());
        assert.equal(path.join(''), path.join('').toLowerCase());
        assert.ok(path.length >= 2 && path.length <= 4);
    }
});

test('candidates are unique and capped', () => {
    // The hubs repeat what the graph already found; the same path must not be
    // priced twice, because each candidate is an RPC call.
    const paths = swapPaths(EDGES, LAIN, USDC, [WCYBER, WCYBER, USDC, LAIN]);
    const keys = new Set(paths.map((path) => path.join('>')));

    assert.equal(keys.size, paths.length);
    assert.ok(swapPaths(EDGES, LAIN, USDC, CYBERIA.hubs, 2).length <= 2);
});

test('an asset has no route to itself', () => {
    assert.deepEqual(swapPaths(EDGES, LAIN, LAIN.toLowerCase(), []), []);
});

test('the signed floor is the quote less exactly the accepted slippage', () => {
    assert.equal(applySlippage(1_000_000n, 50), 995_000n);
    assert.equal(applySlippage(1_000_000n, 0), 1_000_000n);
    // Rounding always goes down: a floor rounded up is a floor the pool can
    // miss by a wei, reverting a swap that should have gone through.
    assert.equal(applySlippage(9_999n, 100), 9_899n);
});

test('a slippage outside 0–100% is refused rather than clamped', () => {
    assert.throws(() => applySlippage(1n, -1));
    assert.throws(() => applySlippage(1n, 10_000));
    assert.throws(() => applySlippage(1n, 12.5));
});

test('price impact is the execution rate against the marginal one', () => {
    // Probe: 1 unit pays 100. Trade: 1000 units pay 95_000 — 5% worse.
    const impact = priceImpactPct(1_000n, 95_000n, 1n, 100n);

    assert.ok(impact !== null);
    assert.ok(Math.abs(impact - 5) < 1e-9);

    // A trade at the spot rate moves nothing, and one that prices a hair
    // better than spot is rounding, not a gift.
    assert.equal(priceImpactPct(1_000n, 100_000n, 1n, 100n), 0);
    assert.equal(priceImpactPct(1_000n, 100_001n, 1n, 100n), 0);
});

test('an unreadable probe is no impact figure, not an impact of zero', () => {
    assert.equal(priceImpactPct(1_000n, 95_000n, 1n, 0n), null);
    assert.equal(priceImpactPct(0n, 0n, 1n, 100n), null);
});

test('the coin and its own wrapper are a wrap, in whichever direction', () => {
    assert.equal(wrapDirection(CYBERIA, null, WCYBER), 'wrap');
    assert.equal(wrapDirection(CYBERIA, WCYBER.toLowerCase(), null), 'unwrap');
    // Anything else is a trade: two tokens, or the coin against a token that
    // is not its wrapper.
    assert.equal(wrapDirection(CYBERIA, null, USDC), null);
    assert.equal(wrapDirection(CYBERIA, WCYBER, USDC), null);
    assert.equal(wrapDirection(CYBERIA, null, null), null);
});

test('every DEX chain declares hubs its own router can reach', () => {
    for (const chain of LIQUIDITY_CHAINS) {
        assert.ok(chain.hubs.length > 0, `${chain.chainId} has no hubs`);
        assert.ok(
            chain.hubs.some(
                (hub) =>
                    hub.toLowerCase() === chain.wrappedNative.toLowerCase(),
            ),
            `${chain.chainId} must be able to hop through its wrapped native`,
        );
    }
});
