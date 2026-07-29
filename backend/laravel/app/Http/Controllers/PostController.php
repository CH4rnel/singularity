<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Models\Post;
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

    public function store(StorePostRequest $request): RedirectResponse
    {
        $request->user()->posts()->create($request->validated());

        return back()->with('status', 'post-created');
    }
}
