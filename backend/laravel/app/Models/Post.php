<?php

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected $fillable = [
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * Posts the feed may show: everything but what enough people reported.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
