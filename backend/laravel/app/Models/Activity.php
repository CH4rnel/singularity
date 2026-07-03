<?php

namespace App\Models;

use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single entry of the DAO activity feed (proposal.created / vote.cast /
 * comment.posted). Append-only; created_at managed by Eloquent, no updated_at.
 */
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'type',
        'user_id',
        'dao_id',
        'subject_type',
        'subject_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dao(): BelongsTo
    {
        return $this->belongsTo(Dao::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
