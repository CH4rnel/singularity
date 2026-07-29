<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denormalised progress row (xp, level, streak) kept in sync by
 * GamificationService. Always safe to recompute from xp_entries except for
 * the streak columns, which are calendar state rather than a ledger sum.
 */
class UserStat extends Model
{
    /**
     * Mirrors the column defaults so a row created with only a user_id still
     * arrives as a usable model — firstOrCreate() does not read defaults back.
     */
    protected $attributes = [
        'xp' => 0,
        'level' => 1,
        'current_streak' => 0,
        'longest_streak' => 0,
    ];

    protected $fillable = [
        'user_id',
        'xp',
        'level',
        'current_streak',
        'longest_streak',
        'last_active_on',
        'streak_started_on',
    ];

    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'level' => 'integer',
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'last_active_on' => 'date',
            'streak_started_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
