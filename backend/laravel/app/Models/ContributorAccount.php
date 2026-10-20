<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What GitHub says about one account, cached for the anti-sybil rules.
 *
 * Keyed by GitHub's numeric id: a login can be renamed and then claimed by
 * somebody else, the id cannot.
 */
class ContributorAccount extends Model
{
    protected $fillable = [
        'github_id',
        'login',
        'type',
        'github_created_at',
        'followers',
        'public_repos',
        'fetched_at',
        'banned_at',
        'ban_reason',
    ];

    protected function casts(): array
    {
        return [
            'github_created_at' => 'datetime',
            'fetched_at' => 'datetime',
            'banned_at' => 'datetime',
            'followers' => 'integer',
            'public_repos' => 'integer',
        ];
    }
}
