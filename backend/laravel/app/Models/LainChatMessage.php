<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LainChatMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_LAIN = 'lain';

    /** Context boundary: the model only sees rows after the latest reset. */
    public const ROLE_RESET = 'reset';

    protected $fillable = [
        'user_id',
        'role',
        'content',
        'model',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rows of the user's current conversation (after their latest reset). */
    public static function currentConversation(int $userId): Builder
    {
        $query = static::query()->where('user_id', $userId);
        $lastReset = static::query()
            ->where('user_id', $userId)
            ->where('role', self::ROLE_RESET)
            ->max('id');

        if ($lastReset !== null) {
            $query->where('id', '>', $lastReset);
        }

        return $query->orderBy('id');
    }
}
