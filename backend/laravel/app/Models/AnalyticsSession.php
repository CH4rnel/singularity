<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A visit: everything one installation did before it went quiet for longer
 * than the session timeout. The boundary is decided on the client, because it
 * is the only side that can tell an idle app from an offline one.
 */
class AnalyticsSession extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'analytics_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
