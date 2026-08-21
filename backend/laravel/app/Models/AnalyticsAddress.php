<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address one installation asked us to check.
 *
 * Held for exactly two purposes — confirming that a wallet was funded, and
 * finding out what a sponsored drip cost — and therefore only for chains this
 * server can read without an API key (`config/analytics.php`). An address on
 * any other network buys nothing and would cost the user a linkage between
 * their installation and their on-chain identity, so it is never sent.
 */
class AnalyticsAddress extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'analytics_addresses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function analyticsUser(): BelongsTo
    {
        return $this->belongsTo(AnalyticsUser::class, 'user_id');
    }
}
