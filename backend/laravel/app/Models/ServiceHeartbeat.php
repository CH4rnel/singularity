<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last thing a host said about itself.
 *
 * Raw facts only — container states, tmux sessions, load, disk, log mtimes.
 * What any of it *means* is decided by config/monitoring.php, so the reporting
 * script never has to be redeployed when a service is added or renamed.
 */
class ServiceHeartbeat extends Model
{
    protected $fillable = [
        'host',
        'payload',
        'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'reported_at' => 'datetime',
        ];
    }
}
