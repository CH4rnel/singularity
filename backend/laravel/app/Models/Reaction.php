<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reaction extends Model
{
    /** Fixed palette accepted by ReactionController::toggle. */
    public const PALETTE = ['👍', '🔥', '❤️', '👀', '🚀'];

    protected $fillable = [
        'user_id',
        'reactable_type',
        'reactable_id',
        'emoji',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }
}
