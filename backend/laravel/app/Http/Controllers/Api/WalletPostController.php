<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\GamificationService;
use App\Services\Social\PostAnnouncer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writing into the feed from the wallet.
 *
 * The rest of what the wallet knows about Cyberia is read-only and says so —
 * but read-only was never the honest description of *posting*. It was a
 * consequence of one missing step: the wallet holds a key and the feed needs an
 * author, and the two were never introduced. They are now, by the same
 * signature the Daily board already uses — the wallet signs the site's login
 * challenge once and gets an ordinary session, after which this is an ordinary
 * authenticated write. Nothing about custody changes: the seed stays in the
 * browser, and what this server learns is an address that proved it can sign.
 *
 * A separate controller from `WalletSocialController` on purpose. That one is
 * public, cached and answers to nobody; this one is behind the session and
 * writes. Mixing them would put a cache in front of a write path and an
 * authorisation question into an endpoint that deliberately has none.
 */
class WalletPostController extends Controller
{
    public function store(
        Request $request,
        GamificationService $gamification,
        PostAnnouncer $announcer,
    ): JsonResponse {
        $validated = $request->validate([
            // The same rule the site's own form carries, because it is the
            // same table and a second limit would be a second answer to "how
            // long may a post be".
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $post = $user->posts()->create($validated);

        // Keyed by the post, so a retry of a request whose answer was lost
        // cannot pay twice — the same rule the site's form follows.
        $gamification->recordAction($user, 'post', (string) $post->getKey());

        /*
         * Everybody who allowed notifications hears about this, and it happens
         * after the answer rather than inside it: the person who wrote the
         * post should not wait on a fan-out to a thousand devices, and this
         * host has no queue worker to hand it to.
         */
        dispatch(fn () => $announcer->announce($post))->afterResponse();

        return response()->json(['post' => $this->present($post)], 201);
    }

    /**
     * The new row in the shape the feed already draws, so the screen can put it
     * straight on top instead of re-reading a list it just changed.
     *
     * @return array<string, mixed>
     */
    private function present(Post $post): array
    {
        $user = $post->user()->first();

        return [
            'kind' => 'post',
            'id' => "post-{$post->id}",
            'at' => $post->created_at?->toIso8601String(),
            'who' => $user === null ? null : [
                'name' => $user->name,
                'avatar' => $user->avatar,
                'address' => $user->wallet_address,
                'url' => $user->profile_url,
            ],
            'text' => $post->body,
            'meta' => null,
            'url' => route('feed'),
        ];
    }
}
