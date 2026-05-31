<?php

namespace App\Services\Slots;

use App\Models\SlotPool;
use App\Models\SlotPoolToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages the slot pool: token whitelist, on-chain balance sync, and the
 * normalized reel weights that drive outcome probability.
 */
class SlotPoolService
{
    public function __construct(
        private readonly TokenMetadataService $metadata,
        private readonly PumpfunDiscoveryService $pumpfun,
    ) {}

    public function activePool(): ?SlotPool
    {
        return SlotPool::query()->where('status', 'active')->first();
    }

    /**
     * Add a mint to the pool's whitelist. Pulls metadata, refuses to enable
     * tokens that have a freeze authority (those can lock the hot wallet ATA).
     *
     * @param  string  $source  one of SlotPoolToken::SOURCE_* — distinguishes
     *                          admin entries from bulk/lazy pump.fun imports.
     * @param  array<string,mixed>  $extras  optional fields to merge
     *                                       (pumpfun_market_cap_usd, etc.).
     */
    public function whitelistToken(
        SlotPool $pool,
        string $mint,
        bool $autoEnable = false,
        string $source = SlotPoolToken::SOURCE_ADMIN,
        array $extras = [],
    ): SlotPoolToken {
        $meta = $this->metadata->fetch($mint, forceRefresh: true);

        if (! $meta) {
            throw new \RuntimeException("Failed to fetch metadata for {$mint}");
        }

        $enabled = $autoEnable && ! $meta->has_freeze_authority;

        $existing = SlotPoolToken::where('slot_pool_id', $pool->id)
            ->where('mint', $mint)
            ->first();

        $attributes = array_merge([
            'token_program' => $meta->token_program ?? 'token',
            'decimals' => $meta->decimals ?? 0,
            'symbol' => $meta->symbol,
            'logo_url' => $meta->logo_url,
            'enabled' => $enabled,
            // Preserve an existing non-admin source; only set on creation.
            'source' => $existing?->source ?? $source,
        ], $extras);

        return SlotPoolToken::updateOrCreate(
            ['slot_pool_id' => $pool->id, 'mint' => $mint],
            $attributes,
        );
    }

