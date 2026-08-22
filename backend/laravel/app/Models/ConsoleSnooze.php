<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property Carbon $snoozed_until
 * @property int|null $user_id
 */
class ConsoleSnooze extends Model
{
    protected $fillable = ['key', 'snoozed_until', 'user_id'];

    protected function casts(): array
    {
        return ['snoozed_until' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Snoozes that have not woken up yet.
     *
     * @param  Builder<ConsoleSnooze>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('snoozed_until', '>', now());
    }
}
