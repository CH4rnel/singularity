<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $mint
 * @property string|null $symbol
 * @property string|null $name
 * @property string|null $logo_url
 * @property int|null $decimals
 * @property string|null $token_program
 * @property bool $has_freeze_authority
 * @property array|null $raw
 * @property Carbon $fetched_at
 */
class TokenMetadataCache extends Model
{
    protected $table = 'token_metadata_cache';

    protected $primaryKey = 'mint';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'mint',
        'symbol',
        'name',
        'logo_url',
        'decimals',
        'token_program',
        'has_freeze_authority',
        'raw',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'has_freeze_authority' => 'boolean',
            'raw' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