    /**
     * Lazy auto-whitelist hook: verify the mint is a pump.fun token, then
     * call whitelistToken. Returns null if pump.fun didn't recognize the mint
     * or the discovery API was unreachable — callers should fall back to the
     * normal "not whitelisted" rejection.
     */
    public function tryLazyWhitelist(SlotPool $pool, string $mint): ?SlotPoolToken
    {
        if (! config('services.slots.pumpfun_lazy_enabled')) {
            return null;
        }

        $coin = $this->pumpfun->verifyMint($mint);

        if ($coin === null) {
            return null;
        }

        try {
            $token = $this->whitelistToken(
                pool: $pool,
                mint: $mint,
                autoEnable: (bool) config('services.slots.pumpfun_auto_enable', true),
                source: SlotPoolToken::SOURCE_PUMPFUN_LAZY,
                extras: [
                    'pumpfun_market_cap_usd' => isset($coin['usd_market_cap']) ? (string) $coin['usd_market_cap'] : null,
                    'pumpfun_last_seen_at' => now(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Slots: lazy whitelist failed', ['mint' => $mint, 'error' => $e->getMessage()]);

            return null;
        }

        return $token->enabled ? $token : null;
    }

    /**
     * Eligibility check for a bet: the mint must be whitelisted, enabled, and
     * the amount within configured per-mint bounds. If the mint isn't on the
     * whitelist yet and pump.fun lazy mode is on, this will try to add it.
     */
    public function assertBetAllowed(SlotPool $pool, string $mint, string $amountRaw): SlotPoolToken
    {
        $token = $pool->tokens()->where('mint', $mint)->first();

        if ($token && ! $token->enabled) {
            throw new \DomainException('Token not whitelisted');
        }

        if (! $token) {
            $token = $this->tryLazyWhitelist($pool, $mint);
        }

        if (! $token || ! $token->enabled) {
            throw new \DomainException('Token not whitelisted');
        }

        if (bccomp($amountRaw, $token->min_bet, 0) < 0) {
            throw new \DomainException('Bet below minimum');
        }

        if ($token->max_bet !== null && bccomp($amountRaw, $token->max_bet, 0) > 0) {
            throw new \DomainException('Bet above maximum');
        }

        return $token;
    }

    /**
     * Refresh the cached ATA balances for every enabled pool token from
     * Solana RPC. Called on a schedule and right before computing reel
     * weights so probabilities reflect on-chain reality.
     *
     * @return array<string, string> mint => raw balance
     */
    public function syncBalances(SlotPool $pool): array
    {
        $tokens = $pool->enabledTokens()->get();

        if ($tokens->isEmpty()) {
            return [];
        }

        $rpc = config('services.slots.rpc_url');

        $batch = $tokens->values()->map(fn (SlotPoolToken $t, int $i) => [
            'jsonrpc' => '2.0',
            'id' => $i,
            'method' => 'getTokenAccountsByOwner',
            'params' => [
                $pool->hot_wallet_address,
                ['mint' => $t->mint],
                ['encoding' => 'jsonParsed'],
            ],
        ])->all();

        try {
            $response = Http::timeout(20)->post($rpc, $batch);
        } catch (\Throwable $e) {
            Log::warning('Slots: balance sync RPC failed', ['error' => $e->getMessage()]);

            return $tokens->pluck('current_balance', 'mint')->all();
        }

        if (! $response->successful()) {
            Log::warning('Slots: balance sync HTTP error', ['status' => $response->status()]);

            return $tokens->pluck('current_balance', 'mint')->all();
        }

        $body = $response->json();
        $results = [];

        foreach ($tokens->values() as $i => $token) {
            $entry = $body[$i] ?? null;
            $accounts = data_get($entry, 'result.value', []);
            $raw = '0';

            foreach ($accounts as $acct) {
                $amount = data_get($acct, 'account.data.parsed.info.tokenAmount.amount');

                if (is_string($amount) || is_numeric($amount)) {
                    $raw = bcadd($raw, (string) $amount, 0);
                }
            }

            $token->update(['current_balance' => $raw]);
            $results[$token->mint] = $raw;
        }

        return $results;
    }

    /**
     * Normalized weights per mint, summing to 1.0. Tokens with empty balance
     * are dropped (can't fill a reel slot with something we can't pay out).
     *
     * @return array<int, array{mint:string,weight:float,token:SlotPoolToken}>
     */
    public function weights(SlotPool $pool): array
    {
        $tokens = $pool->enabledTokens()->get();
        $entries = [];
        $total = 0.0;

        foreach ($tokens as $token) {
            $balance = (float) $token->current_balance;

            if ($balance <= 0) {
                continue;
            }

            $weight = $token->weight_override !== null
                ? (float) $token->weight_override
                : $balance / (10 ** $token->decimals);

            if ($weight <= 0) {
                continue;
            }

            $entries[] = ['mint' => $token->mint, 'weight' => $weight, 'token' => $token];
            $total += $weight;
        }

        if ($total <= 0) {
            return [];
        }

        return array_map(
            fn (array $e) => ['mint' => $e['mint'], 'weight' => $e['weight'] / $total, 'token' => $e['token']],
            $entries
        );
    }

    /**
     * Snapshot used for `/api/slots/pool` and the outcome resolver. Returns
     * EVERY enabled token (so the UI can offer it as a bet option), with the
     * normalized weight relative to the positive-balance set. Tokens with
     * zero balance get weight=0 — never sampled on reels, never paid out,
     * but still bettable so the pool can be bootstrapped from empty.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function snapshot(SlotPool $pool): Collection
    {
        $tokens = $pool->enabledTokens()->get();

        if ($tokens->isEmpty()) {
            return collect();
        }

        // Normalization base: sum of positive UI-balances. Zero-balance tokens
        // ride along with weight 0 and don't affect anyone else's weight.
        $total = 0.0;
        $uiBalances = [];

        foreach ($tokens as $token) {
            $balance = (float) $token->current_balance;
            $ui = $balance > 0 ? $balance / (10 ** $token->decimals) : 0.0;

            if ($token->weight_override !== null && $balance > 0) {
                $ui = (float) $token->weight_override;
            }

            $uiBalances[$token->id] = $ui;
            $total += $ui;
        }

        return $tokens->map(fn (SlotPoolToken $t) => [
            'mint' => $t->mint,
            'symbol' => $t->symbol,
            'decimals' => $t->decimals,
            'logo_url' => $t->logo_url,
            'weight' => $total > 0 ? $uiBalances[$t->id] / $total : 0.0,
            'balance' => $t->current_balance,
            'min_bet' => $t->min_bet,
            'max_bet' => $t->max_bet,
            'token_program' => $t->token_program,
        ])->values();
    }

    /**
     * Verify a Solana SPL transfer landed in the slot hot wallet. Adapted from
     * BridgeService::verifySolanaTokenDeposit but targets the slot RPC + the
     * slot's own hot wallet — bridge code is untouched.
     */
    public function verifyDeposit(string $txHash, string $expectedSender, string $expectedMint, string $expectedRecipient): ?string
    {
        $rpc = config('services.slots.rpc_url');

        try {
            $response = Http::timeout(30)->post($rpc, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTransaction',
                'params' => [
                    $txHash,
                    ['encoding' => 'jsonParsed', 'commitment' => 'confirmed', 'maxSupportedTransactionVersion' => 0],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Slots: verifyDeposit RPC failed', ['tx' => $txHash, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $result = $response->json('result');

        if (! $result || ($result['meta']['err'] ?? null) !== null) {
            return null;
        }

        $pre = $this->indexTokenBalances($result['meta']['preTokenBalances'] ?? []);
        $post = $this->indexTokenBalances($result['meta']['postTokenBalances'] ?? []);

        $recipientKey = $expectedRecipient.':'.$expectedMint;
        $preRaw = $pre[$recipientKey] ?? '0';
        $postRaw = $post[$recipientKey] ?? '0';

        if (bccomp($postRaw, $preRaw, 0) <= 0) {
            return null;
        }

        $senderKey = $expectedSender.':'.$expectedMint;
        $senderPre = $pre[$senderKey] ?? null;
        $senderPost = $post[$senderKey] ?? '0';

        if ($senderPre !== null && bccomp($senderPre, $senderPost, 0) <= 0) {
            return null;
        }

        return bcsub($postRaw, $preRaw, 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, string>
     */
    private function indexTokenBalances(array $entries): array
    {
        $out = [];

        foreach ($entries as $entry) {
            $owner = $entry['owner'] ?? null;
            $mint = $entry['mint'] ?? null;
            $amount = $entry['uiTokenAmount']['amount'] ?? null;

            if (! $owner || ! $mint || $amount === null) {
                continue;
            }

            $out[$owner.':'.$mint] = (string) $amount;
        }

        return $out;
    }
}
