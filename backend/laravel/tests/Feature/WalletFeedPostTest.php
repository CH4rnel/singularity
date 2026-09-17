<?php

use App\Models\AnalyticsUser;
use App\Models\Post;
use App\Models\User;
use App\Notifications\FeedPostNotification;
use App\Services\Social\PostAnnouncer;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Writing into the feed from the wallet, and who hears about it.
 *
 * Two things are worth pinning. The write is *authenticated* — the whole point
 * of the wallet's signature is that the post has an author, so an anonymous
 * request must be refused rather than filed under nobody. And the announcement
 * reaches the audience this project actually has: almost everybody here is a
 * wallet installation that never signed up for anything, so a fan-out that
 * walked only site accounts would be a feature that notifies nobody.
 */
it('refuses a post from a wallet that has not signed in', function () {
    $this->postJson('/api/wallet/feed', ['body' => 'hello'])
        ->assertUnauthorized();

    expect(Post::query()->count())->toBe(0);
});

it('writes a post and answers with the row the feed draws', function () {
    Notification::fake();

    $user = User::factory()->create(['wallet_address' => '0x'.str_repeat('a', 40)]);

    $response = $this->actingAs($user)
        ->postJson('/api/wallet/feed', ['body' => 'first light']);

    $response->assertCreated()
        ->assertJsonPath('post.kind', 'post')
        ->assertJsonPath('post.text', 'first light')
        ->assertJsonPath('post.who.address', $user->wallet_address);

    expect(Post::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses an empty post and one longer than the feed accepts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/wallet/feed', ['body' => '   '])
        ->assertStatus(422);

    $this->actingAs($user)
        ->postJson('/api/wallet/feed', ['body' => str_repeat('x', 2001)])
        ->assertStatus(422);
});

it('announces a post to accounts and to wallet installations alike', function () {
    Notification::fake();

    $author = User::factory()->create();
    $reader = User::factory()->create();
    $silent = User::factory()->create();

    // Only a device that agreed to be notified is a recipient; the third
    // account has no subscription and must not be walked at all.
    $author->updatePushSubscription('https://push.example/author', 'k', 'a');
    $reader->updatePushSubscription('https://push.example/reader', 'k', 'a');

    $install = AnalyticsUser::query()->create([
        'id' => (string) Str::uuid(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    $install->updatePushSubscription('https://push.example/install', 'k', 'a');

    $post = $author->posts()->create(['body' => 'the wired is wide']);

    app(PostAnnouncer::class)->announce($post);

    Notification::assertSentTo($reader, FeedPostNotification::class);
    Notification::assertSentTo($install, FeedPostNotification::class);
    // The author is not told what the author just wrote.
    Notification::assertNotSentTo($author, FeedPostNotification::class);
    Notification::assertNotSentTo($silent, FeedPostNotification::class);
});

it('carries the author and the post itself into the notification', function () {
    $author = User::factory()->create(['name' => 'lain']);
    $post = $author->posts()->create(['body' => str_repeat('a', 300)]);

    $notification = FeedPostNotification::for($post);
    $reader = User::factory()->create(['notification_locale' => 'ru']);

    expect($notification->titleFor($reader))->toBe('Новый пост: lain')
        // Cut, never rewritten: a push that summarises somebody misquotes them.
        ->and(mb_strlen($notification->excerpt))->toBeLessThanOrEqual(140)
        ->and($notification->url)->toBe('/wallet?section=feed');
});
