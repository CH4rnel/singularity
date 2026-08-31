<?php

namespace App\Models;

use Database\Factories\CrmMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the correspondence with one person.
 *
 * A note is what an operator thought; this is what was said. The difference
 * matters because only one of them has two sides: the reply is the evidence
 * that anybody is on the other end, and every question worth asking about a
 * conversation — how long since we wrote, whether they answer, how fast —
 * needs the direction of each line and the time it was actually said.
 *
 * @property int $id
 * @property int $crm_contact_id
 * @property int|null $user_id
 * @property string $direction
 * @property string $channel
 * @property string $body
 * @property string|null $author_name
 * @property Carbon $sent_at
 * @property string|null $external_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CrmMessage extends Model
{
    /** @use HasFactory<CrmMessageFactory> */
    use HasFactory;

    /** `out` is ours, `in` is theirs. There is no third party in a dossier. */
    public const DIRECTIONS = ['out', 'in'];

    /**
     * Where it was said. Two of these are what the operators use today, the
     * rest are what an import will bring; `other` exists so a conversation
     * held somewhere unforeseen is still written down rather than not.
     */
    public const CHANNELS = ['telegram', 'discord', 'x', 'email', 'call', 'other'];

    protected $fillable = [
        'crm_contact_id',
        'user_id',
        'direction',
        'channel',
        'body',
        'author_name',
        'sent_at',
        'external_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'crm_contact_id');
    }

    /** The operator who wrote the line down — never the person it is with. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'out';
    }

    /**
     * Oldest first — the order a conversation is read in.
     *
     * `id` breaks ties because an import can land a whole exchange on one
     * timestamp, and a room where two lines swap places between reads is a
     * room nobody can quote.
     *
     * @param  Builder<CrmMessage>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('sent_at')->orderBy('id');
    }
}
