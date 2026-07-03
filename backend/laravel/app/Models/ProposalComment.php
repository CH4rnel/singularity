<?php

namespace App\Models;

use Database\Factories\ProposalCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProposalComment extends Model
{
    /** @use HasFactory<ProposalCommentFactory> */
    use HasFactory;

    protected $fillable = [
        'proposal_id',
        'user_id',
        'parent_id',
        'body',
    ];

    protected static function booted(): void
    {
        // Replies are removed by the DB cascade, so clean their feed entries
        // and reactions here alongside this comment's own.
        static::deleting(function (ProposalComment $comment) {
            $ids = [$comment->id, ...$comment->replies()->pluck('id')];

            Activity::where('subject_type', self::class)
                ->whereIn('subject_id', $ids)
                ->delete();

            Reaction::where('reactable_type', self::class)
                ->whereIn('reactable_id', $ids)
                ->delete();
        });
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }
}
