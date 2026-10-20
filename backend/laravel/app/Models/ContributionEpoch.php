<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One period's pool, split by points and written as a Merkle tree.
 *
 * `built` has a root nobody can claim against yet; `published` is the root the
 * contract holds. A built epoch can be rebuilt, a published one never is.
 */
class ContributionEpoch extends Model
{
    public const BUILT = 'built';

    public const PUBLISHED = 'published';

    protected $fillable = [
        'number',
        'starts_at',
        'ends_at',
        'asset',
        'decimals',
        'pool_raw',
        'allocated_raw',
        'total_points',
        'merkle_root',
        'status',
        'publish_tx',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'number' => 'integer',
            'decimals' => 'integer',
            'total_points' => 'integer',
        ];
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(ContributionReward::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }
}
