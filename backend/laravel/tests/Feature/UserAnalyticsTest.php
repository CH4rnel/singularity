<?php

use App\Models\User;
use App\Services\GamificationService;
use App\Services\UserAnalyticsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    $this->analytics = app(UserAnalyticsService::class);
});

/**
 * Record a device visiting on each of the given dates. Inserted directly
 * because site_events does not mass-assign created_at — the model stamps it,
 * which is exactly what backdated fixtures must avoid.
 */
function visits(string $session, array $dates): void
{
    foreach ($dates as $date) {
        DB::table('site_events')->insert([
            'session_id' => $session,
            'event' => 'page_view',
            'page' => '/swap',
            'created_at' => Carbon::parse($date, 'UTC')->toDateTimeString(),
        ]);
    }
}

it('counts active devices over the usual windows', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    visits((string) Str::uuid(), ['2026-07-29 09:00']);
    visits((string) Str::uuid(), ['2026-07-26 09:00']);
    visits((string) Str::uuid(), ['2026-07-10 09:00']);

    $activity = $this->analytics->activity();

    expect($activity['dau'])->toBe(1)
        ->and($activity['wau'])->toBe(2)
        ->and($activity['mau'])->toBe(3)
        ->and($activity['stickiness'])->toBe(33.3);
});

it('separates devices first seen inside the window from returning ones', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    // First seen well before the 7-day window, back during it.
    visits('11111111-1111-4111-8111-111111111111', ['2026-06-01 09:00', '2026-07-28 09:00']);
    // Brand new.
    visits('22222222-2222-4222-8222-222222222222', ['2026-07-28 10:00']);

    expect($this->analytics->newVsReturning(7))->toBe(['new' => 1, 'returning' => 1]);
});

it('reports weekly cohorts and withholds rates that are too young', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    // Week of 2026-07-06: one device returns the next day, one never does.
    visits('33333333-3333-4333-8333-333333333333', ['2026-07-06 09:00', '2026-07-07 09:00']);
    visits('44444444-4444-4444-8444-444444444444', ['2026-07-06 10:00']);

    $cohort = collect($this->analytics->cohorts())->firstWhere('week', '2026-07-06');

    expect($cohort['size'])->toBe(2)
        ->and($cohort['rates']['d1'])->toBe(50.0)
        // 6 + 30 days after the cohort week opened is still in the future.
        ->and($cohort['rates']['d30'])->toBeNull();
});

it('summarises progression health', function () {
    $gamification = app(GamificationService::class);
    $user = User::factory()->create();

    $gamification->award($user, 'swap', 'a', 350);
    $gamification->touch($user);

    $progression = $this->analytics->progression();

    expect($progression['tracked'])->toBe(1)
        ->and($progression['with_xp'])->toBe(1)
        ->and($progression['live_streaks'])->toBe(1)
        ->and($this->analytics->topMembers())->toHaveCount(1);
});

it('serves a public leaderboard page', function () {
    $user = User::factory()->create();
    app(GamificationService::class)->award($user, 'swap', 'a', 120);

    $this->get('/leaderboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Leaderboard')
            ->has('rows', 1)
            ->where('rows.0.level', 2)
            ->where('me', null));
});

afterEach(function () {
    Carbon::setTestNow();
});
