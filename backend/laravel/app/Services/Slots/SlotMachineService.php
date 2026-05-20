<?php

namespace App\Services\Slots;

use App\Models\SlotPool;
use App\Models\SlotPoolToken;
use App\Models\SlotSpin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Orchestrates a single spin lifecycle:
 *   prepare → user-funded deposit → confirm (verify + settle).
 *
 * Commits a server seed hash in prepare, reveals the seed on settle. The
 * sequence (server_seed, client_seed, nonce) drives the deterministic RNG,
 * so an audit reveals exactly how the reels were chosen.
 *
 * Burn + payout are bundled into one Solana transaction signed by the slot
 * hot wallet (see crypto/anchor/scripts/slot-burn-and-payout.ts).
 */
class SlotMachineService
{
    public function __construct(
        private readonly SlotPoolService $pools,
        private readonly SlotRngService $rng,
        private readonly SlotOutcomeResolver $resolver,
        private readonly SlotEventLogger $events,
    ) {}

    /**
     * Phase 1: commit a server seed, return deposit instructions to the user.
     */
    public function prepare(SlotPool $pool, string $walletAddress, string $betMint, string $betAmountRaw, string $clientSeed): SlotSpin
    {
        $this->pools->assertBetAllowed($pool, $betMint, $betAmountRaw);
        $this->assertNoFloodingSpins($walletAddress);

        $serverSeed = $this->rng->newServerSeed();
        $ttl = (int) config('services.slots.prepare_ttl_minutes', 5);

        $spin = SlotSpin::create([
            'slot_pool_id' => $pool->id,
            'wallet_address' => $walletAddress,
            'bet_mint' => $betMint,
            'bet_amount' => $betAmountRaw,
            'deposit_address' => $pool->hot_wallet_address,
            'server_seed' => $serverSeed,
            'server_seed_hash' => $this->rng->hashServerSeed($serverSeed),
            'client_seed' => $clientSeed,
            'nonce' => $this->nextNonce($walletAddress),
            'outcome_type' => SlotSpin::OUTCOME_PENDING,
            'status' => SlotSpin::STATUS_PREPARED,
            'prepared_at' => now(),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $this->events->record('spin.prepared', $spin, [
            'bet_mint' => $betMint,
            'bet_amount' => $betAmountRaw,
        ]);

        return $spin;
    }

    /**
     * Phase 2: user has submitted their SPL transfer; verify the deposit
     * landed in the slot hot wallet, then run the RNG and settle.
     */
    public function confirm(SlotSpin $spin, string $depositTxHash): SlotSpin
    {
        if ($spin->status !== SlotSpin::STATUS_PREPARED) {
            throw new \DomainException("Spin not in prepared state: {$spin->status}");
        }

        if ($spin->expires_at && $spin->expires_at->isPast()) {
            $spin->update(['status' => SlotSpin::STATUS_EXPIRED]);
            throw new \DomainException('Spin expired');
        }

        $pool = $spin->pool;

        $depositRaw = $this->pools->verifyDeposit(
            txHash: $depositTxHash,
            expectedSender: $spin->wallet_address,
            expectedMint: $spin->bet_mint,
            expectedRecipient: $pool->hot_wallet_address,
        );

        if ($depositRaw === null || bccomp($depositRaw, $spin->bet_amount, 0) < 0) {
            $spin->update([
                'status' => SlotSpin::STATUS_FAILED,
                'error_message' => 'Deposit verification failed',
            ]);
            $this->events->record('spin.verify_failed', $spin, ['tx' => $depositTxHash]);
            throw new \DomainException('Deposit not found or under-funded');
        }

        $spin->update([
            'deposit_tx_hash' => $depositTxHash,
            'status' => SlotSpin::STATUS_DEPOSIT_SEEN,
            'confirmed_at' => now(),
        ]);

        return $this->settle($spin);
    }

    /**
     * Phase 3: roll the dice, build the on-chain settlement transaction,
     * commit to DB. Run inside a transaction so partial RNG state never leaks.
     */
    public function settle(SlotSpin $spin): SlotSpin
    {
        $pool = $spin->pool;

        // Re-sync balances so the snapshot reflects the just-received deposit.
        $this->pools->syncBalances($pool);
        $snapshot = $this->pools->snapshot($pool)->values()->all();

        // RNG can only sample tokens we can actually pay out, i.e. positive
        // weight. Snapshot may also list zero-balance bettable tokens.
        $weights = array_values(array_filter(
            array_map(fn (array $e) => ['mint' => $e['mint'], 'weight' => $e['weight']], $snapshot),
            fn (array $w) => $w['weight'] > 0,
        ));

        if ($weights === []) {
            $spin->update(['status' => SlotSpin::STATUS_FAILED, 'error_message' => 'Pool empty']);
            throw new \DomainException('Pool has no payable tokens');
        }

        $roll = $this->rng->spin($spin->server_seed, $spin->client_seed, $spin->nonce, $weights);

        $outcome = $this->resolver->resolve(
            pool: $pool,
            betMint: $spin->bet_mint,
            betAmountRaw: $spin->bet_amount,
            reels: $roll['reels'],
            jackpotRoll: $roll['jackpotRoll'],
            snapshot: $snapshot,
        );

        $betToken = $pool->tokens()->where('mint', $spin->bet_mint)->first();
        $payload = $this->buildSignerPayload($spin, $betToken, $outcome);

        try {
            $txHash = $this->runSigner($payload);
        } catch (\Throwable $e) {
            $spin->update([
                'reels' => $roll['reels'],
                'outcome_type' => $outcome['outcome'],
                'prize_payload' => $outcome['prize'],
                'burn_amount' => $outcome['burn_amount'],
                'status' => SlotSpin::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            $this->events->record('spin.settle_failed', $spin, [], $e->getMessage());
            throw $e;
        }

        DB::transaction(function () use ($spin, $roll, $outcome, $txHash, $pool) {
            $spin->update([
                'reels' => $roll['reels'],
                'outcome_type' => $outcome['outcome'],
                'prize_payload' => $outcome['prize'],
                'burn_amount' => $outcome['burn_amount'],
                'payout_tx_hash' => $txHash,
                'status' => SlotSpin::STATUS_SETTLED,
                'settled_at' => now(),
            ]);

            // Cheap optimistic adjustments to pool balances (real sync happens
            // on next prepare anyway). The hot wallet's bet ATA gained the
            // deposit then lost `burn_amount`; outgoing prizes drop balances.
            $netBet = bcsub($spin->bet_amount, $outcome['burn_amount'], 0);
            $this->adjustBalance($pool, $spin->bet_mint, $netBet);

            foreach ($outcome['prize'] as $line) {
                $this->adjustBalance($pool, $line['mint'], '-'.$line['amount']);
            }
        });

        $this->events->record('spin.settled', $spin, [
            'outcome' => $outcome['outcome'],
            'tx' => $txHash,
        ]);

        return $spin->fresh();
    }

    private function buildSignerPayload(SlotSpin $spin, SlotPoolToken $betToken, array $outcome): array
    {
        return [
            'burn' => bccomp($outcome['burn_amount'], '0', 0) > 0 ? [
                'mint' => $spin->bet_mint,
                'amount' => $outcome['burn_amount'],
                'tokenProgram' => $betToken->token_program,
            ] : null,
            'payouts' => array_map(function (array $line) use ($spin) {
                $token = SlotPoolToken::where('slot_pool_id', $spin->slot_pool_id)
                    ->where('mint', $line['mint'])
                    ->firstOrFail();

                return [
                    'mint' => $line['mint'],
                    'amount' => $line['amount'],
                    'recipient' => $spin->wallet_address,
                    'tokenProgram' => $token->token_program,
                ];
            }, $outcome['prize']),
        ];
    }

    protected function runSigner(array $payload): string
    {
        $scriptDir = base_path('/../../crypto/anchor');
        if (! is_dir($scriptDir)) {
            $scriptDir = '/singularity/crypto/anchor'; // production layout
        }

        $result = Process::path($scriptDir)
            ->env([
                'ANCHOR_PROVIDER_URL' => config('services.slots.rpc_url'),
                'ANCHOR_WALLET' => config('services.slots.hot_wallet_keypair_path'),
            ])
            ->timeout(120)
            ->input(json_encode($payload, JSON_THROW_ON_ERROR))
            ->run(['npx', 'ts-node', '--transpile-only', 'scripts/slot-burn-and-payout.ts']);

        Log::info('Slot signer output', [
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit' => $result->exitCode(),
        ]);

        if ($result->exitCode() !== 0) {
            throw new \RuntimeException('Slot signer failed: '.$result->errorOutput());
        }

        $json = $this->lastJsonLine($result->output());

        if (! $json || empty($json['txHash'])) {
            // No-op (loss with zero burn somehow) — synthesize a sentinel.
            if (($json['status'] ?? '') === 'noop') {
                return '';
            }
            throw new \RuntimeException('Could not parse signer output');
        }

        return (string) $json['txHash'];
    }

    private function lastJsonLine(string $output): ?array
    {
        foreach (array_reverse(preg_split('/\r?\n/', trim($output))) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function adjustBalance(SlotPool $pool, string $mint, string $delta): void
    {
        $token = $pool->tokens()->where('mint', $mint)->first();
        if (! $token) {
            return;
        }
        $new = bcadd($token->current_balance, $delta, 0);
        if (bccomp($new, '0', 0) < 0) {
            $new = '0';
        }
        $token->update(['current_balance' => $new]);
    }

    private function assertNoFloodingSpins(string $walletAddress): void
    {
        $active = SlotSpin::where('wallet_address', $walletAddress)
            ->where('status', SlotSpin::STATUS_PREPARED)
            ->where('expires_at', '>', now())
            ->count();

        if ($active >= 3) {
            throw new \DomainException('Too many pending spins');
        }
    }

    private function nextNonce(string $walletAddress): int
    {
        return (int) SlotSpin::where('wallet_address', $walletAddress)->max('nonce') + 1;
    }
}
