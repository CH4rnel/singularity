<?php

namespace App\Services;

use App\Models\SolanaStakingPosition;
use App\Models\SolanaStakingTransaction;
use App\Models\User;
use App\Support\Environment;
use App\Support\TokenAmount;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class SolanaStakingService
{
    private const SECONDS_PER_DAY = 86400;

    /** @var array<int, string> */
    private const RESERVED_STATUSES = [
        SolanaStakingTransaction::STATUS_PROCESSING,
        SolanaStakingTransaction::STATUS_NEEDS_REVIEW,
    ];

    /**
     * Browser-safe staking configuration. Signing keys never leave the server.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $rate = trim((string) config('services.staking.ash_per_cyber_per_day', '0'));
        $treasury = trim((string) config('services.staking.treasury_address'));
        $rpcUrl = trim((string) config('services.staking.rpc_url'));
        $keypairPath = trim((string) config('services.staking.keypair_path'));
        $evmPrivateKey = trim((string) config('services.staking.evm_private_key'));
        $validRate = preg_match('/^\d+(?:\.\d{1,18})?$/', $rate) === 1
            && bccomp($rate, '0', 18) > 0;
        $enabled = (bool) config('services.staking.enabled')
            && $treasury !== ''
            && $rpcUrl !== ''
            && $keypairPath !== ''
            && $evmPrivateKey !== ''
            && $validRate;

        return [
            'enabled' => $enabled,
            'cluster' => (string) config('services.staking.cluster', 'mainnet'),
            'rpc_url' => (string) config('services.staking.public_rpc_url'),
            'treasury_address' => $treasury,
            'cyber_sol_mint' => (string) config('services.staking.cyber_sol_mint'),
            'cyber_sol_decimals' => (int) config('services.staking.cyber_sol_decimals', 6),
            'token_program' => (string) config('services.staking.token_program', 'token-2022'),
            'ash_decimals' => (int) config('services.staking.ash_decimals', 18),
            'ash_per_cyber_per_day' => $validRate ? $rate : '0',
            'withdrawals_enabled' => $enabled,
            'claims_enabled' => $enabled,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(User $user): ?array
    {
        $position = SolanaStakingPosition::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $position) {
            return null;
        }

        [$accruedAshRaw] = $this->previewAccrual($position, now());
        $reservedPrincipal = $this->reservedAmount($position, SolanaStakingTransaction::TYPE_WITHDRAWAL);
        $reservedRewards = $this->reservedAmount($position, SolanaStakingTransaction::TYPE_REWARD_CLAIM);

        return [
            'solana_address' => $position->solana_address,
            'principal_raw' => $position->principal_raw,
            'available_principal_raw' => $this->subtractFloor($position->principal_raw, $reservedPrincipal),
            'accrued_ash_raw' => $accruedAshRaw,
            'available_ash_raw' => $this->subtractFloor($accruedAshRaw, $reservedRewards),
            'total_deposited_raw' => $position->total_deposited_raw,
            'total_withdrawn_raw' => $position->total_withdrawn_raw,
            'total_claimed_ash_raw' => $position->total_claimed_ash_raw,
            'transactions' => $position->transactions()
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (SolanaStakingTransaction $transaction): array => [
                    'uuid' => $transaction->uuid,
                    'type' => $transaction->type,
                    'amount_raw' => $transaction->amount_raw,
                    'tx_hash' => $transaction->tx_hash,
                    'status' => $transaction->status,
                    'error_message' => $transaction->error_message,
                    'created_at' => $transaction->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function prepareDeposit(User $user, string $amountRaw): SolanaStakingTransaction
    {
        $this->assertEnabled();
        $this->assertPositiveRawAmount($amountRaw);
        $position = $this->positionFor($user);

        return $position->transactions()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => SolanaStakingTransaction::TYPE_DEPOSIT,
            'amount_raw' => $amountRaw,
            'status' => SolanaStakingTransaction::STATUS_PREPARED,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function confirmDeposit(
        User $user,
        string $uuid,
        string $txHash,
    ): SolanaStakingTransaction {
        $transaction = SolanaStakingTransaction::query()
            ->where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->where('type', SolanaStakingTransaction::TYPE_DEPOSIT)
            ->firstOrFail();

        if ($transaction->status === SolanaStakingTransaction::STATUS_COMPLETED) {
            return $transaction;
        }

        if ($transaction->status !== SolanaStakingTransaction::STATUS_PREPARED) {
            throw new \DomainException('Deposit is not awaiting confirmation.');
        }

        $position = $transaction->position()->firstOrFail();
        $this->verifyDepositTransaction($transaction, $position, $txHash);

        try {
            return DB::transaction(function () use ($transaction, $txHash): SolanaStakingTransaction {
                $lockedTransaction = SolanaStakingTransaction::query()
                    ->lockForUpdate()
                    ->findOrFail($transaction->id);

                if ($lockedTransaction->status === SolanaStakingTransaction::STATUS_COMPLETED) {
                    return $lockedTransaction;
                }

                $position = SolanaStakingPosition::query()
                    ->lockForUpdate()
                    ->findOrFail($lockedTransaction->solana_staking_position_id);
                $this->accrue($position, now());
                $position->principal_raw = bcadd($position->principal_raw, $lockedTransaction->amount_raw, 0);
                $position->total_deposited_raw = bcadd(
                    $position->total_deposited_raw,
                    $lockedTransaction->amount_raw,
                    0,
                );
                $position->save();

                $lockedTransaction->update([
                    'tx_hash' => $txHash,
                    'status' => SolanaStakingTransaction::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                return $lockedTransaction;
            });
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('This Solana transaction has already been credited.');
        }
    }

    public function withdraw(User $user, string $amountRaw): SolanaStakingTransaction
    {
        $this->assertEnabled();

        if (trim((string) config('services.staking.keypair_path')) === '') {
            throw new \DomainException('Solana staking withdrawals are not configured.');
        }

        $this->assertPositiveRawAmount($amountRaw);

        $transaction = DB::transaction(function () use ($user, $amountRaw): SolanaStakingTransaction {
            $position = $this->lockedPositionFor($user);
            $this->accrue($position, now());
            $reserved = $this->reservedAmount($position, SolanaStakingTransaction::TYPE_WITHDRAWAL);
            $available = $this->subtractFloor($position->principal_raw, $reserved);

            if (bccomp($amountRaw, $available, 0) > 0) {
                throw new \DomainException('Withdrawal amount exceeds the available stake.');
            }

            return $position->transactions()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'type' => SolanaStakingTransaction::TYPE_WITHDRAWAL,
                'amount_raw' => $amountRaw,
                'status' => SolanaStakingTransaction::STATUS_PROCESSING,
            ]);
        });

        $txHash = $this->payoutSolana($transaction, (string) $user->solana_wallet_address);

        if ($txHash === null) {
            $transaction->update([
                'status' => SolanaStakingTransaction::STATUS_NEEDS_REVIEW,
                'error_message' => 'Automatic payout could not be confirmed; funds remain reserved for operator review.',
            ]);

            return $transaction;
        }

        // Persist the broadcast evidence before mutating the ledger. If the
        // database transaction below fails, the reserved row remains
        // reviewable without risking a second payout.
        $transaction->update(['tx_hash' => $txHash]);

        return DB::transaction(function () use ($transaction, $txHash): SolanaStakingTransaction {
            $position = SolanaStakingPosition::query()
                ->lockForUpdate()
                ->findOrFail($transaction->solana_staking_position_id);
            $this->accrue($position, now());
            $position->principal_raw = bcsub($position->principal_raw, $transaction->amount_raw, 0);
            $position->total_withdrawn_raw = bcadd(
                $position->total_withdrawn_raw,
                $transaction->amount_raw,
                0,
            );
            $position->save();
            $transaction->update([
                'tx_hash' => $txHash,
                'status' => SolanaStakingTransaction::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return $transaction;
        });
    }

    public function claimRewards(User $user): SolanaStakingTransaction
    {
        $this->assertEnabled();

        if (! $user->wallet_address) {
            throw new \DomainException('Link a Cyberia EVM wallet before claiming ASH.');
        }

        if (trim((string) config('services.staking.evm_private_key')) === '') {
            throw new \DomainException('ASH payouts are not configured.');
        }

        $transaction = DB::transaction(function () use ($user): SolanaStakingTransaction {
            $position = $this->lockedPositionFor($user);
            $this->accrue($position, now());
            $reserved = $this->reservedAmount($position, SolanaStakingTransaction::TYPE_REWARD_CLAIM);
            $available = $this->subtractFloor($position->accrued_ash_raw, $reserved);

            if (bccomp($available, '0', 0) <= 0) {
                throw new \DomainException('No ASH rewards are available to claim.');
            }

            return $position->transactions()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'type' => SolanaStakingTransaction::TYPE_REWARD_CLAIM,
                'amount_raw' => $available,
                'status' => SolanaStakingTransaction::STATUS_PROCESSING,
                'metadata' => ['evm_recipient' => $user->wallet_address],
            ]);
        });

        $txHash = $this->payoutAsh($transaction, (string) $user->wallet_address);

        if ($txHash === null) {
            $transaction->update([
                'status' => SolanaStakingTransaction::STATUS_NEEDS_REVIEW,
                'error_message' => 'Automatic ASH payout could not be confirmed; rewards remain reserved for operator review.',
            ]);

            return $transaction;
        }

        // Keep the chain hash even if final ledger settlement fails. Rewards
        // stay reserved until an operator reconciles that transaction.
        $transaction->update(['tx_hash' => $txHash]);

        return DB::transaction(function () use ($transaction, $txHash): SolanaStakingTransaction {
            $position = SolanaStakingPosition::query()
                ->lockForUpdate()
                ->findOrFail($transaction->solana_staking_position_id);
            $this->accrue($position, now());
            $position->accrued_ash_raw = bcsub($position->accrued_ash_raw, $transaction->amount_raw, 0);
            $position->total_claimed_ash_raw = bcadd(
                $position->total_claimed_ash_raw,
                $transaction->amount_raw,
                0,
            );
            $position->save();
            $transaction->update([
                'tx_hash' => $txHash,
                'status' => SolanaStakingTransaction::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return $transaction;
        });
    }

    private function positionFor(User $user): SolanaStakingPosition
    {
        $address = trim((string) $user->solana_wallet_address);

        if ($address === '') {
            throw new \DomainException('Link a Solana wallet before staking CYBER.sol.');
        }

        $position = SolanaStakingPosition::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'solana_address' => $address,
                'accrued_at' => now(),
            ],
        );

        if ($position->solana_address !== $address) {
            if (bccomp($position->principal_raw, '0', 0) > 0
                || bccomp($this->reservedAmount($position, SolanaStakingTransaction::TYPE_WITHDRAWAL), '0', 0) > 0) {
                throw new \DomainException('The linked Solana wallet changed while a stake is active. Restore the original wallet or contact support.');
            }

            $position->update(['solana_address' => $address]);
        }

        return $position;
    }

    private function lockedPositionFor(User $user): SolanaStakingPosition
    {
        $this->positionFor($user);

        return SolanaStakingPosition::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertEnabled(): void
    {
        if (! $this->publicConfig()['enabled']) {
            throw new \DomainException('CYBER.sol staking is not configured yet.');
        }
    }

    private function assertPositiveRawAmount(string $amountRaw): void
    {
        if (! preg_match('/^[0-9]+$/', $amountRaw) || bccomp($amountRaw, '0', 0) <= 0) {
            throw new \DomainException('Amount must be a positive raw token amount.');
        }
    }

    private function accrue(SolanaStakingPosition $position, CarbonInterface $at): void
    {
        [$accrued, $remainder] = $this->previewAccrual($position, $at);
        $position->accrued_ash_raw = $accrued;
        $position->reward_remainder = $remainder;
        $position->accrued_at = $at;
        $position->save();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function previewAccrual(SolanaStakingPosition $position, CarbonInterface $at): array
    {
        if (! $position->accrued_at || bccomp($position->principal_raw, '0', 0) <= 0) {
            return [$position->accrued_ash_raw, $position->reward_remainder];
        }

        $seconds = (int) max(0, $position->accrued_at->diffInSeconds($at, false));

        if ($seconds === 0) {
            return [$position->accrued_ash_raw, $position->reward_remainder];
        }

        $rateRaw = TokenAmount::toRaw(
            (string) config('services.staking.ash_per_cyber_per_day', '0'),
            (int) config('services.staking.ash_decimals', 18),
        );
        $cyberScale = bcpow('10', (string) config('services.staking.cyber_sol_decimals', 6));
        $denominator = bcmul($cyberScale, (string) self::SECONDS_PER_DAY, 0);
        $numerator = bcadd(
            bcmul(
                bcmul($position->principal_raw, $rateRaw, 0),
                (string) $seconds,
                0,
            ),
            $position->reward_remainder,
            0,
        );
        $earned = bcdiv($numerator, $denominator, 0);

        return [
            bcadd($position->accrued_ash_raw, $earned, 0),
            bcmod($numerator, $denominator),
        ];
    }

    private function reservedAmount(SolanaStakingPosition $position, string $type): string
    {
        return (string) $position->transactions()
            ->where('type', $type)
            ->whereIn('status', self::RESERVED_STATUSES)
            ->get(['amount_raw'])
            ->reduce(
                fn (string $total, SolanaStakingTransaction $transaction): string => bcadd($total, $transaction->amount_raw, 0),
                '0',
            );
    }

    private function subtractFloor(string $amount, string $reserved): string
    {
        return bccomp($amount, $reserved, 0) > 0
            ? bcsub($amount, $reserved, 0)
            : '0';
    }

    private function verifyDepositTransaction(
        SolanaStakingTransaction $transaction,
        SolanaStakingPosition $position,
        string $txHash,
    ): void {
        $response = Http::timeout(30)->post((string) config('services.staking.rpc_url'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getTransaction',
            'params' => [
                $txHash,
                ['encoding' => 'jsonParsed', 'commitment' => 'confirmed', 'maxSupportedTransactionVersion' => 0],
            ],
        ]);

        $result = $response->successful() ? $response->json('result') : null;

        if (! is_array($result) || ($result['meta']['err'] ?? null) !== null) {
            throw new \DomainException('Solana transaction is missing, failed, or not confirmed yet.');
        }

        if (! $this->hasExpectedSigner($result, $position->solana_address)) {
            throw new \DomainException('The linked Solana wallet did not sign this deposit.');
        }

        if (! $this->hasExpectedMemo($result, $this->depositMemo($transaction))) {
            throw new \DomainException('The staking deposit memo does not match this request.');
        }

        $mint = (string) config('services.staking.cyber_sol_mint');
        $treasury = (string) config('services.staking.treasury_address');
        $pre = $this->indexTokenBalances($result['meta']['preTokenBalances'] ?? []);
        $post = $this->indexTokenBalances($result['meta']['postTokenBalances'] ?? []);
        $treasuryDelta = bcsub(
            $post[$treasury.':'.$mint] ?? '0',
            $pre[$treasury.':'.$mint] ?? '0',
            0,
        );
        $senderDelta = bcsub(
            $pre[$position->solana_address.':'.$mint] ?? '0',
            $post[$position->solana_address.':'.$mint] ?? '0',
            0,
        );

        if (bccomp($treasuryDelta, $transaction->amount_raw, 0) !== 0
            || bccomp($senderDelta, $transaction->amount_raw, 0) < 0) {
            throw new \DomainException('Solana transfer mint, recipient, or amount does not match the staking request.');
        }
    }

    /** @param array<string, mixed> $result */
    private function hasExpectedSigner(array $result, string $address): bool
    {
        foreach ($result['transaction']['message']['accountKeys'] ?? [] as $account) {
            if (is_array($account)
                && ($account['pubkey'] ?? null) === $address
                && ($account['signer'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $result */
    private function hasExpectedMemo(array $result, string $memo): bool
    {
        foreach ($result['transaction']['message']['instructions'] ?? [] as $instruction) {
            if (($instruction['program'] ?? null) === 'spl-memo'
                && ($instruction['parsed'] ?? null) === $memo) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $balances
     * @return array<string, string>
     */
    private function indexTokenBalances(array $balances): array
    {
        $indexed = [];

        foreach ($balances as $balance) {
            $owner = $balance['owner'] ?? null;
            $mint = $balance['mint'] ?? null;
            $amount = $balance['uiTokenAmount']['amount'] ?? null;

            if (is_string($owner) && is_string($mint) && is_string($amount)) {
                $indexed[$owner.':'.$mint] = isset($indexed[$owner.':'.$mint])
                    ? bcadd($indexed[$owner.':'.$mint], $amount, 0)
                    : $amount;
            }
        }

        return $indexed;
    }

    public function depositMemo(SolanaStakingTransaction $transaction): string
    {
        return 'cyberia-stake:'.$transaction->uuid;
    }

    private function payoutSolana(SolanaStakingTransaction $transaction, string $recipient): ?string
    {
        $scriptDir = Environment::isProduction()
            ? '/singularity/crypto/anchor'
            : base_path('/../../crypto/anchor');
        $captured = '';

        try {
            $result = Process::path($scriptDir)
                ->env([
                    'ANCHOR_PROVIDER_URL' => (string) config('services.staking.rpc_url'),
                    'ANCHOR_WALLET' => (string) config('services.staking.keypair_path'),
                    'EXPECTED_SENDER' => (string) config('services.staking.treasury_address'),
                ])
                ->timeout(120)
                ->run([
                    'npx', 'ts-node', '--transpile-only', 'scripts/relay-spl-transfer.ts',
                    (string) config('services.staking.cyber_sol_mint'),
                    $recipient,
                    $transaction->amount_raw,
                    (string) config('services.staking.token_program', 'token-2022'),
                ], function (string $type, string $buffer) use (&$captured): void {
                    $captured .= $buffer;
                });
        } catch (ProcessTimedOutException) {
            $this->rememberBroadcastHash($transaction, $captured);

            return null;
        }

        if ($result->exitCode() !== 0) {
            Log::error('Solana staking withdrawal relay failed', [
                'transaction_id' => $transaction->id,
                'exit' => $result->exitCode(),
            ]);

            $this->rememberBroadcastHash($transaction, $result->output());

            return null;
        }

        return $this->extractTxHash($result->output());
    }

    private function payoutAsh(SolanaStakingTransaction $transaction, string $recipient): ?string
    {
        $hardhatDir = Environment::isProduction()
            ? '/singularity/crypto/hardhat'
            : base_path('/../../crypto/hardhat');
        $captured = '';

        try {
            $result = Process::path($hardhatDir)
                ->env([
                    'EVM_RPC_URL' => (string) config('services.staking.evm_rpc_url'),
                    'EVM_CHAIN_ID' => (string) config('services.staking.evm_chain_id', 49406),
                    'BRIDGE_RELAYER_PRIVATE_KEY' => (string) config('services.staking.evm_private_key'),
                ])
                ->timeout(120)
                ->run([
                    'npx', 'tsx', 'scripts/relay-erc20-transfer.ts',
                    (string) config('services.staking.ash_address'),
                    $recipient,
                    $transaction->amount_raw,
                    '0',
                ], function (string $type, string $buffer) use (&$captured): void {
                    $captured .= $buffer;
                });
        } catch (ProcessTimedOutException) {
            $this->rememberBroadcastHash($transaction, $captured);

            return null;
        }

        if ($result->exitCode() !== 0) {
            Log::error('Solana staking ASH relay failed', [
                'transaction_id' => $transaction->id,
                'exit' => $result->exitCode(),
            ]);
            $this->rememberBroadcastHash($transaction, $result->output());

            return null;
        }

        return $this->extractTxHash($result->output());
    }

    private function rememberBroadcastHash(
        SolanaStakingTransaction $transaction,
        string $output,
    ): void {
        $txHash = $this->extractTxHash($output);

        if ($txHash !== null) {
            $transaction->update(['tx_hash' => $txHash]);
        }
    }

    private function extractTxHash(string $output): ?string
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $output))));

        foreach ($lines as $line) {
            $json = json_decode($line, true);
            $hash = is_array($json)
                ? ($json['txHash'] ?? $json['broadcastTxHash'] ?? null)
                : null;

            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        return null;
    }
}
