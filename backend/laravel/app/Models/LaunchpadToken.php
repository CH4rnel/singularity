<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaunchpadToken extends Model
{
    protected $fillable = [
        'chain_id',
        'address',
        'creator',
        'name',
        'symbol',
        'description',
        'image_path',
        'html_path',
        'site_subdomain',
    ];

    /** A token launched on several chains has one row per chain. */
    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
        ];
    }
}
