<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GitHub repository whose merged work earns rewards — once it is verified.
 */
class ContributionRepository extends Model
{
    protected $fillable = [
        'full_name',
        'weight',
        'verified_at',
        'verification_error',
        'synced_at',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'verified_at' => 'datetime',
            'synced_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function url(): string
    {
        return 'https://github.com/'.$this->full_name;
    }
}
