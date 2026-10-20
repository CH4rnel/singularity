<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who passed every required checkpoint of a season.
 *
 * `pending` waits for a person (`pioneer:review`), `approved` is paid the
 * completion XP and owed the badge, `rejected` keeps its checkpoint XP and
 * nothing else. `completed_at` is when the last checkpoint happened on chain,
 * not when the verifier noticed, so the board is ordered by what people did.
 */
class PioneerFinisher extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'season',
        'user_id',
        'completed_at',
        'status',
        'reviewed_at',
        'reviewed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
