<?php

namespace App\Services\Board;

use App\Models\BoardPost;
use App\Models\BoardThread;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BoardPublisher
{
    /** @param array<string, mixed> $data */
    public function publish(User $user, array $data, ?BoardThread $thread = null, string $board = 'general'): BoardPost
    {
        abort_unless(in_array($board, config('board.boards'), true), 404);
        $path = null;
        $bytes = null;
        if (isset($data['image'])) {
            if (! function_exists('imagewebp')) {
                throw ValidationException::withMessages(['image' => 'Image processing is unavailable. Text posting is still available.']);
            }
            $image = @imagecreatefromstring($data['image']->get());
            if ($image === false) {
                throw ValidationException::withMessages(['image' => 'This image could not be decoded.']);
            }
            imagepalettetotruecolor($image);
            imagesavealpha($image, true);
            ob_start();
            $encoded = imagewebp($image, null, 85);
            $bytes = ob_get_clean();
            imagedestroy($image);
            if (! $encoded || ! is_string($bytes) || $bytes === '') {
                throw ValidationException::withMessages(['image' => 'This image could not be processed.']);
            }
            $path = 'board/'.Str::uuid().'.webp';
        }

        try {
            return Cache::lock('board-publish', 15)->block(5, fn (): BoardPost => DB::transaction(function () use ($user, $data, $thread, $board, $path, $bytes): BoardPost {
                if ($thread) {
                    $thread = BoardThread::query()->lockForUpdate()->findOrFail($thread->id);
                    abort_if($thread->hidden_at, 404);
                    if ($thread->closed || $thread->posts()->count() >= 500) {
                        throw ValidationException::withMessages(['body' => 'This thread is closed to new replies.']);
                    }
                }
                if ($path !== null) {
                    $daily = BoardPost::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subDay())->whereNotNull('image_path')->count();
                    if ($daily >= config('board.images_per_day') || BoardPost::query()->sum('image_bytes') + strlen($bytes) > config('board.storage_bytes')) {
                        throw ValidationException::withMessages(['image' => 'The image storage limit has been reached. You can still post text.']);
                    }
                    if (! Storage::disk('local')->put($path, $bytes)) {
                        throw ValidationException::withMessages(['image' => 'The image could not be stored.']);
                    }
                }
                $thread ??= BoardThread::query()->create(['board' => $board, 'title' => $data['title'], 'bumped_at' => now()]);
                $agent = $user->id === config('board.lain_user_id');
                $post = $thread->posts()->make([
                    'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'parent_id' => $data['parent_id'] ?? null,
                    'author' => $agent ? 'Lain' : 'Anon #'.substr(hash_hmac('sha256', $user->id.':'.$thread->id, config('app.key')), 0, 8),
                    'actor' => $agent ? 'agent' : 'human',
                    'author_address' => preg_match('/^0x[0-9a-f]{40}$/i', (string) $user->wallet_address) ? strtolower($user->wallet_address) : null,
                    'body' => $data['body'] ?? '', 'image_path' => $path,
                    'image_hash' => $bytes === null ? null : hash('sha256', $bytes), 'image_bytes' => $bytes === null ? 0 : strlen($bytes),
                ]);
                $post->setRelation('thread', $thread);
                $post->content_hash = hash('sha256', $post->manifest());
                $post->save();
                $thread->update(['bumped_at' => now()]);

                return $post;
            }));
        } catch (\Throwable $error) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }
}
