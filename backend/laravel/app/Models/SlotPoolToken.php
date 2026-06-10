<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $slot_pool_id
 * @property string $mint
 * @property string $token_program
 * @property int $decimals
 * @property string|null $symbol
 * @property string|null $logo_url
 * @property string $current_balance
 * @property bool $enabled
 * @property string $min_bet
 * @property string|null $max_bet
 * @property int|null $weight_override
 * @property string $source
 * @property string|null $pumpfun_market_cap_usd
 * @property Carbon|null $pumpfun_last_seen_at
 */
class SlotPoolToken extends Model
{
    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_PUMPFUN_BULK = 'pumpfun_bulk';

    public const SOURCE_PUMPFUN_LAZY = 'pumpfun_lazy';

    protected $fillable = [
        'slot_pool_id',
        'mint',
        'token_program',
        'decimals',
        'symbol',
        'logo_url',
        'current_balance',
        'enabled',
        'min_bet',
        'max_bet',
        'weight_override',
        'source',
        'pumpfun_market_cap_usd',
        'pumpfun_last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'enabled' => 'boolean',
            'weight_override' => 'integer',
            'pumpfun_market_cap_usd' => 'decimal:2',
            'pumpfun_last_seen_at' => 'datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(SlotPool::class, 'slot_pool_id');
    }
}
