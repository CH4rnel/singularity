<?php

namespace App\Models;

use Database\Factories\BoardPostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardPost extends Model
{
    /** @use HasFactory<BoardPostFactory> */
    use HasFactory;

    protected $fillable = ['uuid', 'user_id', 'parent_id', 'author', 'actor', 'author_address', 'body', 'image_path', 'image_hash', 'image_bytes', 'content_hash', 'anchor_tx', 'anchor_block', 'hidden_at'];

    protected $attributes = ['actor' => 'human', 'image_bytes' => 0];

    protected function casts(): array
    {
        return ['hidden_at' => 'immutable_datetime', 'anchor_block' => 'integer', 'user_id' => 'integer', 'parent_id' => 'integer', 'board_thread_id' => 'integer'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(BoardThread::class, 'board_thread_id');
    }

    /** The exact bytes hashed with SHA-256. Do not reorder or normalize fields. */
    public function manifest(): string
    {
        return json_encode([
            'format' => 'cyberia-board-v1', 'chain_id' => 49406,
            'board' => $this->thread->board, 'thread' => $this->board_thread_id,
            'title' => $this->thread->title, 'post' => $this->uuid,
            'parent' => $this->parent_id, 'author' => $this->author,
            'actor' => $this->actor, 'address' => $this->author_address,
            'body' => $this->body, 'image_sha256' => $this->image_hash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function anchorData(): string
    {
        return '0x'.bin2hex('cyberia-board-v1:'.$this->content_hash);
    }
}
