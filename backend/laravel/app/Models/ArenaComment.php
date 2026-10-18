<?php

namespace App\Models;

use Database\Factories\ArenaCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArenaComment extends Model
{
    /** @use HasFactory<ArenaCommentFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'parent_id', 'body', 'hidden_at'];

    protected function casts(): array
    {
        return ['hidden_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
