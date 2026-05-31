<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $status
 * @property string $hot_wallet_address
 * @property int $burn_bps
 * @property int $house_edge_bps
 * @property int $jackpot_threshold_bps
 * @property int $max_single_win_bps
 * @property int $jackpot_basket_bps
 * @property int $jackpot_basket_size
 */
class SlotPool extends Model
{
    protected $fillable = [
        'name',
        'status',
        'hot_wallet_address',
        'burn_bps',
        'house_edge_bps',
        'jackpot_threshold_bps',
        'max_single_win_bps',
        'jackpot_basket_bps',
        'jackpot_basket_size',
    ];

    protected function casts(): array
    {
        return [
            'burn_bps' => 'integer',
            'house_edge_bps' => 'integer',
            'jackpot_threshold_bps' => 'integer',
            'max_single_win_bps' => 'integer',
            'jackpot_basket_bps' => 'integer',
            'jackpot_basket_size' => 'integer',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(SlotPoolToken::class);
    }

    public function enabledTokens(): HasMany
    {
        return $this->tokens()->where('enabled', true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
