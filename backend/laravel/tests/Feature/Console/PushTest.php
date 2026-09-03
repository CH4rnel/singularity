<?php

use App\Models\AnalyticsUser;
use App\Models\User;
use App\Notifications\ProgressNotification;
use App\Support\VapidHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The operator's own way to say something, and the health strip that has to be
 * right before the send button means anything.
 *
 * The strip is the point of most of this. Push spent its first hours with keys
 * that were "set" and unusable: the browser subscribed, the row was stored, and
 * every send died inside the library with nothing on any screen saying so.
 */
const PUSH_VAPID_PUBLIC = 'BFyCqu6c1-7IfXmNbjkQX4wGEwLVxsoeQ5YZU7iz24zlEdpNsh5i2_Gzv8lzHjuA_CClN7_xRuJQM9LfvPoigJY';
const PUSH_VAPID_PRIVATE = '32q0BGHK6sEQfAIHRhSSFH7p7LyHQWggbt3kMtorb4s';

beforeEach(function () {
    $this->withoutVite();
    config()->set('webpush.vapid.public_key', PUSH_VAPID_PUBLIC);
    config()->set('webpush.vapid.private_key', PUSH_VAPID_PRIVATE);
    config()->set('webpush.vapid.subject', 'https://cyberia.test');
    Http::fake(['*' => Http::response('', 201)]);
});

function pushOperator(): User
{
    $user = User::factory()->create(['wallet_address' => '0x'.str_repeat('c', 40)]);
    config()->set('crm.admin_user_ids', [$user->id]);

    return $user;
}

function pushInstall(?string $locale = 'ru'): AnalyticsUser
{
    $install = AnalyticsUser::create([
        'id' => (string) Str::uuid(),
        'created_at' => now(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'platform' => 'pwa',
        'notification_locale' => $locale,
    ]);

    $install->updatePushSubscription(
        'https://push.test/i/'.$install->id,
        'BPjjy6m75glPkZPm05g6itPT9AZhJkrsbBtC75-XOCa6RJvH7z0M9yG7chy90mfqGpyW_woG0cRpVUh02B6a5XU',
        'aysn5PMyEtD377c-cxXE-g',
    );

    return $install;
}

function pushSubscriber(?string $locale = 'ru'): User
{
    $user = User::factory()->create(['notification_locale' => $locale]);
    $user->updatePushSubscription(
        'https://push.test/'.$user->id,
        'BPjjy6m75glPkZPm05g6itPT9AZhJkrsbBtC75-XOCa6RJvH7z0M9yG7chy90mfqGpyW_woG0cRpVUh02B6a5XU',
        'aysn5PMyEtD377c-cxXE-g',
    );

    return $user;
}

/* --------------------------------------------------------------- health -- */

it('calls a correct key pair healthy', function () {
    expect(VapidHealth::check()['ok'])->toBeTrue();
});

it('names a private key written as standard base64', function () {
    // The exact failure that shipped: 79 characters of standard base64 where a
    // 43-character base64url scalar was wanted.
    config()->set('webpush.vapid.private_key', 'MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg+aB/dGVzdA==');

    $health = VapidHealth::check();

    expect($health['ok'])->toBeFalse()
        ->and($health['private'])->toContain('base64url')
        ->and(implode(' ', $health['problems']))->toContain('VAPID_PRIVATE_KEY');
});

it('names a key of the wrong length', function () {
    config()->set('webpush.vapid.private_key', 'c2hvcnQ');

    expect(VapidHealth::check()['private'])->toContain('вместо 32');
});

it('names a missing subject, which no key length would catch', function () {
    config()->set('webpush.vapid.subject', '');

    $health = VapidHealth::check();

    expect($health['ok'])->toBeFalse()
        ->and(implode(' ', $health['problems']))->toContain('VAPID_SUBJECT');
});

/* ----------------------------------------------------------------- lens -- */

it('is closed to everyone outside the console', function () {
    $this->actingAs(User::factory()->create())->get('/crm/push')->assertNotFound();
});

it('counts only people who can actually receive something', function () {
    $operator = pushOperator();
    pushSubscriber();
    User::factory()->count(3)->create();

    $this->actingAs($operator)->get('/crm/push')
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Push')
            ->where('coverage.reachable', 1)
            ->where('health.ok', true));
});

