<?php

use App\Models\Proposal;
use App\Models\SiteEvent;
use App\Models\User;
use App\Models\UserStat;
use App\Models\XpEntry;
use App\Services\Dao\ActivityRecorder;
use App\Services\GamificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
        // Two quests, not one: showing up is itself the daily_visit quest, and
        // the second swap re-runs both without paying either twice.
        ->and(XpEntry::where('user_id', $user->id)->where('source', 'quest')->count())->toBe(2);
});

it('completes the daily visit quest just by showing up', function () {
    $user = User::factory()->create();

    // Any first action of the day is a visit. Nothing calls recordAction with
    // 'visit' — the site reports page_view — so this quest is completed from
    // touch(), which is the only place that knows the day turned over.
    $this->gamification->recordAction($user, 'page_view', page: '/wallet');

    $quest = collect($this->gamification->questBoard($user))->firstWhere('key', 'daily_visit');

    expect($quest['completed'])->toBeTrue();
});

it('pays the daily visit quest once a day', function () {
    $user = User::factory()->create();

    $this->gamification->recordAction($user, 'page_view', page: '/wallet');
    $this->gamification->recordAction($user, 'page_view', page: '/swap');

    expect(
        XpEntry::where('user_id', $user->id)
            ->where('source', 'quest')
            ->where('reference', 'like', 'daily_visit:%')
            ->count(),
    )->toBe(1);
});

it('counts distinct pages for the exploration quest', function () {
    $user = User::factory()->create();

    foreach (['/swap', '/swap', '/bridge'] as $page) {
        SiteEvent::create([
            'session_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'event' => 'page_view',
            'page' => $page,
            'created_at' => now(),
        ]);
        $this->gamification->recordAction($user, 'page_view', page: $page);
    }

    $quest = collect($this->gamification->questBoard($user))->firstWhere('key', 'daily_explore');

    // Two distinct pages out of the three required, despite three page views.
    expect($quest['progress'])->toBe(2)
        ->and($quest['completed'])->toBeFalse();
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
