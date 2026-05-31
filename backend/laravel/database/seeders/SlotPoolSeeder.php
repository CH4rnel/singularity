<?php

namespace Database\Seeders;

use App\Models\SlotPool;
use Illuminate\Database\Seeder;

class SlotPoolSeeder extends Seeder
{
    public function run(): void
    {
        $hotWallet = config('services.slots.hot_wallet_address');

        SlotPool::firstOrCreate(
            ['name' => 'pump.fun'],
            [
                'status' => $hotWallet ? 'active' : 'paused',
                'hot_wallet_address' => $hotWallet,
                'burn_bps' => (int) config('services.slots.burn_bps', 200),
                'house_edge_bps' => (int) config('services.slots.house_edge_bps', 400),
                'jackpot_threshold_bps' => (int) config('services.slots.jackpot_threshold_bps', 10),
                'max_single_win_bps' => (int) config('services.slots.max_single_win_bps', 2000),
                'jackpot_basket_bps' => 2500,
                'jackpot_basket_size' => 5,
            ]
        );
    }
}
