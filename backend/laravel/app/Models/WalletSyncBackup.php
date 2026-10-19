<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A profile's wallet, sealed in the browser under the sync password. See the
 * migration for what is and is not in here.
 */
class WalletSyncBackup extends Model
{
    protected $fillable = [
        'user_id',
        'address',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
