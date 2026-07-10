<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's personal CEX-style deposit address on one external chain
 * (bitcoin/litecoin/yenten/monero). Issued by UserDepositAddressService;
 * once issued the address is honored forever, so rows are never rewritten.
 */
class UserDepositAddress extends Model
{
    protected $fillable = [
        'user_id',
        'chain',
        'address',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
