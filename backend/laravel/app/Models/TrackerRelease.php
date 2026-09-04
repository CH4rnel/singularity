<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One release: a torrent that exists on the index because a token names it.
 *
 * Everything on this row except the swarm counters was read off the chain and
 * out of the document the token points at — the submitter's part was naming a
 * token id. That is the whole trust model, and it is why there is no `user_id`
 * here: the owner is an address, and it changes when the token is sold.
 */
class TrackerRelease extends Model
{
    protected $fillable = [
        'info_hash',
        'name',
        'description',
        'category',
        'size_bytes',
        'file_count',
        'files',
        'magnet',
        'chain_id',
        'contract',
        'token_id',
        'owner',
        'token_uri',
        'preview_url',
        'cover_url',
        'media_kind',
    ];

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'size_bytes' => 'integer',
            'file_count' => 'integer',
            'chain_id' => 'integer',
            'seeders' => 'integer',
            'leechers' => 'integer',
            'completed' => 'integer',
            'last_announce_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    public function peers(): HasMany
    {
        return $this->hasMany(TrackerPeer::class, 'info_hash', 'info_hash');
    }

    /** What the index and the announce endpoint both mean by "a release". */
    public function scopeListed(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }

    /**
     * The row as the index, the wallet and the site all read it.
     *
     * One shape for every reader, because three shapes is three places for
     * `seeders` to mean something slightly different.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $chain = config("tracker.chains.{$this->chain_id}");

        return [
            'info_hash' => $this->info_hash,
            'name' => $this->name,
            'description' => $this->description ?? '',
            'category' => $this->category,
            'size_bytes' => $this->size_bytes,
            'file_count' => $this->file_count,
            'files' => array_slice($this->files ?? [], 0, 500),
            'magnet' => $this->magnet,
            'media_kind' => $this->media_kind,
            'preview_url' => $this->preview_url,
            'cover_url' => $this->cover_url,
            'seeders' => $this->seeders,
            'leechers' => $this->leechers,
            'completed' => $this->completed,
            'chain_id' => $this->chain_id,
            'contract' => $this->contract,
            'token_id' => $this->token_id,
            'owner' => $this->owner,
            'token_uri' => $this->token_uri,
            // The token is the release's proof, so the way to check it is part
            // of the row rather than something a reader has to assemble.
            'token_url' => isset($chain['explorer_url'])
                ? rtrim((string) $chain['explorer_url'], '/')."/token/{$this->contract}/instance/{$this->token_id}"
                : null,
            'published_at' => $this->created_at?->toIso8601String(),
            'last_announce_at' => $this->last_announce_at?->toIso8601String(),
        ];
    }
}
