<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $solana_address
 * @property string $principal_raw
 * @property string $accrued_ash_raw
 * @property string $reward_remainder
 * @property string $total_deposited_raw
 * @property string $total_withdrawn_raw
 * @property string $total_claimed_ash_raw
 * @property Carbon|null $accrued_at
 */
class SolanaStakingPosition extends Model
{
    protected $fillable = [
        'user_id',
        'solana_address',
        'principal_raw',
        'accrued_ash_raw',
        'reward_remainder',
        'total_deposited_raw',
        'total_withdrawn_raw',
        'total_claimed_ash_raw',
        'accrued_at',
    ];

    protected function casts(): array
    {
        return ['accrued_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SolanaStakingTransaction::class);
    }
}
