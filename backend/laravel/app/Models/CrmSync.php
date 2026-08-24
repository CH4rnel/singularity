<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One run of the CRM import, kept so the console can say how old the base is.
 *
 * @property int $id
 * @property string $trigger
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property array<string, int>|null $counts
 * @property int $added
 * @property int $sold
 * @property string|null $note
 */
class CrmSync extends Model
{
    /** A run that could not read every source it was supposed to. */
    public const NOTE_HOLDERS_UNREADABLE = 'holders_unreadable';

    protected $fillable = [
        'trigger',
        'started_at',
        'finished_at',
        'counts',
        'added',
        'sold',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counts' => 'array',
        ];
    }

    /** A run that never finished is a run that died halfway. */
    public function isComplete(): bool
    {
        return $this->finished_at !== null && $this->note === null;
    }
}
