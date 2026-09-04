<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One client in one swarm, for as long as it keeps saying so.
 *
 * These rows are not history. A peer that stops announcing is deleted, because
 * a tracker's only job is to hand a new joiner addresses that answer — a list
 * padded with yesterday's clients is a list that looks healthy and connects to
 * nobody.
 */
class TrackerPeer extends Model
{
    protected $fillable = [
        'info_hash',
        'peer_id',
        'ip',
        'port',
        'left_bytes',
        'uploaded',
        'downloaded',
        'seeder',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'left_bytes' => 'integer',
            'uploaded' => 'integer',
            'downloaded' => 'integer',
            'seeder' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
}
