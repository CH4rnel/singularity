<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leaf of an epoch's tree: an address, an amount and the proof for it.
 *
 * The proof is public on purpose — it lets anybody's wallet claim for the
 * address without a session, and it pays nobody but that address.
 */
class ContributionReward extends Model
{
    protected $fillable = [
        'contribution_epoch_id',
        'user_id',
        'github_id',
        'address',
        'points',
        'amount_raw',
        'leaf',
        'proof',
        'claimed_at',
        'claim_tx',
    ];

    protected function casts(): array
    {
        return [
            'proof' => 'array',
            'points' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }

    public function epoch(): BelongsTo
    {
        return $this->belongsTo(ContributionEpoch::class, 'contribution_epoch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
