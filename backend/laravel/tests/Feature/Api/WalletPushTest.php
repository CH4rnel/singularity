<?php

use App\Models\AnalyticsUser;
use App\Notifications\ProgressNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Being notified without having an account.
 *
 * The site's bell hangs off a signed-in user and lives in a header the wallet
 * never renders — so push could reach the handful of site accounts and none of
 * the installations, which is almost everybody. This is the other door.
 *
 * The rules worth pinning are the refusals: an installation that does not
 * exist is not created here, and keys that cannot sign do not get answered
 * "subscribed" — saying yes over a broken signer is exactly how this feature
 * spent its first day.
 */
const WALLET_VAPID_PUBLIC = 'BFyCqu6c1-7IfXmNbjkQX4wGEwLVxsoeQ5YZU7iz24zlEdpNsh5i2_Gzv8lzHjuA_CClN7_xRuJQM9LfvPoigJY';
const WALLET_VAPID_PRIVATE = '32q0BGHK6sEQfAIHRhSSFH7p7LyHQWggbt3kMtorb4s';
const WALLET_P256DH = 'BPjjy6m75glPkZPm05g6itPT9AZhJkrsbBtC75-XOCa6RJvH7z0M9yG7chy90mfqGpyW_woG0cRpVUh02B6a5XU';

beforeEach(function () {
    config()->set('webpush.vapid.public_key', WALLET_VAPID_PUBLIC);
    config()->set('webpush.vapid.private_key', WALLET_VAPID_PRIVATE);
    config()->set('webpush.vapid.subject', 'https://cyberia.test');
    Http::fake(['*' => Http::response('', 201)]);
});

function install(array $attributes = []): AnalyticsUser
{
    return AnalyticsUser::create(array_merge([
        'id' => (string) Str::uuid(),
        'created_at' => now(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'platform' => 'pwa',
    ], $attributes));
}

function subscribeBody(AnalyticsUser $user, array $extra = []): array
{
    return array_merge([
        'user_id' => $user->id,
        'endpoint' => 'https://push.test/'.$user->id,
        'keys' => ['p256dh' => WALLET_P256DH, 'auth' => 'aysn5PMyEtD377c-cxXE-g'],
    ], $extra);
}

it('subscribes an installation with no account anywhere', function () {
    $user = install();

    $this->postJson('/api/analytics/push', subscribeBody($user))
        ->assertOk()
        ->assertJson(['subscribed' => true]);

    expect($user->fresh()->pushSubscriptions()->count())->toBe(1);
});

it('remembers the language the browser reported', function () {
    $user = install();

    $this->postJson('/api/analytics/push', subscribeBody($user, ['locale' => 'ru-RU']))->assertOk();

    expect($user->fresh()->notification_locale)->toBe('ru');
});

it('does not invent an installation for an id it has never seen', function () {
    $unknown = (string) Str::uuid();

    $this->postJson('/api/analytics/push', [
        'user_id' => $unknown,
        'endpoint' => 'https://push.test/x',
        'keys' => ['p256dh' => WALLET_P256DH, 'auth' => 'a'],
    ])->assertNotFound();

    expect(AnalyticsUser::find($unknown))->toBeNull();
});

it('refuses to claim a subscription over keys that cannot sign', function () {
    config()->set('webpush.vapid.private_key', 'c2hvcnQ');
    $user = install();

    $this->postJson('/api/analytics/push', subscribeBody($user))
        ->assertStatus(503)
        ->assertJson(['subscribed' => false, 'reason' => 'unconfigured']);

    expect($user->fresh()->pushSubscriptions()->count())->toBe(0);
});

it('unsubscribes', function () {
    $user = install();
    $this->postJson('/api/analytics/push', subscribeBody($user))->assertOk();

    $this->deleteJson('/api/analytics/push', [
        'user_id' => $user->id,
        'endpoint' => 'https://push.test/'.$user->id,
    ])->assertOk();

    expect($user->fresh()->pushSubscriptions()->count())->toBe(0);
});

it('takes an unsubscribe for an unknown installation quietly', function () {
    // A device clearing up after its installation row was pruned should not
    // see an error it can do nothing about.
    $this->deleteJson('/api/analytics/push', [
        'user_id' => (string) Str::uuid(),
        'endpoint' => 'https://push.test/gone',
    ])->assertOk();
});

it('sends an installation push and no database row', function () {
    Notification::fake();
    $user = install();
    $this->postJson('/api/analytics/push', subscribeBody($user))->assertOk();

    $user->fresh()->notify(new ProgressNotification(
        type: 'console.broadcast',
        title: ['en' => 'Hello'],
        body: ['en' => 'Body'],
        url: '/wallet',
    ));

    // An installation has no bell to read, so a database row would be a
    // message nobody could ever open.
    Notification::assertSentTo(
        $user,
        ProgressNotification::class,
        fn (ProgressNotification $n, array $channels) => $channels === [WebPushChannel::class],
    );
});

it('writes to an installation in the language it reported', function () {
    $user = install();
    $this->postJson('/api/analytics/push', subscribeBody($user, ['locale' => 'ru']))->assertOk();

    $notification = new ProgressNotification(
        type: 'console.broadcast',
        title: ['en' => 'Waiting', 'ru' => 'Ждёт'],
        body: ['en' => 'Body', 'ru' => 'Текст'],
        url: '/wallet',
    );

    expect($notification->titleFor($user->fresh()))->toBe('Ждёт');
});

it('refuses an endpoint that is not a url', function () {
    $user = install();

    $this->postJson('/api/analytics/push', subscribeBody($user, ['endpoint' => 'not-a-url']))
        ->assertStatus(422);
});
