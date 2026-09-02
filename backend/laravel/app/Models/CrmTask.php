<?php

namespace App\Models;

use Database\Factories\CrmTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $external_id
 * @property int|null $crm_contact_id
 * @property int|null $assigned_to_user_id
 * @property int|null $created_by_user_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CrmTask extends Model
{
    /** @use HasFactory<CrmTaskFactory> */
    use HasFactory;

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    /** Statuses that still need someone to do something. */
    public const ACTIVE_STATUSES = ['open', 'in_progress'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'external_id',
        'crm_contact_id',
        'assigned_to_user_id',
        'created_by_user_id',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Keep completed_at in lockstep with the status, whichever entry point
     * (controller, seeder, console) changed it.
     */
    protected static function booted(): void
    {
        static::saving(function (CrmTask $task): void {
            if ($task->status === 'done') {
                $task->completed_at ??= now();
            } else {
                $task->completed_at = null;
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'crm_contact_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<CrmTaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(CrmTaskComment::class)->oldest();
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->isActive() && $this->due_at->isPast();
    }

    /**
     * Open or in-progress tasks.
     *
     * @param  Builder<CrmTask>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * Active tasks whose due date has passed.
     *
     * @param  Builder<CrmTask>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->active()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    /**
     * Order by priority, then soonest due date, with undated tasks last.
     *
     * @param  Builder<CrmTask>  $query
     */
    public function scopeByDueDate(Builder $query): void
    {
        $query->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 WHEN 'low' THEN 2 ELSE 3 END")
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByDesc('id');
    }

    /**
     * Filter by assignee: a user id, "me" for the current operator, or
     * "unassigned" for tasks nobody picked up.
     *
     * @param  Builder<CrmTask>  $query
     */
    public function scopeAssignee(Builder $query, ?string $assignee, ?int $currentUserId): void
    {
        match (true) {
            $assignee === null || $assignee === '' => null,
            $assignee === 'unassigned' => $query->whereNull('assigned_to_user_id'),
            $assignee === 'me' => $query->where('assigned_to_user_id', $currentUserId),
            ctype_digit($assignee) => $query->where('assigned_to_user_id', (int) $assignee),
            default => null,
        };
    }
}
