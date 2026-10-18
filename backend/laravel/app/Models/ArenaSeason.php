<?php

namespace App\Models;

use Database\Factories\ArenaSeasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArenaSeason extends Model
{
    /** @use HasFactory<ArenaSeasonFactory> */
    use HasFactory;

    protected $fillable = ['slug', 'title', 'starts_at', 'ends_at', 'rules', 'rules_hash', 'last_tick_at', 'automation_error'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'last_tick_at' => 'immutable_datetime', 'rules' => 'array'];
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(ArenaRound::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ArenaComment::class);
    }

    public function followers(): HasMany
    {
        return $this->hasMany(ArenaFollower::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(ArenaCycle::class);
    }
}