it('reaches wallet installations, which is most of everybody', function () {
    Notification::fake();
    $operator = pushOperator();
    $account = pushSubscriber();
    $install = pushInstall();

    $this->actingAs($operator)->post('/crm/push', [
        'audience' => 'all',
        'title' => 'Заголовок',
        'body' => 'Текст',
    ])->assertRedirect();

    Notification::assertSentTo($account, ProgressNotification::class);
    Notification::assertSentTo($install, ProgressNotification::class);
});

it('can write to installations alone', function () {
    Notification::fake();
    $operator = pushOperator();
    $account = pushSubscriber();
    $install = pushInstall();

    $this->actingAs($operator)->post('/crm/push', [
        'audience' => 'installs',
        'title' => 'Заголовок',
        'body' => 'Текст',
    ])->assertRedirect();

    Notification::assertSentTo($install, ProgressNotification::class);
    Notification::assertNotSentTo($account, ProgressNotification::class);
});

it('counts installations and accounts apart', function () {
    $operator = pushOperator();
    pushSubscriber();
    pushInstall();
    pushInstall();

    $this->actingAs($operator)->get('/crm/push')
        ->assertInertia(fn (Assert $page) => $page
            ->where('coverage.accounts', 1)
            ->where('coverage.installs', 2)
            ->where('coverage.reachable', 3));
});

it('sends to everyone subscribed', function () {
    Notification::fake();
    $operator = pushOperator();
    $a = pushSubscriber();
    $b = pushSubscriber();

    $this->actingAs($operator)->post('/crm/push', [
        'audience' => 'all',
        'title' => 'Заголовок',
        'body' => 'Текст',
        'url' => '/wallet',
    ])->assertRedirect();

    Notification::assertSentTo([$a, $b], ProgressNotification::class);
});

it('sends to one person when one is named', function () {
    Notification::fake();
    $operator = pushOperator();
    $a = pushSubscriber();
    $b = pushSubscriber();

    $this->actingAs($operator)->post('/crm/push', [
        'audience' => 'user',
        'user_id' => $a->id,
        'title' => 'Только тебе',
        'body' => 'Текст',
    ])->assertRedirect();

    Notification::assertSentTo($a, ProgressNotification::class);
    Notification::assertNotSentTo($b, ProgressNotification::class);
});

it('refuses to send over keys that cannot sign', function () {
    Notification::fake();
    $operator = pushOperator();
    pushSubscriber();
    config()->set('webpush.vapid.private_key', 'c2hvcnQ');

    $this->actingAs($operator)->post('/crm/push', [
        'audience' => 'all',
        'title' => 'Заголовок',
        'body' => 'Текст',
    ])->assertSessionHasErrors('title');

    Notification::assertNothingSent();
});

it('refuses to send to nobody', function () {
    Notification::fake();

    $this->actingAs(pushOperator())->post('/crm/push', [
        'audience' => 'all',
        'title' => 'Заголовок',
        'body' => 'Текст',
    ])->assertSessionHasErrors('title');

    Notification::assertNothingSent();
});

it('does not label russian text as english', function () {
    $operator = pushOperator();
    $user = pushSubscriber(locale: 'en');

    $this->actingAs($operator)->post('/crm/push', [
        'audience' => 'all',
        'title' => 'Заголовок',
        'body' => 'Текст',
    ])->assertRedirect();

    // No English given, so an English reader gets the operator's own words
    // rather than a key or an empty string.
    $data = json_decode((string) DB::table('notifications')
        ->where('notifiable_id', $user->id)->value('data'), true);

    expect($data['title'])->toBe('Заголовок');
});

it('gives an english reader the english version when there is one', function () {
    $operator = pushOperator();
    $user = pushSubscriber(locale: 'en');

    $this->actingAs($operator)->post('/crm/push', [
        'audience' => 'all',
        'title' => 'Заголовок',
        'body' => 'Текст',
        'title_en' => 'Heading',
        'body_en' => 'Text',
    ])->assertRedirect();

    $data = json_decode((string) DB::table('notifications')
        ->where('notifiable_id', $user->id)->value('data'), true);

    expect($data['title'])->toBe('Heading');
});

it('refuses a url that leaves the site', function () {
    $this->actingAs(pushOperator())->post('/crm/push', [
        'audience' => 'all',
        'title' => 'Заголовок',
        'body' => 'Текст',
        'url' => 'https://elsewhere.test/steal',
    ])->assertSessionHasErrors('url');
});
