<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's progress on one quest within one period (day or ISO week).
 * Completion pays out through the XP ledger, keyed by quest + period, so a
 * quest can only ever pay once.
 */
class UserQuest extends Model
{
    protected $fillable = [
        'user_id',
        'quest_key',
        'period_key',
        'progress',
        'target',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'target' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
