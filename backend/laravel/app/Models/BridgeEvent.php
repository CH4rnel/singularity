<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BridgeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'session_id',
        'user_id',
        'bridge_request_id',
        'event_type',
        'direction',
        'amount',
        'source_address',
        'destination_address',
        'error_message',
        'metadata',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:18',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bridgeRequest(): BelongsTo
    {
        return $this->belongsTo(BridgeRequest::class);
    }
}
