<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paid-for action in the XP ledger. Append-only; the (user, source,
 * reference) unique index is the idempotency guard, so writes go through
 * GamificationService::award() rather than mass assignment elsewhere.
 */
class XpEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'source',
        'reference',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
