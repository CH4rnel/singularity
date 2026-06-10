<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $slot_pool_id
 * @property string $wallet_address
 * @property string $bet_mint
 * @property string $bet_amount
 * @property string $deposit_address
 * @property string|null $deposit_tx_hash
 * @property string|null $server_seed
 * @property string $server_seed_hash
 * @property string $client_seed
 * @property int $nonce
 * @property array|null $reels
 * @property string $outcome_type
 * @property array|null $prize_payload
 * @property string|null $payout_tx_hash
 * @property string|null $burn_amount
 * @property string $status
 * @property string|null $error_message
 * @property Carbon|null $prepared_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $expires_at
 */
class SlotSpin extends Model
{
    public const STATUS_PREPARED = 'prepared';

    public const STATUS_DEPOSIT_SEEN = 'deposit_seen';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_LOSS = 'loss';

    public const OUTCOME_WIN = 'win';

    public const OUTCOME_JACKPOT = 'jackpot';

    protected $fillable = [
        'user_id',
        'slot_pool_id',
        'wallet_address',
        'bet_mint',
        'bet_amount',
        'deposit_address',
        'deposit_tx_hash',
        'server_seed',
        'server_seed_hash',
        'client_seed',
        'nonce',
        'reels',
        'outcome_type',
        'prize_payload',
        'payout_tx_hash',
        'burn_amount',
        'status',
        'error_message',
        'prepared_at',
        'confirmed_at',
        'settled_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'nonce' => 'integer',
            'reels' => 'array',
            'prize_payload' => 'array',
            'prepared_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'settled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(SlotPool::class, 'slot_pool_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
