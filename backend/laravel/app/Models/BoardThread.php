<?php

namespace App\Models;

use Database\Factories\BoardThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BoardThread extends Model
{
    /** @use HasFactory<BoardThreadFactory> */
    use HasFactory;

    protected $fillable = ['board', 'title', 'closed', 'hidden_at', 'bumped_at'];

    protected $attributes = ['closed' => false];

    protected function casts(): array
    {
        return ['closed' => 'boolean', 'hidden_at' => 'immutable_datetime', 'bumped_at' => 'immutable_datetime'];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(BoardPost::class);
    }

    public function firstPost(): HasOne
    {
        return $this->hasOne(BoardPost::class)->oldestOfMany();
    }
}
