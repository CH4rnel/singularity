<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A stretch of time during which a service was not healthy.
 *
 * Open while `resolved_at` is null. There is at most one open incident per
 * service, which is what makes "alert once" expressible: the incident row is
 * the memory, so a restarted scheduler or a flushed cache cannot turn one
 * outage into a stream of identical messages.
 */
class ServiceIncident extends Model
{
    protected $fillable = [
        'service',
        'status',
        'reason',
        'detail',
        'started_at',
        'resolved_at',
        'notified_at',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** Seconds the incident lasted, or has lasted so far. */
    public function durationSeconds(): int
    {
        return ($this->resolved_at ?? now())->diffInSeconds($this->started_at, true);
    }
}
