<?php

namespace Database\Seeders;

use App\Models\SlotPool;
use App\Models\SlotPoolToken;
use Illuminate\Database\Seeder;

/**
 * Seeds Circle's devnet test stablecoins (USDC + EURC) into the active slot
 * pool so reels have content while iterating on devnet.
 *
 * Run manually: `php artisan db:seed --class=Database\\Seeders\\DevnetStablesSeeder`.
 * Not wired into DatabaseSeeder — only relevant when SLOT_CLUSTER=devnet.
 *
 * Bypasses TokenMetadataService because api.devnet.solana.com does not serve
 * Helius DAS getAsset; metadata is hardcoded from Circle's public docs.
 */
class DevnetStablesSeeder extends Seeder
{
    public function run(): void
    {
        $pool = SlotPool::query()->first();

        if (! $pool) {
            $this->command?->error('No SlotPool row found. Run SlotPoolSeeder first.');

            return;
        }

        if ($pool->status !== 'active') {
            $pool->update(['status' => 'active']);
            $this->command?->info('Activated pool '.$pool->name);
        }

        $tokens = [
            [
                'mint' => '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU',
                'symbol' => 'USDC',
                'logo_url' => 'https://raw.githubusercontent.com/solana-labs/token-list/main/assets/mainnet/EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v/logo.png',
            ],
            [
                'mint' => 'HzwqbKZw8HxMN6bF2yFZNrht3c2iXXzpKcFu7uBEDKtr',
                'symbol' => 'EURC',
                'logo_url' => 'https://assets.coingecko.com/coins/images/26045/standard/euro-coin.png',
            ],
        ];

        foreach ($tokens as $t) {
            SlotPoolToken::updateOrCreate(
                ['slot_pool_id' => $pool->id, 'mint' => $t['mint']],
                [
                    'token_program' => 'token',
                    'decimals' => 6,
                    'symbol' => $t['symbol'],
                    'logo_url' => $t['logo_url'],
                    'enabled' => true,
                    'current_balance' => '0',
                    'min_bet' => '100000',     // 0.1 token
                    'max_bet' => '100000000',  // 100 token
                    'source' => SlotPoolToken::SOURCE_ADMIN,
                ]
            );

            $this->command?->info("Whitelisted {$t['symbol']} ({$t['mint']})");
        }
    }
}
