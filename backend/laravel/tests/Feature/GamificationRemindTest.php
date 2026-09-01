<?php

use App\Models\User;
use App\Models\UserStat;
use App\Notifications\ProgressNotification;
use App\Support\Localised;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * A real uncompressed P-256 point. The webpush library encrypts the payload
 * before any transport is involved, so a placeholder key fails inside the
 * library rather than at the network — which is why the tests that let the
 * notification actually persist need a valid one.
 */
const P256DH = 'BPjjy6m75glPkZPm05g6itPT9AZhJkrsbBtC75-XOCa6RJvH7z0M9yG7chy90mfqGpyW_woG0cRpVUh02B6a5XU';
const AUTH = 'aysn5PMyEtD377c-cxXE-g';

/** A throwaway VAPID pair — the library validates both halves before sending. */
const VAPID_PUB = 'BFyCqu6c1-7IfXmNbjkQX4wGEwLVxsoeQ5YZU7iz24zlEdpNsh5i2_Gzv8lzHjuA_CClN7_xRuJQM9LfvPoigJY';
const VAPID_PRIV = '32q0BGHK6sEQfAIHRhSSFH7p7LyHQWggbt3kMtorb4s';

/**
 * The nudge, and the rules that keep it from becoming spam.
 *
 * Everything pinned here is a reason *not* to send: somebody already here
 * today, somebody with no way to receive it, somebody already written to. A
 * reminder that fires when it should not is worse than none at all — it is the
 * fastest way to have push turned off forever.
 */
function reachable(array $stats = [], ?string $locale = 'ru'): User
{
    // VAPID has to be configured for the WebPush channel to be selected at
    // all, and the delivery itself is faked one line below.
    config()->set('webpush.vapid.public_key', VAPID_PUB);
    config()->set('webpush.vapid.private_key', VAPID_PRIV);
    config()->set('webpush.vapid.subject', 'https://cyberia.test');
    Http::fake(['*' => Http::response('', 201)]);

    $user = User::factory()->create(['notification_locale' => $locale]);

    UserStat::query()->create(array_merge([
        'user_id' => $user->id,
        'xp' => 100,
        'level' => 2,
        'current_streak' => 5,
        'longest_streak' => 5,
        'last_active_on' => Carbon::now('UTC')->subDay()->toDateString(),
    ], $stats));

    $user->updatePushSubscription('https://push.test/'.$user->id, P256DH, AUTH);

    return $user;
}

it('warns somebody whose streak dies tonight', function () {
    Notification::fake();
    $user = reachable();

    $this->artisan('gamification:remind')->assertSuccessful();

    Notification::assertSentTo($user, ProgressNotification::class,
        fn (ProgressNotification $n) => $n->type === 'progress.streak_at_risk');
});

it('says nothing to somebody who has already been here today', function () {
    Notification::fake();
    reachable(['last_active_on' => Carbon::now('UTC')->toDateString()]);

    $this->artisan('gamification:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

it('says nothing about a streak that already broke', function () {
    Notification::fake();
    // Two days away: the streak is gone, so there is nothing left to save and
    // nothing honest to say.
    reachable(['last_active_on' => Carbon::now('UTC')->subDays(3)->toDateString()]);

    $this->artisan('gamification:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

it('says nothing about a streak of one', function () {
    Notification::fake();
    reachable(['current_streak' => 1]);

    $this->artisan('gamification:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

it('cannot reach somebody with no push subscription', function () {
    Notification::fake();
    $user = User::factory()->create();
    UserStat::query()->create([
        'user_id' => $user->id, 'xp' => 10, 'level' => 1,
        'current_streak' => 5, 'longest_streak' => 5,
        'last_active_on' => Carbon::now('UTC')->subDay()->toDateString(),
    ]);

    $this->artisan('gamification:remind')->assertSuccessful();

    Notification::assertNothingSent();
});

it('nudges a daily quest left one step short', function () {
    Notification::fake();
    // Here today, so the streak is safe — but the quest is not finished.
    $user = reachable(['last_active_on' => Carbon::now('UTC')->subDay()->toDateString(), 'current_streak' => 1]);

    DB::table('user_quests')->insert([
        'user_id' => $user->id,
        'quest_key' => 'daily_explore',
        'period_key' => Carbon::now('UTC')->toDateString(),
        'progress' => 2,
        'target' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('gamification:remind')->assertSuccessful();

    Notification::assertSentTo($user, ProgressNotification::class,
        fn (ProgressNotification $n) => $n->type === 'progress.quest_nearly_done');
});

it('writes at most once a day', function () {
    $user = reachable();

    $this->artisan('gamification:remind')->assertSuccessful();
    $this->artisan('gamification:remind')->assertSuccessful();

    expect(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(1);
});

it('changes nothing on a dry run', function () {
    Notification::fake();
    reachable();

    $this->artisan('gamification:remind --dry-run')
        ->expectsOutputToContain('dry run')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('writes in the language the browser reported', function () {
    $user = reachable(locale: 'ru');

    $this->artisan('gamification:remind')->assertSuccessful();

    $data = json_decode((string) DB::table('notifications')
        ->where('notifiable_id', $user->id)->value('data'), true);

    expect($data['title'])->toContain('Серия из 5 дней');
});

it('falls back to english when nobody said', function () {
    $user = reachable(locale: null);

    $this->artisan('gamification:remind')->assertSuccessful();

    $data = json_decode((string) DB::table('notifications')
        ->where('notifiable_id', $user->id)->value('data'), true);

    expect($data['title'])->toContain('5-day streak');
});

it('collapses every zh variant onto simplified, and refuses what it does not speak', function () {
    expect(Localised::normalise('zh-TW'))->toBe('zh')
        ->and(Localised::normalise('ru-RU'))->toBe('ru')
        ->and(Localised::normalise('vi-VN'))->toBeNull()
        ->and(Localised::normalise(''))->toBeNull();
});

it('falls back to english for a language a string was never written in', function () {
    expect(Localised::pick(['en' => 'only english'], 'ru'))->toBe('only english');
});

it('stores the browser language when a subscription is registered', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('push-subscriptions.store'), [
        'endpoint' => 'https://push.test/abc',
        'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        'locale' => 'zh-CN',
    ])->assertOk();

    expect($user->fresh()->notification_locale)->toBe('zh');
});
