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
 * @property string|null $source_tx_hash
 * @property int $source_nonce
 * @property string|null $sender_address
 * @property string $recipient_address
 * @property string|null $deposit_address
 * @property string|null $deposit_wif
 * @property bool $swept
 * @property bool $wrapper_burned
 * @property string $amount
 * @property string|null $fee_amount
 * @property string|null $fee_usd
 * @property bool $gas_drop_planned
 * @property string|null $gas_drop_amount
 * @property bool $convert_to_native
 * @property bool|null $converted
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
        'deposit_address',
        'deposit_wif',
        'swept',
        'wrapper_burned',
        'amount',
        'fee_amount',
        'fee_usd',
        'gas_drop_planned',
        'gas_drop_amount',
        'convert_to_native',
        'converted',
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
            'convert_to_native' => 'boolean',
            'converted' => 'boolean',
            'deposit_wif' => 'encrypted',
            'swept' => 'boolean',
            'wrapper_burned' => 'boolean',
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

    public function isAwaitingDeposit(): bool
    {
        return $this->status === 'awaiting_deposit';
    }

    public function markAwaitingDeposit(): void
    {
        $this->update(['status' => 'awaiting_deposit']);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function markExpired(): void
    {
        $this->update(['status' => 'expired']);
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
