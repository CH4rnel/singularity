<?php

namespace App\Services\Slots;

use App\Models\TokenMetadataCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves SPL token metadata (symbol, name, logo, decimals, freeze authority)
 * via Helius DAS `getAsset`. Results are cached in the `token_metadata_cache`
 * table for `services.slots.metadata_ttl_hours`.
 *
 * Mints with a non-null freeze authority are flagged — slot pool tokens with
 * `has_freeze_authority = true` should be rejected at the whitelist layer
 * because the authority can freeze the hot wallet's ATA and lock the pool.
 */
class TokenMetadataService
{
    public function fetch(string $mint, bool $forceRefresh = false): ?TokenMetadataCache
    {
        $cache = TokenMetadataCache::find($mint);
        $ttlHours = (int) config('services.slots.metadata_ttl_hours', 24);

        if (! $forceRefresh && $cache && $cache->fetched_at && $cache->fetched_at->gt(now()->subHours($ttlHours))) {
            return $cache;
        }

        $rpc = config('services.slots.rpc_url');

        try {
            $response = Http::timeout(15)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 'slot-meta',
                'method' => 'getAsset',
                'params' => ['id' => $mint],
            ]);

            if (! $response->successful()) {
                Log::warning('Slots: getAsset HTTP error', ['mint' => $mint, 'status' => $response->status()]);

                return $cache;
            }

            $result = $response->json('result');

            if (! is_array($result)) {
                return $cache;
            }
        } catch (\Throwable $e) {
            Log::warning('Slots: getAsset failed', ['mint' => $mint, 'error' => $e->getMessage()]);

            return $cache;
        }

        $symbol = data_get($result, 'content.metadata.symbol')
            ?? data_get($result, 'token_info.symbol');
        $name = data_get($result, 'content.metadata.name');
        $logo = data_get($result, 'content.links.image')
            ?? data_get($result, 'content.files.0.uri');
        $decimals = data_get($result, 'token_info.decimals');
        $programId = data_get($result, 'token_info.token_program');
        $tokenProgram = $this->mapTokenProgram($programId);
        $freezeAuth = data_get($result, 'token_info.freeze_authority')
            ?? data_get($result, 'authorities.0.address');
        $hasFreeze = ! empty(data_get($result, 'token_info.freeze_authority'));

        return TokenMetadataCache::updateOrCreate(
            ['mint' => $mint],
            [
                'symbol' => $symbol ? (string) $symbol : null,
                'name' => $name ? (string) $name : null,
                'logo_url' => $logo ? (string) $logo : null,
                'decimals' => is_numeric($decimals) ? (int) $decimals : null,
                'token_program' => $tokenProgram,
                'has_freeze_authority' => (bool) $hasFreeze,
                'raw' => $result,
                'fetched_at' => now(),
            ]
        );
    }

    private function mapTokenProgram(?string $programId): ?string
    {
        return match ($programId) {
            'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA' => 'token',
            'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb' => 'token-2022',
            default => $programId ? 'token' : null,
        };
    }
}
