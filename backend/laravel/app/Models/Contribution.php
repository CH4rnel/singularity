<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One merged pull request or accepted issue, with the verdict on it.
 *
 * `points` are hundredths of a point. `status` is `counted` or `rejected`;
 * a refusal always carries its `reason`, so nobody's work disappears without
 * a sentence saying why.
 */
class Contribution extends Model
{
    public const COUNTED = 'counted';

    public const REJECTED = 'rejected';

    public const PULL_REQUEST = 'pull_request';

    public const DOCUMENTATION = 'documentation';

    public const ISSUE = 'issue';

    protected $fillable = [
        'contribution_repository_id',
        'github_id',
        'login',
        'kind',
        'number',
        'title',
        'url',
        'additions',
        'deletions',
        'commits',
        'merged_by',
        'accepted_at',
        'points',
        'status',
        'reason',
        'reviewed_at',
        'contribution_epoch_id',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'points' => 'integer',
            'additions' => 'integer',
            'deletions' => 'integer',
            'commits' => 'integer',
            'number' => 'integer',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(ContributionRepository::class, 'contribution_repository_id');
    }

    public function epoch(): BelongsTo
    {
        return $this->belongsTo(ContributionEpoch::class, 'contribution_epoch_id');
    }
}
