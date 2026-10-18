<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureCrmAdmin;
use App\Http\Requests\StoreBoardAnchorRequest;
use App\Http\Requests\StoreBoardPostRequest;
use App\Models\BoardPost;
use App\Models\BoardThread;
use App\Services\Board\BoardAnchorVerifier;
use App\Services\Board\BoardPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BoardController extends Controller
{
    public function index(Request $request, string $board = 'general'): Response
    {
        abort_unless(in_array($board, config('board.boards'), true), 404);
        $moderator = EnsureCrmAdmin::allows($request->user());
        $query = BoardThread::query()->where('board', $board)->whereNull('hidden_at')->with('firstPost')->withCount('posts');
        if ($moderator && $request->boolean('reported')) {
            $query->whereHas('posts', fn ($posts) => $posts->whereNull('hidden_at')->whereIn('id', DB::table('board_reports')->select('board_post_id')));
        }
        $threads = $query->orderByDesc('bumped_at')->orderByDesc('id')->paginate(20)->withQueryString()->through(fn (BoardThread $thread): array => [
            'id' => $thread->id, 'title' => $thread->title, 'closed' => $thread->closed,
            'replies' => max(0, $thread->posts_count - 1), 'bumpedAt' => $thread->bumped_at->toIso8601String(),
            'body' => mb_substr($thread->firstPost?->body ?? '', 0, 300),
            'image' => $thread->firstPost?->image_path ? route('board.image', $thread->firstPost) : null,
        ]);

        return Inertia::render('Board', [...$this->shared($request), 'board' => $board, 'thread' => null, 'threads' => $threads, 'posts' => null]);
    }

    public function show(Request $request, BoardThread $thread): Response
    {
        abort_if($thread->hidden_at, 404);
        $posts = $thread->posts()->orderBy('id')->paginate(30);
        $moderator = EnsureCrmAdmin::allows($request->user());
        $reports = $moderator ? DB::table('board_reports')->whereIn('board_post_id', $posts->pluck('id'))->groupBy('board_post_id')->selectRaw('board_post_id, count(*) as total')->pluck('total', 'board_post_id') : collect();
        $posts->through(fn (BoardPost $post): array => [
            'id' => $post->id, 'parentId' => $post->parent_id, 'author' => $post->author, 'actor' => $post->actor,
            'body' => $post->hidden_at ? null : $post->body,
            'image' => ! $post->hidden_at && $post->image_path ? route('board.image', $post) : null,
            'createdAt' => $post->created_at->toIso8601String(), 'hash' => $post->content_hash,
            'tx' => $post->anchor_tx, 'block' => $post->anchor_block,
            'canRemove' => ! $post->hidden_at && ($moderator || ($request->user() && $post->user_id === $request->user()->id)),
            'canAnchor' => ! $post->hidden_at && $post->author_address && ! $post->anchor_tx && $request->user() && $post->user_id === $request->user()->id,
            'canEvidence' => ! $post->hidden_at && ($post->anchor_tx || ($request->user() && $post->user_id === $request->user()->id)),
            'reports' => $moderator ? (int) ($reports[$post->id] ?? 0) : null,
        ]);

        return Inertia::render('Board', [...$this->shared($request), 'board' => $thread->board,
            'thread' => ['id' => $thread->id, 'title' => $thread->title, 'closed' => $thread->closed || $thread->posts()->count() >= 500], 'threads' => null, 'posts' => $posts]);
    }

    public function store(StoreBoardPostRequest $request, BoardPublisher $publisher, string $board): RedirectResponse
    {
        $post = $publisher->publish($request->user(), $request->validated(), board: $board);

        return to_route('board.show', $post->board_thread_id);
    }

    public function reply(StoreBoardPostRequest $request, BoardPublisher $publisher, BoardThread $thread): RedirectResponse
    {
        $post = $publisher->publish($request->user(), $request->validated(), $thread, $thread->board);

        return redirect()->to($this->postUrl($post));
    }

    public function agentReply(StoreBoardPostRequest $request, BoardPublisher $publisher, BoardThread $thread): JsonResponse
    {
        $abilities = $request->user()->currentAccessToken()?->abilities ?? [];
        abort_unless($request->bearerToken() && in_array('board:write', $abilities, true) && $request->user()->id === config('board.lain_user_id'), 403);
        $post = $publisher->publish($request->user(), $request->validated(), $thread, $thread->board);

        return response()->json(['id' => $post->id, 'hash' => $post->content_hash, 'url' => $this->postUrl($post)], 201);
    }

    public function image(BoardPost $post): BinaryFileResponse
    {
        abort_if($post->hidden_at || $post->thread->hidden_at || ! $post->image_path, 404);
        abort_unless(Storage::disk('local')->exists($post->image_path), 404);

        return response()->file(Storage::disk('local')->path($post->image_path), ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function evidence(Request $request, BoardPost $post): \Illuminate\Http\Response
    {
        abort_if($post->hidden_at || $post->thread->hidden_at, 404);
        abort_unless($post->anchor_tx || ($request->user() && $post->user_id === $request->user()->id), 404);

        return response($post->manifest())->header('Content-Type', 'application/json; charset=utf-8')->header('X-Content-Type-Options', 'nosniff')->header('Cache-Control', 'private, no-store');
    }

    public function anchor(StoreBoardAnchorRequest $request, BoardPost $post, BoardAnchorVerifier $verifier): RedirectResponse
    {
        abort_if($post->hidden_at || $post->thread->hidden_at || ! $post->author_address, 404);
        $hash = strtolower($request->validated('tx_hash'));
        if ($post->anchor_tx === $hash) {
            return back();
        }
        if ($post->anchor_tx || BoardPost::query()->where('anchor_tx', $hash)->exists()) {
            throw ValidationException::withMessages(['tx_hash' => 'This publication or transaction already has a proof.']);
        }
        $block = $verifier->verify($post, $hash);
        DB::transaction(function () use ($post, $hash, $block): void {
            $fresh = BoardPost::query()->lockForUpdate()->findOrFail($post->id);
            abort_if($fresh->hidden_at || $fresh->thread->hidden_at, 404);
            if ($fresh->anchor_tx || ! hash_equals($post->content_hash, hash('sha256', $fresh->manifest()))) {
                throw ValidationException::withMessages(['tx_hash' => 'The publication changed or already has a proof.']);
            }
            $fresh->update(['anchor_tx' => $hash, 'anchor_block' => $block]);
        });

        return back()->with('status', 'board-anchored');
    }

    public function remove(Request $request, BoardPost $post): RedirectResponse
    {
        abort_unless($post->user_id === $request->user()->id || EnsureCrmAdmin::allows($request->user()), 403);
        $thread = $post->thread;
        DB::transaction(function () use ($post, $thread): void {
            $post->update(['hidden_at' => now()]);
            if ($thread->posts()->min('id') === $post->id) {
                $thread->update(['hidden_at' => now()]);
            }
        });

        return $thread->hidden_at ? to_route('board.browse', $thread->board) : back();
    }

    public function report(Request $request, BoardPost $post): RedirectResponse
    {
        abort_if($post->hidden_at || $post->thread->hidden_at, 404);
        DB::table('board_reports')->insertOrIgnore(['board_post_id' => $post->id, 'user_id' => $request->user()->id, 'created_at' => now()]);

        return back()->with('status', 'board-reported');
    }

    public function toggle(Request $request, BoardThread $thread): RedirectResponse
    {
        abort_unless(EnsureCrmAdmin::allows($request->user()), 403);
        abort_if($thread->hidden_at, 404);
        $thread->update(['closed' => ! $thread->closed]);

        return back();
    }

    private function shared(Request $request): array
    {
        return ['boards' => config('board.boards'), 'signedIn' => $request->user() !== null,
            'moderator' => EnsureCrmAdmin::allows($request->user()),
            'walletLinked' => (bool) $request->user()?->wallet_address];
    }

    private function postUrl(BoardPost $post): string
    {
        $page = (int) ceil($post->thread->posts()->where('id', '<=', $post->id)->count() / 30);

        return route('board.show', [$post->board_thread_id, 'page' => $page]).'#post-'.$post->id;
    }
}
