<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One line in the operators' room.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $author
 * @property string|null $body
 * @property bool $calls_lainos
 * @property string|null $lainos_state
 * @property string|null $lainos_note
 * @property int|null $crm_contact_id
 * @property int|null $crm_task_id
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CrmChatMessage extends Model
{
    public const AUTHOR_OPERATOR = 'operator';

    public const AUTHOR_LAINOS = 'lainos';

    /** Nobody has run the call yet — any operator may. */
    public const LAINOS_AWAITING = 'awaiting';

    public const LAINOS_ANSWERED = 'answered';

    /** The call was run and produced nothing: said so, never invented. */
    public const LAINOS_FAILED = 'failed';

    /**
     * The handle that calls the daemon.
     *
     * `@lainos` and not `@lain`, because one of the operators is called lain
     * and a handle that could mean either is a handle that will mean the
     * wrong one at three in the morning.
     */
    public const HANDLE = '@lainos';

    protected $fillable = [
        'user_id',
        'author',
        'body',
        'calls_lainos',
        'lainos_state',
        'lainos_note',
        'crm_contact_id',
        'crm_task_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'calls_lainos' => 'boolean',
            'meta' => 'array',
        ];
    }

    /** Whether this line is addressed to LainOS. */
    public static function mentionsLainos(?string $body): bool
    {
        return $body !== null
            && preg_match('/(^|\s)@lainos\b/iu', $body) === 1;
    }

    /**
     * Who wrote it, when that is a person.
     *
     * Called `sender` and not `author`: `author` is a column on this table
     * (operator or lainos), and a relation of the same name is shadowed by
     * it — the room rendered every name as a dash until this was renamed.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CrmChatFile::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'crm_contact_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CrmTask::class, 'crm_task_id');
    }

    public function isFromLainos(): bool
    {
        return $this->author === self::AUTHOR_LAINOS;
    }

    /**
     * Calls that are still unanswered.
     *
     * @param  Builder<CrmChatMessage>  $query
     */
    public function scopeUnanswered(Builder $query): void
    {
        $query->where('calls_lainos', true)
            ->whereIn('lainos_state', [self::LAINOS_AWAITING, self::LAINOS_FAILED]);
    }
}
