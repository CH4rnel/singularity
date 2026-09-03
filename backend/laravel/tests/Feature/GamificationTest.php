<?php

use App\Models\Proposal;
use App\Models\User;
use App\Models\UserStat;
use App\Models\XpEntry;
use App\Services\Dao\ActivityRecorder;
use App\Services\GamificationService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->gamification = app(GamificationService::class);
});

it('pays an action only once per reference', function () {
    $user = User::factory()->create();

    $first = $this->gamification->recordAction($user, 'swap', '0xdeadbeef');
    $second = $this->gamification->recordAction($user, 'swap', '0xdeadbeef');

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0)
        ->and(XpEntry::where('user_id', $user->id)->where('source', 'swap')->count())->toBe(1);
});

it('caps a referenceless action at one award per day', function () {
    $user = User::factory()->create();

    $this->gamification->recordAction($user, 'swap');
    $this->gamification->recordAction($user, 'swap');

    expect(XpEntry::where('user_id', $user->id)->where('source', 'swap')->count())->toBe(1);
});

it('extends a streak on consecutive days and restarts it after a gap', function () {
    $user = User::factory()->create();

    $this->gamification->touch($user, Carbon::parse('2026-07-01 10:00', 'UTC'));
    $this->gamification->touch($user, Carbon::parse('2026-07-02 09:00', 'UTC'));
    $this->gamification->touch($user, Carbon::parse('2026-07-03 23:00', 'UTC'));

    expect($this->gamification->statsFor($user)->fresh()->current_streak)->toBe(3);

    // A missed day resets the run but keeps the record.
    $this->gamification->touch($user, Carbon::parse('2026-07-05 08:00', 'UTC'));

    $stats = UserStat::where('user_id', $user->id)->first();

    expect($stats->current_streak)->toBe(1)
        ->and($stats->longest_streak)->toBe(3);
});

it('is idempotent within a single day', function () {
    $user = User::factory()->create();

    $granted = $this->gamification->touch($user, Carbon::parse('2026-07-01 10:00', 'UTC'));
    $again = $this->gamification->touch($user, Carbon::parse('2026-07-01 22:00', 'UTC'));

    expect($granted)->toBeGreaterThan(0)
        ->and($again)->toBe(0)
        ->and($this->gamification->statsFor($user)->fresh()->current_streak)->toBe(1);
});

it('pays a streak milestone bonus once per run', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $day) {
        $this->gamification->touch($user, Carbon::parse('2026-07-0'.$day.' 10:00', 'UTC'));
    }

    expect(XpEntry::where('user_id', $user->id)->where('source', 'streak')->count())->toBe(1);
});

it('levels up as xp accumulates', function () {
    expect($this->gamification->levelFor(0))->toBe(1)
        ->and($this->gamification->levelFor(99))->toBe(1)
        ->and($this->gamification->levelFor(100))->toBe(2)
        ->and($this->gamification->levelFor(300))->toBe(3)
        ->and($this->gamification->xpForLevel(2))->toBe(100);
});

it('completes a daily quest and pays its bonus once', function () {
    $user = User::factory()->create();

    $this->gamification->recordAction($user, 'swap', '0xaaa');
    $this->gamification->recordAction($user, 'swap', '0xbbb');

    $quest = collect($this->gamification->questBoard($user))->firstWhere('key', 'daily_trade');

    expect($quest['completed'])->toBeTrue()
        // Three: a swap is also showing up (daily_visit) and also touching the
        // chain (daily_onchain). The second swap re-runs all of them and pays
        // none of them twice.
        ->and(XpEntry::where('user_id', $user->id)->where('source', 'quest')->count())->toBe(3);
});

it('completes the daily visit quest just by showing up', function () {
    $user = User::factory()->create();

    // Nothing calls recordAction with 'visit' — the site reports page_view.
    $this->gamification->recordAction($user, 'page_view', page: '/wallet');

    expect(
        collect($this->gamification->questBoard($user))->firstWhere('key', 'daily_visit')['completed'],
    )->toBeTrue();
});

it('still completes the visit quest when the day was already marked active', function () {
    $user = User::factory()->create();

    // touch() returns early once the day is stamped, so a quest advanced from
    // there is lost for anybody whose first action of the day happened before
    // it existed — with no way to earn it back until midnight.
    $this->gamification->touch($user);

    expect(
        collect($this->gamification->questBoard($user))->firstWhere('key', 'daily_visit')['completed'],
    )->toBeFalse();

    $this->gamification->recordAction($user, 'page_view', page: '/wallet');

    expect(
        collect($this->gamification->questBoard($user))->firstWhere('key', 'daily_visit')['completed'],
    )->toBeTrue();
});

