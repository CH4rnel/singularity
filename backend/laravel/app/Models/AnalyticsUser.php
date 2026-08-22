<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One anonymous installation of the wallet.
 *
 * The primary key is a UUID the client generated on its first run and keeps in
 * local storage. It is not an address, not an account and not a device
 * fingerprint — the only thing that makes two events the same person is that
 * the same installation sent both.
 *
 * The milestone columns (`wallet_created_at`, `funded_at`, `activated_at`,
 * `first_transaction_at`) are write-once. Every one of them is the numerator
 * of a headline metric, and a milestone that can move backwards is a metric
 * that can be inflated by a client replaying its outbox.
 */
class AnalyticsUser extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'analytics_users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'wallet_created_at' => 'datetime',
            'funded_at' => 'datetime',
            'activated_at' => 'datetime',
            'first_transaction_at' => 'datetime',
            'internal_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AnalyticsSession::class, 'user_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(AnalyticsAddress::class, 'user_id');
    }
}
