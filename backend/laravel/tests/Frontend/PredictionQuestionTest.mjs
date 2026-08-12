import assert from 'node:assert/strict';
import test from 'node:test';
import {
    PREDICTION_ASSETS,
    buildPriceQuestion,
    canonicalAmount,
    isAutoResolved,
    parsePriceQuestion,
    questionProse,
} from '@/lib/predictions';

/**
 * The question format, pinned on the browser side.
 *
 * A market question is written here and read by the server, and between the
 * two there is no handshake and no second chance: the string is on-chain, the
 * market is already taking bets, and a resolver that cannot parse what the
 * form produced leaves the money to the refund window. So the vectors below
 * are duplicated verbatim in tests/Feature/Predictions/PredictionQuestionTest
 * .php, and both files have to be changed together or one of them goes red.
 */

/** 2026-09-01T00:00:00Z — fixed so the prose half is reproducible. */
const CLOSE = 1788220800;

test('builds the canonical string for a price question', () => {
    assert.equal(
        buildPriceQuestion({
            symbol: 'SOL',
            above: true,
            threshold: '80',
            closeAt: CLOSE,
        }),
        'Will SOL be above $80 on 2026-09-01? [px:SOL>80@coingecko]',
    );

    assert.equal(
        buildPriceQuestion({
            symbol: 'CYBER',
            above: false,
            threshold: '0.05',
            closeAt: CLOSE,
        }),
        'Will CYBER be below $0.05 on 2026-09-01? [px:CYBER<0.05@dexscreener]',
    );
});

test('spellings of the same threshold produce the same bytes', () => {
    const canonical = buildPriceQuestion({
        symbol: 'BTC',
        above: true,
        threshold: '80.5',
        closeAt: CLOSE,
    });

    for (const spelling of ['080.500', '80.50', ' 80.5 ']) {
        assert.equal(
            buildPriceQuestion({
                symbol: 'BTC',
                above: true,
                threshold: spelling,
                closeAt: CLOSE,
            }),
            canonical,
        );
    }
});

test('canonicalAmount normalises without going through a float', () => {
    assert.equal(canonicalAmount('80'), '80');
    assert.equal(canonicalAmount('080.500'), '80.5');
    assert.equal(canonicalAmount('0.010'), '0.01');
    assert.equal(canonicalAmount('.5'), '0.5');
    assert.equal(canonicalAmount('5.'), '5');

    // Not a positive decimal, so not a threshold.
    for (const bad of ['', '.', '0', '0.000', 'abc', '-1', '1e3', '1,5']) {
        assert.equal(canonicalAmount(bad), null, `expected null for "${bad}"`);
    }

    // More precision than the format carries is refused rather than rounded:
    // rounding here would silently move the threshold someone bet against.
    assert.equal(canonicalAmount('1.123456789'), null);
    assert.equal(canonicalAmount('1.12345678'), '1.12345678');
});

test('round-trips through the parser', () => {
    for (const asset of PREDICTION_ASSETS) {
        for (const above of [true, false]) {
            const question = buildPriceQuestion({
                symbol: asset.symbol,
                above,
                threshold: '12.25',
                closeAt: CLOSE,
            });

            assert.deepEqual(parsePriceQuestion(question), {
                symbol: asset.symbol,
                above,
                threshold: '12.25',
                source: asset.source,
            });
        }
    }
});

test('refuses to read a tag it cannot fully vouch for', () => {
    const cases = [
        // No tag at all — a human question, which is a valid market.
        'Will it rain on 2026-09-01?',
        // An asset this build cannot price.
        'Will DOGE be above $1? [px:DOGE>1@coingecko]',
        // The right asset off the wrong feed: CYBER is not on CoinGecko, and
        // honouring this would settle from a source the question never named.
        'Will CYBER be above $1? [px:CYBER>1@coingecko]',
        // Tag is not the last thing in the string, so it is prose about a tag.
        '[px:SOL>80@coingecko] will happen?',
        // Thresholds that are not thresholds.
        'Will SOL be above $0? [px:SOL>0@coingecko]',
        'Will SOL be above $80? [px:SOL>80.123456789@coingecko]',
        // An operator with no stated tie behaviour.
        'Will SOL be at least $80? [px:SOL>=80@coingecko]',
    ];

    for (const question of cases) {
        assert.equal(
            parsePriceQuestion(question),
            null,
            `expected no spec for "${question}"`,
        );
        assert.equal(isAutoResolved(question), false);
    }
});

test('an over-long question is refused, not truncated', () => {
    // The contract caps the question at 200 bytes; a truncated tag would read
    // as a human market that the form believes is automatic.
    assert.equal(
        buildPriceQuestion({
            symbol: 'SOL',
            above: true,
            threshold: '1'.repeat(180),
            closeAt: CLOSE,
        }),
        null,
    );
});

test('prose strips the tag and nothing else', () => {
    assert.equal(
        questionProse('Will SOL be above $80 on 2026-09-01? [px:SOL>80@coingecko]'),
        'Will SOL be above $80 on 2026-09-01?',
    );
    assert.equal(questionProse('Solana > $80'), 'Solana > $80');
});
