<?php

use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\EventTaxonomy;
use App\Services\Analytics\ProductMetricsService;
use Illuminate\Support\Carbon;

/**
 * The two ways this dashboard was lying to the people reading it.
 *
 * Both were found in live data rather than reasoned about: sixty-eight of the
 * seventy swaps ever recorded belonged to the two operators, and the entire
 * swap volume figure was one test trade through a dust pool that reported
 * $67,548 at 99.98% price impact. Neither is arithmetic going wrong. Both are
 * a number answering a different question from the one on its label.
 */
function outsiders(int $days = 30): AnalyticsFilters
{
    return new AnalyticsFilters(
        from: Carbon::now('UTC')->subDays($days)->startOfDay(),
        to: Carbon::now('UTC'),
    );
}

function everyone(int $days = 30): AnalyticsFilters
{
    return new AnalyticsFilters(
        from: Carbon::now('UTC')->subDays($days)->startOfDay(),
        to: Carbon::now('UTC'),
        includeInternal: true,
    );
}

/* -------------------------------------------------------------- ours -- */

test('our own installations are out of every headline by default', function () {
    $now = Carbon::now('UTC');

    $stranger = installation(['funded_at' => $now->copy()->subDays(3)]);
    logEvent($stranger, 'swap_completed', $now->copy()->subDay());

    $ours = installation([
        'funded_at' => $now->copy()->subDays(3),
        'internal_at' => $now->copy()->subDays(3),
        'internal_reason' => 'address',
    ]);
    logEvent($ours, 'swap_completed', $now->copy()->subDay());

    $overview = app(ProductMetricsService::class)->overview(outsiders());

    expect($overview['new_users'])->toBe(1)
        ->and($overview['funded_users'])->toBe(1)
        ->and($overview['north_star'])->toBe(1)
        // The exclusion is reported, not merely applied: a total that got
        // quietly smaller is the other way to lie with a dashboard.
        ->and($overview['internal_users'])->toBe(1)
        ->and($overview['internal_included'])->toBeFalse();
});

test('asking for our own data puts it back', function () {
    $now = Carbon::now('UTC');

    installation(['funded_at' => $now->copy()->subDays(3)]);
    $ours = installation([
        'funded_at' => $now->copy()->subDays(3),
        'internal_at' => $now,
        'internal_reason' => 'manual',
    ]);
    logEvent($ours, 'swap_completed', $now->copy()->subDay());

    $overview = app(ProductMetricsService::class)->overview(everyone());

    expect($overview['new_users'])->toBe(2)
        ->and($overview['north_star'])->toBe(1)
        ->and($overview['internal_included'])->toBeTrue();
});

test('an internal installation cannot activate the funnel it is excluded from', function () {
    $now = Carbon::now('UTC');

    $ours = installation([
        'internal_at' => $now,
        'activated_at' => $now,
        'activation_event' => 'swap_completed',
    ]);
    logEvent($ours, 'swap_completed', $now->copy()->subHour());

    $overview = app(ProductMetricsService::class)->overview(outsiders());

    // Every number, not just the population one: the event stream is narrowed
    // to the same population, or the rates come out over the wrong base.
    expect($overview['activated_users'])->toBe(0)
        ->and($overview['dau'])->toBe(0)
        ->and($overview['swap_volume_usd'])->toBe(0.0);
});

/* --------------------------------------------------------- notional -- */

test('a trade its own price impact refutes is kept out of volume', function () {
    $now = Carbon::now('UTC');

    $user = installation();

    // The real one from production: a dust pool being emptied.
    logEvent($user, 'swap_completed', $now->copy()->subDay(), [
        'amount_usd' => 67548.63,
        'price_impact' => 99.98,
    ]);

    // An ordinary trade, which is what volume is supposed to mean.
    logEvent($user, 'swap_completed', $now->copy()->subDay(), [
        'amount_usd' => 120.5,
        'price_impact' => 0.4,
    ]);

    $overview = app(ProductMetricsService::class)->overview(outsiders());

    expect($overview['swap_volume_usd'])->toBe(120.5)
        ->and($overview['volume_excluded'])->toBe(1)
        ->and($overview['volume_excluded_usd'])->toBe(67548.63);
});

test('an event that never measured price impact keeps its amount', function () {
    $now = Carbon::now('UTC');

    $user = installation();

    // A bridge has no price impact to report — it is not a trade against a
    // pool — and must not be dropped for failing to report one.
    logEvent($user, 'bridge_completed', $now->copy()->subDay(), [
        'amount_usd' => 42.0,
    ]);

    $overview = app(ProductMetricsService::class)->overview(outsiders());

    expect($overview['bridge_volume_usd'])->toBe(42.0)
        ->and($overview['volume_excluded'])->toBe(0);
});

test('an excluded trade still counts as an action', function () {
    $now = Carbon::now('UTC');

    $user = installation(['funded_at' => $now->copy()->subDays(2)]);
    logEvent($user, 'swap_completed', $now->copy()->subDay(), [
        'amount_usd' => 67548.63,
        'price_impact' => 99.98,
    ]);

    // Only the dollar figure was disbelieved. The trade happened, it settled,
    // and the person who made it is a weekly active funded user.
    expect(app(ProductMetricsService::class)->overview(outsiders())['north_star'])
        ->toBe(1);
});

/* ------------------------------------------------------ proves wallet -- */

test('every event that proves a wallet is a real event', function () {
    foreach (EventTaxonomy::PROVES_WALLET as $event) {
        expect(EventTaxonomy::isKnown($event))->toBeTrue(
            "{$event} proves a wallet but is not in the taxonomy",
        );
    }
});

test('opening a screen does not prove a wallet', function () {
    // A visitor has these without owning anything. If any of them counted,
    // the onboarding step would fill up with people who never made a vault —
    // which is the failure this whole repair exists to undo, in reverse.
    foreach (['app_opened', 'first_open', 'session_started', 'swap_opened', 'bridge_opened', 'staking_opened', 'swap_quote_requested'] as $event) {
        expect(EventTaxonomy::provesWallet($event))->toBeFalse(
            "{$event} must not stamp the onboarding milestone",
        );
    }
});
