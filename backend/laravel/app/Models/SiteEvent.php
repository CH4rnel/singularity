<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteEvent extends Model
{
    public const UPDATED_AT = null;

    /** Whitelist enforced at ingest; extend alongside lib/track.ts. */
    public const EVENTS = [
        'page_view',
        'wallet_connected',
        'swap_executed',
        'liquidity_added',
    ];

    protected $fillable = [
        'session_id',
        'user_id',
        'wallet_address',
        'event',
        'page',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
