<?php

use App\Models\User;
use App\Models\UserStat;
use App\Models\XpEntry;
use App\Services\GamificationService;

/**
 * Taking back XP that nothing pays for any more.
 *
 * XP became a currency, so what is in circulation has to mean one thing —
 * otherwise the first purchases here were partly bought with points somebody
 * got for loading a URL. The destructive half is opt-in and the dry run has to
 * be honest about what it would do, because levels fall.
 */
function ledgerEntry(User $user, string $source, int $amount): void
{
    XpEntry::query()->create([
        'user_id' => $user->id,
        'source' => $source,
        'reference' => $source.':'.uniqid('', true),
        'amount' => $amount,
        'created_at' => now(),
    ]);
}

function withStats(User $user, int $xp): void
{
    UserStat::query()->create([
        'user_id' => $user->id,
        'xp' => $xp,
        'level' => app(GamificationService::class)->levelFor($xp),
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_active_on' => now(),
    ]);
}

it('reports without writing on a dry run', function () {
    $user = User::factory()->create();
    ledgerEntry($user, 'swap', 400);
    ledgerEntry($user, 'visit', 100);
    withStats($user, 500);

    $this->artisan('gamification:recount --prune --dry-run')
        ->expectsOutputToContain('Would delete')
        // The projection has to be the total the delete would leave, not the
        // one that is there while the rows still exist.
        ->expectsOutputToContain('500     → 400')
        ->assertSuccessful();

    expect(XpEntry::where('user_id', $user->id)->count())->toBe(2)
        ->and(UserStat::where('user_id', $user->id)->value('xp'))->toBe(500);
});

it('projects the fall from a deleted quest too, not just from a dead source', function () {
    $user = User::factory()->create();
    ledgerEntry($user, 'swap', 400);
    XpEntry::query()->create([
        'user_id' => $user->id, 'source' => 'quest',
        'reference' => 'daily_explore:2026-09-01', 'amount' => 100, 'created_at' => now(),
    ]);
    withStats($user, 500);

    $this->artisan('gamification:recount --prune --dry-run')
        ->expectsOutputToContain('500     → 400')
        ->assertSuccessful();
});

it('drops what no longer pays and rebuilds the total', function () {
    $user = User::factory()->create();
    ledgerEntry($user, 'swap', 400);
    ledgerEntry($user, 'visit', 100);
    ledgerEntry($user, 'streak', 50);
    ledgerEntry($user, 'comment', 15);
    withStats($user, 565);

    $this->artisan('gamification:recount --prune')->assertSuccessful();

    expect(XpEntry::where('user_id', $user->id)->pluck('source')->all())->toBe(['swap'])
        ->and(UserStat::where('user_id', $user->id)->value('xp'))->toBe(400);
});

it('keeps a bonus for a quest that still exists', function () {
    $user = User::factory()->create();
    ledgerEntry($user, 'swap', 400);
    XpEntry::query()->create([
        'user_id' => $user->id, 'source' => 'quest',
        'reference' => 'daily_trade:2026-09-01', 'amount' => 40, 'created_at' => now(),
    ]);
    withStats($user, 440);

    $this->artisan('gamification:recount --prune')->assertSuccessful();

    expect(UserStat::where('user_id', $user->id)->value('xp'))->toBe(440);
});

it('drops a bonus for a quest that is gone', function () {
    $user = User::factory()->create();
    ledgerEntry($user, 'swap', 400);
    // 20 XP for opening three pages. Its source is still called `quest`, so
    // only the reference tells it apart from a bonus somebody earned.
    XpEntry::query()->create([
        'user_id' => $user->id, 'source' => 'quest',
        'reference' => 'daily_explore:2026-09-01', 'amount' => 20, 'created_at' => now(),
    ]);
    withStats($user, 420);

    $this->artisan('gamification:recount --prune')->assertSuccessful();

    expect(UserStat::where('user_id', $user->id)->value('xp'))->toBe(400);
});

it('repairs a running total that drifted from its ledger', function () {
    $user = User::factory()->create();
    ledgerEntry($user, 'swap', 400);
    withStats($user, 9999);

    // No --prune: recomputing the cached total against the ledger is worth
    // doing on its own, because a running total nobody checks drifts.
    $this->artisan('gamification:recount')->assertSuccessful();

    expect(UserStat::where('user_id', $user->id)->value('xp'))->toBe(400);
});

it('lowers the level when the total falls', function () {
    $user = User::factory()->create();
    ledgerEntry($user, 'swap', 100);
    ledgerEntry($user, 'visit', 900);
    withStats($user, 1000);

    expect(UserStat::where('user_id', $user->id)->value('level'))->toBe(5);

    $this->artisan('gamification:recount --prune')->assertSuccessful();

    expect(UserStat::where('user_id', $user->id)->value('level'))->toBe(2);
});

it('leaves an account whose xp was all earned alone', function () {
    $user = User::factory()->create();
    ledgerEntry($user, 'bridge', 900);
    withStats($user, 900);

    $this->artisan('gamification:recount --prune')
        ->expectsOutputToContain('0 of 1 accounts moved')
        ->assertSuccessful();
});
