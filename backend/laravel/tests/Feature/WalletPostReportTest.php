<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reporting a feed post from the wallet.
 *
 * The rules worth pinning: a report needs a person, one person counts once,
 * and a post that enough different people reported leaves every feed that
 * draws it — the wallet's and the site's — without being deleted.
 */
beforeEach(function () {
    Cache::flush();
    config(['wallet.feed.hide_after_reports' => 2]);
});

it('refuses a report from nobody', function () {
    $post = Post::factory()->create();

    $this->postJson("/api/wallet/feed/{$post->id}/report", ['reason' => 'spam'])
        ->assertUnauthorized();
});

it('takes only a reason from the list', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/wallet/feed/{$post->id}/report", ['reason' => 'I dislike it'])
        ->assertUnprocessable();
});

it('counts a person once, however often they report', function () {
    $post = Post::factory()->create();
    $reporter = User::factory()->create();

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($reporter)
            ->postJson("/api/wallet/feed/{$post->id}/report", ['reason' => 'spam'])
            ->assertAccepted()
            ->assertJson(['hidden' => false]);
    }

    expect(DB::table('post_reports')->where('post_id', $post->id)->count())->toBe(1)
        ->and($post->fresh()->hidden_at)->toBeNull();
});

it('takes a post out of the feed once enough people reported it', function () {
    $post = Post::factory()->create(['body' => 'buy my token now']);
    $kept = Post::factory()->create(['body' => 'gm']);

    // Warm the cache, so the test proves the report clears it.
    expect($this->getJson('/api/wallet/feed?tab=posts')->json('items'))->toHaveCount(2);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/wallet/feed/{$post->id}/report", ['reason' => 'scam'])
        ->assertJson(['hidden' => false]);
    $this->actingAs(User::factory()->create())
        ->postJson("/api/wallet/feed/{$post->id}/report", ['reason' => 'spam'])
        ->assertJson(['hidden' => true]);

    $items = $this->getJson('/api/wallet/feed?tab=posts')->json('items');

    expect($items)->toHaveCount(1)
        ->and($items[0]['id'])->toBe("post-{$kept->id}")
        ->and(Post::query()->find($post->id))->not->toBeNull()
        ->and(Post::query()->visible()->pluck('id')->all())->toBe([$kept->id]);
});
