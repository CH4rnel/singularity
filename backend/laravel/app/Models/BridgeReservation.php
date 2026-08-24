<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One claim on a destination inventory pool. See the migration for why this
 * table exists and what each status means.
 *
 * @property int $id
 * @property string $reference
 * @property string $pool
 * @property string $direction
 * @property string $token
 * @property string $net_raw
 * @property int $decimals
 * @property string $amount
 * @property string|null $sender_address
 * @property string $recipient_address
 * @property int|null $bridge_request_id
 * @property string $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $released_at
 * @property string|null $release_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BridgeReservation extends Model
{
    public const PENDING_SOURCE = 'pending_source';

    public const COMMITTED = 'committed';

    public const SETTLED = 'settled';

    public const RELEASED = 'released';

    protected $fillable = [
        'reference',
        'pool',
        'direction',
        'token',
        'net_raw',
        'decimals',
        'amount',
        'sender_address',
        'recipient_address',
        'bridge_request_id',
        'status',
        'expires_at',
        'committed_at',
        'settled_at',
        'released_at',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'expires_at' => 'datetime',
            'committed_at' => 'datetime',
            'settled_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function bridgeRequest(): BelongsTo
    {
        return $this->belongsTo(BridgeRequest::class);
    }

    /**
     * Claims that still stand against a pool's live balance: a committed
     * obligation, or an unexpired pre-signature hold.
     *
     * The expiry is applied here rather than only by the sweeper, so capacity
     * comes back the instant a hold lapses instead of at the next cron tick.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', self::COMMITTED)
                ->orWhere(function (Builder $pending) {
                    $pending->where('status', self::PENDING_SOURCE)
                        ->where(function (Builder $window) {
                            $window->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
        });
    }

    public function isCommitted(): bool
    {
        return $this->status === self::COMMITTED;
    }

    public function hasExpired(): bool
    {
        return $this->status === self::PENDING_SOURCE
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
