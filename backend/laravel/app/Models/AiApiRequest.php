<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One metered call: which key or which payment, which model, how many tokens,
 * what outcome.
 *
 * Deliberately contentless. It is what a quota and an invoice need and nothing
 * more — no prompt, no completion, not even their lengths in characters.
 *
 * Exactly one of `ai_api_key_id` and `x402_payment_id` is set: a caller either
 * held a key or paid for this call, and the two doors never overlap.
 *
 * @property ?int $ai_api_key_id
 * @property ?int $x402_payment_id
 * @property string $model
 * @property string $served_model
 * @property string $provider
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $status
 * @property bool $streamed
 */
class AiApiRequest extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ai_api_key_id',
        'x402_payment_id',
        'model',
        'served_model',
        'provider',
        'prompt_tokens',
        'completion_tokens',
        'status',
        'streamed',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'streamed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiApiKey, $this> */
    public function key(): BelongsTo
    {
        return $this->belongsTo(AiApiKey::class, 'ai_api_key_id');
    }

    /** @return BelongsTo<X402Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(X402Payment::class, 'x402_payment_id');
    }
}
