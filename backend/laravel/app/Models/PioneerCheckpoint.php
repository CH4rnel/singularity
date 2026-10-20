<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Pioneer Season checkpoint a person passed, with the record that proved
 * it (`swap:0x…`, `bridge:42`, `wallet:0x…`). Written only by
 * PioneerSeasonService, never from a request.
 */
class PioneerCheckpoint extends Model
{
    protected $fillable = [
        'season',
        'user_id',
        'checkpoint',
        'reference',
        'qualified_at',
    ];

    protected function casts(): array
    {
        return [
            'checkpoint' => 'integer',
            'qualified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
