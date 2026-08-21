<?php

use App\Services\Analytics\EventTaxonomy;

/**
 * The taxonomy exists twice — once in PHP and once in TypeScript — and this is
 * the half of the guard that runs in CI here. (`tests/Frontend/
 * AnalyticsTaxonomyTest.mjs` is the other half; between them the two lists can
 * only be changed together.)
 *
 * The duplication is deliberate: the browser copy has to be a TypeScript union
 * so a misspelled event name is a build error rather than a row that silently
 * never appears on a dashboard. Duplication without a pin, though, is just
 * drift with extra steps.
 */
test('the browser and the server agree on every event name', function () {
    $source = file_get_contents(resource_path('js/lib/analytics/taxonomy.ts'));

    preg_match('/export const ANALYTICS_EVENTS[^\[]*\[(.*?)\] as const;/s', $source, $matches);

    preg_match_all("/'([a-z_]+)'/", $matches[1] ?? '', $names);

    expect($names[1])->toBe(EventTaxonomy::EVENTS);
});

test('the browser and the server agree on what counts as meaningful', function () {
    $source = file_get_contents(resource_path('js/lib/analytics/taxonomy.ts'));

    preg_match('/export const MEANINGFUL_EVENTS[^\[]*\[(.*?)\] as const;/s', $source, $matches);

    preg_match_all("/'([a-z_]+)'/", $matches[1] ?? '', $names);

    // This list is the definition of an activated user and of an active user.
    // The two copies disagreeing means the wallet and the dashboard disagree
    // about who the product's users are.
    expect($names[1])->toBe(EventTaxonomy::MEANINGFUL);
});

test('the browser and the server agree on the failure vocabulary', function () {
    $source = file_get_contents(resource_path('js/lib/analytics/taxonomy.ts'));

    preg_match('/export const ANALYTICS_ERROR_CODES = \[(.*?)\] as const;/s', $source, $matches);

    preg_match_all("/'([A-Za-z_]+)'/", $matches[1] ?? '', $names);

    expect($names[1])->toBe(EventTaxonomy::ERROR_CODES);
});

test('the browser and the server allow the same properties', function () {
    $source = file_get_contents(resource_path('js/lib/analytics/taxonomy.ts'));

    preg_match(
        '/export const ANALYTICS_PROPERTY_SHAPES[^{]*\{(.*?)\n    \};/s',
        $source,
        $matches,
    );

    preg_match_all('/^\s{8}(\w+):/m', $matches[1] ?? '', $keys);

    // Order does not matter here — an allowlist is a set — but membership
    // does: a key one side allows and the other strips is a property that
    // silently disappears somewhere between the wallet and the table.
    expect(collect($keys[1])->sort()->values()->all())
        ->toBe(collect(array_keys(EventTaxonomy::PROPERTIES))->sort()->values()->all());
});

test('every event named in a funnel or an outcome actually exists', function () {
    $named = collect(EventTaxonomy::FUNNELS)->flatten()
        ->merge(collect(EventTaxonomy::OUTCOMES)->flatten())
        ->merge(EventTaxonomy::MEANINGFUL)
        ->merge(EventTaxonomy::FAILURES)
        ->unique();

    foreach ($named as $event) {
        expect(EventTaxonomy::isKnown($event))->toBeTrue("unknown event: {$event}");
    }
});

test('a failure event never counts towards a success rate it does not belong to', function () {
    // A quote nobody signed is not a failed swap, and the ratio that says how
    // often swaps work must not contain it.
    $outcomeFailures = collect(EventTaxonomy::OUTCOMES)->pluck('failure')->all();

    expect(in_array('swap_quote_failed', EventTaxonomy::FAILURES, true))->toBeTrue()
        ->and(in_array('swap_quote_failed', $outcomeFailures, true))->toBeFalse();
});

test('numbers are clamped rather than trusted', function () {
    $clean = EventTaxonomy::sanitize([
        'amount_usd' => -5,
        'duration_ms' => 99_999_999,
        'price_impact' => 1e9,
        'hops' => 3.7,
        'slippage' => NAN,
    ]);

    expect($clean['amount_usd'])->toBe(0.0)
        ->and($clean['duration_ms'])->toBe(3_600_000)
        ->and($clean['price_impact'])->toBe(100.0)
        ->and($clean['hops'])->toBe(4)
        // A number that is not a number is dropped, never stored as 0.
        ->and($clean)->not->toHaveKey('slippage');
});

test('an error code outside the vocabulary is refused, not stored', function () {
    // Otherwise the field becomes a place for a raw message to end up.
    expect(EventTaxonomy::sanitize(['error_code' => 'insufficient funds for gas * price + value']))
        ->toBe([]);
});
