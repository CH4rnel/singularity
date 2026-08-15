<?php

use App\Services\Predictions\PredictionMarketReader;
use App\Services\Predictions\PredictionsResolver;

/**
 * What the oracle decides, decided without a chain.
 *
 * `plan()` takes markets, quotes and a clock as arguments precisely so this
 * file can exist: every branch that moves money is reachable here, including
 * the ones that are supposed to move none. The signing half is deliberately
 * not exercised — it has no decisions in it.
 */
const WINDOW = 30 * 86400;

const GRACE = 3 * 86400;

/** Closed an hour ago, which is when a market becomes the oracle's problem. */
function market(array $overrides = []): array
{
    return [
        'id' => 7,
        'question' => 'Will SOL be above $80 on 2026-09-01? [px:SOL>80@coingecko]',
        'closeTime' => 1_000_000,
        'outcome' => 0,
        'yesPool' => '1000000000000000000',
        'noPool' => '3000000000000000000',
        ...$overrides,
    ];
}

function plan(array $markets, array $prices, int $now, int $grace = GRACE): array
{
    return app(PredictionsResolver::class)->plan($markets, $prices, $now, WINDOW, $grace);
}

it('leaves a market alone until it closes', function () {
    $result = plan([market()], ['solana' => 92.0], 999_000);

    expect($result['resolve'])->toBe([])
        ->and($result['pending'])->toBe([])
        ->and($result['expired'])->toBe([]);
});

it('leaves a market alone once somebody answered it', function () {
    $result = plan([market(['outcome' => 1])], ['solana' => 92.0], 1_003_600);

    expect($result['resolve'])->toBe([])->and($result['pending'])->toBe([]);
});

it('settles a closed price market from the live quote', function () {
    $yes = plan([market()], ['solana' => 92.5], 1_003_600);

    expect($yes['resolve'])->toHaveCount(1)
        ->and($yes['resolve'][0]['id'])->toBe(7)
        ->and($yes['resolve'][0]['outcome'])->toBe(PredictionsResolver::OUTCOME_YES)
        ->and($yes['resolve'][0]['reason'])->toContain('SOL $92.5 > $80')
        // Pot is both sides together, in wei.
        ->and($yes['resolve'][0]['pot'])->toBe('4000000000000000000');

    $no = plan([market()], ['solana' => 12.0], 1_003_600);

    expect($no['resolve'][0]['outcome'])->toBe(PredictionsResolver::OUTCOME_NO);
});

it('treats the threshold as strict, exactly as the question says', function () {
    // "above $80" at exactly $80 is not above it. This is the single most
    // arguable case in the whole format, so it is pinned rather than assumed.
    $result = plan([market()], ['solana' => 80.0], 1_003_600);

    expect($result['resolve'][0]['outcome'])->toBe(PredictionsResolver::OUTCOME_NO);

    $below = plan(
        [market(['question' => 'Will SOL be below $80 on 2026-09-01? [px:SOL<80@coingecko]'])],
        ['solana' => 80.0],
        1_003_600,
    );

    expect($below['resolve'][0]['outcome'])->toBe(PredictionsResolver::OUTCOME_NO);
});

it('waits rather than guessing when the feed has no answer', function () {
    foreach ([null, 0.0, 'n/a'] as $broken) {
        $result = plan([market()], ['solana' => $broken], 1_003_600);

        expect($result['resolve'])->toBe([])
            ->and($result['pending'])->toHaveCount(1)
            ->and($result['pending'][0]['reason'])->toBe('no coingecko quote for SOL')
            ->and($result['pending'][0]['deadline'])->toBe(1_000_000 + WINDOW);
    }
});

it('sends a free-form question to a human', function () {
    $result = plan([market(['question' => 'Solana > $80'])], ['solana' => 92.0], 1_003_600);

    expect($result['resolve'])->toBe([])
        ->and($result['pending'][0]['reason'])->toBe('free-form question, needs a human');
});

it('cancels an unanswered market before the refund window shuts it', function () {
    $deadline = 1_000_000 + WINDOW;

    // A day before the cancel point it is still somebody's to answer.
    $early = plan([market(['question' => 'Solana > $80'])], [], $deadline - GRACE - 86400);
    expect($early['resolve'])->toBe([])->and($early['pending'])->toHaveCount(1);

    // Inside the grace period it is cancelled, which refunds both sides whole.
    $late = plan([market(['question' => 'Solana > $80'])], [], $deadline - GRACE + 60);

    expect($late['pending'])->toBe([])
        ->and($late['resolve'])->toHaveCount(1)
        ->and($late['resolve'][0]['outcome'])->toBe(PredictionsResolver::OUTCOME_INVALID)
        ->and($late['resolve'][0]['reason'])->toContain('free-form question');
});

it('never cancels when the safety net is switched off', function () {
    $deadline = 1_000_000 + WINDOW;
    $result = plan([market(['question' => 'Solana > $80'])], [], $deadline - 60, -1);

    expect($result['resolve'])->toBe([])->and($result['pending'])->toHaveCount(1);
});

it('reports a lapsed market instead of sending a transaction that reverts', function () {
    // Past the window the contract refuses every outcome, including Invalid.
    // This is the state both live markets are in, and the only honest thing
    // left to do with them is say so.
    $result = plan([market(['question' => 'Solana > $80'])], [], 1_000_000 + WINDOW + 1);

    expect($result['resolve'])->toBe([])
        ->and($result['pending'])->toBe([])
        ->and($result['expired'])->toHaveCount(1)
        ->and($result['expired'][0]['id'])->toBe(7);
});

it('decodes the struct array the live contract actually returns', function () {
    // Captured from rpc.cyberia.church: getMarkets(0, 100) against the deployed
    // PredictionMarket. Hand-rolled ABI decoding is exactly the kind of code
    // that passes on invented input and fails on real payloads, so the input
    // here is a real payload.
    $hex = trim(file_get_contents(base_path('tests/Fixtures/prediction-markets.hex')));
    $markets = PredictionMarketReader::decodeMarkets($hex);

    expect($markets)->toHaveCount(2);

    expect($markets[0]['id'])->toBe(0)
        ->and($markets[0]['question'])->toBe('CYBER.sol Marketcap > $40k')
        ->and($markets[0]['closeTime'])->toBe(1783948260)
        ->and($markets[0]['outcome'])->toBe(0)
        ->and($markets[0]['yesPool'])->toBe('0');

    // The second market has different-length prose and non-zero pools, so the
    // per-element offsets are doing real work rather than a fixed stride.
    expect($markets[1]['id'])->toBe(1)
        ->and($markets[1]['question'])->toBe('Solana > $80')
        ->and($markets[1]['creator'])->toBe('0xaff26832db3557daf540b0b09dee06c24b8a38bb')
        ->and($markets[1]['yesPool'])->toBe('1000000000000000000')
        ->and($markets[1]['noPool'])->toBe('1000000000000000000');
});

it('reads an empty market list as empty, and junk as unreadable', function () {
    $empty = '0x'.str_pad('20', 64, '0', STR_PAD_LEFT).str_repeat('0', 64);

    expect(PredictionMarketReader::decodeMarkets($empty))->toBe([]);
    expect(PredictionMarketReader::decodeMarkets('0x'))->toBeNull();
});
