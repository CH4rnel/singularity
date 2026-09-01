<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Models\Post;
use App\Services\GamificationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Feed', [
            'posts' => Post::query()
                ->with(['user:id,name,onchain_nickname,avatar_path,wallet_address'])
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function store(StorePostRequest $request, GamificationService $gamification): RedirectResponse
    {
        $post = $request->user()->posts()->create($request->validated());

        // The wall paid nothing until now, which made it the one place on this
        // site where taking part was worth less than opening a page. Keyed by
        // the post so editing or reloading cannot pay twice.
        $gamification->recordAction($request->user(), 'post', (string) $post->getKey());

        return back()->with('status', 'post-created');
    }
}
