<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $direction
 * @property string $token
 * @property string $source_chain
 * @property string $source_tx_hash
 * @property int $source_nonce
 * @property string $sender_address
 * @property string $recipient_address
 * @property string $amount
 * @property string|null $fee_amount
 * @property string|null $fee_usd
 * @property bool $gas_drop_planned
 * @property string|null $gas_drop_amount
 * @property string $status
 * @property string|null $destination_tx_hash
 * @property string|null $error_message
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BridgeRequest extends Model
{
    protected $fillable = [
        'user_id',
        'direction',
        'token',
        'source_chain',
        'source_tx_hash',
        'source_nonce',
        'sender_address',
        'recipient_address',
        'amount',
        'fee_amount',
        'fee_usd',
        'gas_drop_planned',
        'gas_drop_amount',
        'status',
        'destination_tx_hash',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:18',
            'fee_amount' => 'decimal:18',
            'fee_usd' => 'decimal:8',
            'gas_drop_planned' => 'boolean',
            'gas_drop_amount' => 'decimal:18',
            'source_nonce' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markCompleted(string $destinationTxHash): void
    {
        $this->update([
            'status' => 'completed',
            'destination_tx_hash' => $destinationTxHash,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }
}
