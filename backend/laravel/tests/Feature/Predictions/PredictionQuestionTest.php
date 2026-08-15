<?php

use App\Services\Predictions\PredictionQuestion;

/**
 * The question format, pinned on the server side.
 *
 * These are the same vectors as tests/Frontend/PredictionQuestionTest.mjs, on
 * purpose and verbatim. The browser writes the string and this side decides
 * money on it, so the two implementations agreeing is not an implementation
 * detail — it is the whole reason a market created in a browser can be settled
 * by a scheduler. Change one file and the other has to change with it.
 */

/** 2026-09-01T00:00:00Z — fixed so the prose half is reproducible. */
const CLOSE = 1788220800;

it('builds the canonical string for a price question', function () {
    expect(PredictionQuestion::buildPrice('SOL', true, '80', CLOSE))
        ->toBe('Will SOL be above $80 on 2026-09-01? [px:SOL>80@coingecko]');

    expect(PredictionQuestion::buildPrice('CYBER', false, '0.05', CLOSE))
        ->toBe('Will CYBER be below $0.05 on 2026-09-01? [px:CYBER<0.05@dexscreener]');
});

it('produces the same bytes for every spelling of a threshold', function () {
    $canonical = PredictionQuestion::buildPrice('BTC', true, '80.5', CLOSE);

    foreach (['080.500', '80.50', ' 80.5 '] as $spelling) {
        expect(PredictionQuestion::buildPrice('BTC', true, $spelling, CLOSE))
            ->toBe($canonical);
    }
});

it('normalises an amount without going through a float', function () {
    expect(PredictionQuestion::canonicalAmount('80'))->toBe('80');
    expect(PredictionQuestion::canonicalAmount('080.500'))->toBe('80.5');
    expect(PredictionQuestion::canonicalAmount('0.010'))->toBe('0.01');
    expect(PredictionQuestion::canonicalAmount('.5'))->toBe('0.5');
    expect(PredictionQuestion::canonicalAmount('5.'))->toBe('5');

    foreach (['', '.', '0', '0.000', 'abc', '-1', '1e3', '1,5'] as $bad) {
        expect(PredictionQuestion::canonicalAmount($bad))->toBeNull();
    }

    // Refused rather than rounded: rounding would silently move a threshold
    // somebody has already bet against.
    expect(PredictionQuestion::canonicalAmount('1.123456789'))->toBeNull();
    expect(PredictionQuestion::canonicalAmount('1.12345678'))->toBe('1.12345678');
});

it('round-trips every asset through the parser', function () {
    foreach (PredictionQuestion::assets() as $symbol => $asset) {
        foreach ([true, false] as $above) {
            $question = PredictionQuestion::buildPrice($symbol, $above, '12.25', CLOSE);

            expect(PredictionQuestion::parsePrice($question))->toBe([
                'symbol' => $symbol,
                'above' => $above,
                'threshold' => '12.25',
                'source' => $asset['source'],
                'quote' => $asset['quote'],
            ]);
        }
    }
});

it('refuses to read a tag it cannot fully vouch for', function () {
    $cases = [
        'Will it rain on 2026-09-01?',
        'Will DOGE be above $1? [px:DOGE>1@coingecko]',
        'Will CYBER be above $1? [px:CYBER>1@coingecko]',
        '[px:SOL>80@coingecko] will happen?',
        'Will SOL be above $0? [px:SOL>0@coingecko]',
        'Will SOL be above $80? [px:SOL>80.123456789@coingecko]',
        'Will SOL be at least $80? [px:SOL>=80@coingecko]',
    ];

    foreach ($cases as $question) {
        expect(PredictionQuestion::parsePrice($question))->toBeNull();
    }
});

it('refuses an over-long question instead of truncating it', function () {
    expect(PredictionQuestion::buildPrice('SOL', true, str_repeat('1', 180), CLOSE))
        ->toBeNull();
});

it('strips the tag and nothing else', function () {
    expect(PredictionQuestion::prose('Will SOL be above $80 on 2026-09-01? [px:SOL>80@coingecko]'))
        ->toBe('Will SOL be above $80 on 2026-09-01?');
    expect(PredictionQuestion::prose('Solana > $80'))->toBe('Solana > $80');
});
