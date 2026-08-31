<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One x402 payment: verified when the row is written, settled when it is stamped.
 *
 * The identity of a payer here is an address and nothing else — no account, no
 * key, no session. That is the point of the protocol, and it is also the limit
 * of what this row may ever say about a person.
 *
 * @property string $resource
 * @property string $payer
 * @property string $network
 * @property string $scheme
 * @property string $asset
 * @property string $amount
 * @property ?string $transaction
 * @property ?Carbon $settled_at
 * @property Carbon $created_at
 */
class X402Payment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'resource',
        'payer',
        'network',
        'scheme',
        'asset',
        'amount',
        'transaction',
        'settled_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    public function settled(): bool
    {
        return $this->settled_at !== null;
    }

    /** @return HasMany<AiApiRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(AiApiRequest::class, 'x402_payment_id');
    }
}
