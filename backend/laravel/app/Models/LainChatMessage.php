<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LainChatMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_LAIN = 'lain';

    protected $fillable = [
        'user_id',
        'session_id',
        'role',
        'content',
        'model',
    ];

    /** New messages bump the session's updated_at, which orders the list. */
    protected $touches = ['session'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LainChatSession::class, 'session_id');
    }
}
