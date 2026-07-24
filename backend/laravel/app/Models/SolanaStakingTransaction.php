<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $solana_staking_position_id
 * @property int $user_id
 * @property string $type
 * @property string $amount_raw
 * @property string|null $tx_hash
 * @property string $status
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 */
class SolanaStakingTransaction extends Model
{
    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_REWARD_CLAIM = 'reward_claim';

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $fillable = [
        'uuid',
        'solana_staking_position_id',
        'user_id',
        'type',
        'amount_raw',
        'tx_hash',
        'status',
        'error_message',
        'metadata',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(SolanaStakingPosition::class, 'solana_staking_position_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