it('pays the wall, which used to be worth less than opening a page', function () {
    $user = User::factory()->create();

    $this->gamification->recordAction($user, 'post', '42');

    expect(XpEntry::where('user_id', $user->id)->where('source', 'post')->sum('amount'))->toBe(20)
        ->and(
            collect($this->gamification->questBoard($user))->firstWhere('key', 'daily_wall')['completed'],
        )->toBeTrue();
});

it('closes the on-chain quest on any real action, not just a swap', function () {
    $user = User::factory()->create();

    // The quest this replaced could be finished by opening three pages. This
    // one needs something that cost a transaction — and accepts any of them,
    // so most people finish it without going out of their way.
    $this->gamification->recordAction($user, 'staking', '0xstake');

    expect(
        collect($this->gamification->questBoard($user))->firstWhere('key', 'daily_onchain')['completed'],
    )->toBeTrue();
});

it('ranks the leaderboard by xp and skips merged accounts', function () {
    $top = User::factory()->create();
    $mid = User::factory()->create();
    $merged = User::factory()->create();

    $this->gamification->award($top, 'swap', 'a', 500);
    $this->gamification->award($mid, 'swap', 'b', 100);
    $this->gamification->award($merged, 'swap', 'c', 900);
    // merged_into_id is intentionally not fillable (AccountMergeService owns it).
    $merged->forceFill(['merged_into_id' => $top->id])->save();

    $rows = $this->gamification->leaderboard();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['user_id'])->toBe($top->id)
        ->and($rows[0]['position'])->toBe(1)
        ->and($rows[1]['user_id'])->toBe($mid->id)
        ->and($this->gamification->progressFor($mid)['rank'])->toBe(2);
});

it('credits governance activity when a vote is recorded', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    // The recorder is the choke point every governance action goes through.
    $recorder = app(ActivityRecorder::class);
    $proposal = Proposal::factory()->create();
    $recorder->record('vote.cast', $user, $proposal);

    expect(XpEntry::where('user_id', $user->id)->where('source', 'vote')->count())->toBe(1);
});

/**
 * A streak that nobody is keeping.
 *
 * `current_streak` is a stored counter that only moves inside `touch()`, so
 * somebody who stops coming back keeps the number they walked away with —
 * breaking a streak is the *absence* of an event, and nothing runs on absence.
 * User 38 was showing five consecutive days on the leaderboard a week after
 * their last one.
 */
it('shows a streak only while it is still alive', function () {
    $g = $this->gamification;
    $now = Carbon::parse('2026-09-01 12:00', 'UTC');

    expect($g->liveStreak('2026-09-01 10:00:00', 5, $now))->toBe(5)
        // Yesterday counts: the day is not over, so it is alive and at risk —
        // which is exactly what the reminder writes to people about.
        ->and($g->liveStreak('2026-08-31 23:00:00', 5, $now))->toBe(5)
        // Two days ago is a streak that ended and nothing was there to say so.
        ->and($g->liveStreak('2026-08-30 12:00:00', 5, $now))->toBe(0)
        ->and($g->liveStreak('2026-08-25 15:12:11', 5, $now))->toBe(0);
});

it('treats a missing or empty last-active date as no streak', function () {
    expect($this->gamification->liveStreak(null, 5))->toBe(0)
        ->and($this->gamification->liveStreak('', 5))->toBe(0)
        ->and($this->gamification->liveStreak('2026-09-01', 0))->toBe(0);
});

it('does not put a dead streak on the leaderboard', function () {
    $user = User::factory()->create();
    $this->gamification->award($user, 'swap', 'x', 500);

    UserStat::where('user_id', $user->id)->update([
        'current_streak' => 5,
        'longest_streak' => 5,
        'last_active_on' => Carbon::now('UTC')->subWeek(),
    ]);

    $row = collect($this->gamification->leaderboard(10))->firstWhere('user_id', $user->id);

    expect($row['current_streak'])->toBe(0)
        // The record stands: it happened, it is just over.
        ->and(UserStat::where('user_id', $user->id)->value('longest_streak'))->toBe(5);
});

it('does not put a dead streak on a public profile', function () {
    $user = User::factory()->create();
    $this->gamification->award($user, 'swap', 'x', 500);

    UserStat::where('user_id', $user->id)->update([
        'current_streak' => 5,
        'last_active_on' => Carbon::now('UTC')->subWeek(),
    ]);

    expect($this->gamification->publicProgressFor($user)['current_streak'])->toBe(0);
});
