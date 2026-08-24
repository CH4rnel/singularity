<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded event.
 *
 * `event_id` is the client's own idempotency key and carries a unique index:
 * a retried flush, a replayed outbox and a duplicated beacon all collide with
 * a row that is already here. `created_at` is server time — the client's clock
 * is kept beside it in `client_time` and never used for anything a dashboard
 * reads, because a device with a wrong clock would otherwise move a cohort.
 */
class AnalyticsEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'analytics_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
            'client_time' => 'datetime',
        ];
    }

    public function analyticsUser(): BelongsTo
    {
        return $this->belongsTo(AnalyticsUser::class, 'user_id');
    }
}
