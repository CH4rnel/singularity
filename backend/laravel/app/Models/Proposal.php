<?php

namespace App\Models;

use App\Support\Markdown;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    protected $fillable = [
        'dao_id',
        'user_id',
        'title',
        'description',
        'ends_at',
    ];

    /** Computed status keeps the JSON shape of the old stored column. */
    protected $appends = ['status', 'description_html'];

    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Purge feed entries and reactions pointing at this proposal and at
        // its comments/votes: those children are removed by DB-level cascades,
        // so their model events never fire.
        static::deleting(function (Proposal $proposal) {
            $commentIds = $proposal->comments()->pluck('id');
            $voteIds = $proposal->votes()->pluck('id');

            Activity::query()
                ->where(fn ($query) => $query
                    ->where('subject_type', self::class)
                    ->where('subject_id', $proposal->id))
                ->orWhere(fn ($query) => $query
                    ->where('subject_type', ProposalComment::class)
                    ->whereIn('subject_id', $commentIds))
                ->orWhere(fn ($query) => $query
                    ->where('subject_type', ProposalVote::class)
                    ->whereIn('subject_id', $voteIds))
                ->delete();

            Reaction::query()
                ->where(fn ($query) => $query
                    ->where('reactable_type', self::class)
                    ->where('reactable_id', $proposal->id))
                ->orWhere(fn ($query) => $query
                    ->where('reactable_type', ProposalComment::class)
                    ->whereIn('reactable_id', $commentIds))
                ->delete();
        });
    }

    /**
     * Voting status derived from the deadline — open while ends_at is null or
     * in the future. Computed at read time so no scheduler is needed.
     */
    protected function status(): Attribute
    {
        return Attribute::get(
            fn () => $this->isOpen() ? 'open' : 'closed',
        );
    }

    protected function descriptionHtml(): Attribute
    {
        return Attribute::get(
            fn () => Markdown::toSafeHtml($this->description),
        );
    }

    public function isOpen(): bool
    {
        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    public function dao(): BelongsTo
    {
        return $this->belongsTo(Dao::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProposalComment::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ProposalVote::class);
    }

    public function votesFor(): HasMany
    {
        return $this->votes()->where('support', true);
    }

    public function votesAgainst(): HasMany
    {
        return $this->votes()->where('support', false);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ProposalSnapshot::class);
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }
}
