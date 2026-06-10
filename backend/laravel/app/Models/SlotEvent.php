<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'slot_spin_id',
        'event_type',
        'error_message',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function spin(): BelongsTo
    {
        return $this->belongsTo(SlotSpin::class, 'slot_spin_id');
    }
}
