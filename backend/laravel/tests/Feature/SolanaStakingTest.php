<?php

use App\Models\SolanaStakingPosition;
use App\Models\SolanaStakingTransaction;
use App\Models\User;
use App\Services\SolanaStakingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

const SOLANA_STAKER = '7YttLkHDoS9F3oE3f8o8t4kzJYjD2kXcRgFPrKtWQpVA';
const SOLANA_TREASURY = 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w';
const CYBER_SOL_MINT = 'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump';

beforeEach(function () {
    config()->set('services.staking', [
        'enabled' => true,
        'cluster' => 'mainnet',
        'rpc_url' => 'https://solana.invalid',
        'public_rpc_url' => 'https://solana.invalid',
        'treasury_address' => SOLANA_TREASURY,
        'keypair_path' => '/fake/staking.json',
        'cyber_sol_mint' => CYBER_SOL_MINT,
        'cyber_sol_decimals' => 6,
        'token_program' => 'token-2022',
        'ash_address' => '0x992Fca0a89DD95afb17751f6CC233Adb9B089df5',
        'ash_decimals' => 18,
        'ash_per_cyber_per_day' => '0.000001',
        'evm_rpc_url' => 'https://rpc.cyberia.church',
        'evm_chain_id' => 49406,
        'evm_private_key' => 'fake-payout-key',
    ]);
});

test('staking page exposes browser safe configuration only', function () {
    $this->withoutVite();
    config()->set('services.staking.evm_private_key', 'must-not-leak');
    config()->set('services.staking.keypair_path', '/secret/keypair.json');

    $this->get(route('staking'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Staking')
            ->where('solana.enabled', true)
            ->where('solana.treasury_address', SOLANA_TREASURY)
            ->missing('solana.evm_private_key')
            ->missing('solana.keypair_path'));
});

test('staking remains disabled until both payout wallets are configured', function () {
    config()->set('services.staking.keypair_path', null);

    expect(app(SolanaStakingService::class)->publicConfig())
        ->enabled->toBeFalse()
        ->withdrawals_enabled->toBeFalse()
        ->claims_enabled->toBeFalse();
});

test('authenticated user prepares a memo bound deposit', function () {
    $user = User::factory()->create([
        'solana_wallet_address' => SOLANA_STAKER,
    ]);

    $response = $this->actingAs($user)->postJson(
        route('staking.solana.deposits.prepare'),
        ['amount_raw' => '1000000'],
    );

    $response->assertCreated()
        ->assertJsonPath('deposit.amount_raw', '1000000')
        ->assertJsonPath('deposit.treasury_address', SOLANA_TREASURY)
        ->assertJsonPath('deposit.mint', CYBER_SOL_MINT);

    $transaction = SolanaStakingTransaction::query()->sole();

    expect($response->json('deposit.memo'))
        ->toBe('cyberia-stake:'.$transaction->uuid)
        ->and($transaction->status)
        ->toBe(SolanaStakingTransaction::STATUS_PREPARED);
});

test('confirmed exact Solana transfer credits principal once', function () {
    $user = User::factory()->create([
        'solana_wallet_address' => SOLANA_STAKER,
    ]);
    $transaction = app(SolanaStakingService::class)
        ->prepareDeposit($user, '1000000');
    $txHash = str_repeat('2', 64);

    Http::fake([
        'https://solana.invalid' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'meta' => [
                    'err' => null,
                    'preTokenBalances' => [
                        tokenBalance(SOLANA_STAKER, '2000000'),
                        tokenBalance(SOLANA_TREASURY, '0'),
                    ],
                    'postTokenBalances' => [
                        tokenBalance(SOLANA_STAKER, '1000000'),
                        tokenBalance(SOLANA_TREASURY, '1000000'),
                    ],
                ],
                'transaction' => ['message' => [
                    'accountKeys' => [[
                        'pubkey' => SOLANA_STAKER,
                        'signer' => true,
                        'writable' => true,
                    ]],
                    'instructions' => [[
                        'program' => 'spl-memo',
                        'parsed' => 'cyberia-stake:'.$transaction->uuid,
                    ]],
                ]],
            ],
        ]),
    ]);

    $payload = ['uuid' => $transaction->uuid, 'tx_hash' => $txHash];
    $this->actingAs($user)
        ->postJson(route('staking.solana.deposits.confirm'), $payload)
        ->assertOk()
        ->assertJsonPath('position.principal_raw', '1000000');
    $this->actingAs($user)
        ->postJson(route('staking.solana.deposits.confirm'), $payload)
        ->assertOk()
        ->assertJsonPath('position.principal_raw', '1000000');

    expect(SolanaStakingPosition::query()->sole()->principal_raw)
        ->toBe('1000000');
});

test('deposit confirmation rejects a transaction with another memo', function () {
    $user = User::factory()->create([
        'solana_wallet_address' => SOLANA_STAKER,
    ]);
    $transaction = app(SolanaStakingService::class)
        ->prepareDeposit($user, '1000000');

    Http::fake([
        'https://solana.invalid' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'meta' => [
                    'err' => null,
                    'preTokenBalances' => [
                        tokenBalance(SOLANA_STAKER, '2000000'),
                        tokenBalance(SOLANA_TREASURY, '0'),
                    ],
                    'postTokenBalances' => [
                        tokenBalance(SOLANA_STAKER, '1000000'),
                        tokenBalance(SOLANA_TREASURY, '1000000'),
                    ],
                ],
                'transaction' => ['message' => [
                    'accountKeys' => [[
                        'pubkey' => SOLANA_STAKER,
                        'signer' => true,
                    ]],
                    'instructions' => [[
                        'program' => 'spl-memo',
                        'parsed' => 'cyberia-stake:another-request',
                    ]],
                ]],
            ],
        ]),
    ]);

    $this->actingAs($user)->postJson(
        route('staking.solana.deposits.confirm'),
        ['uuid' => $transaction->uuid, 'tx_hash' => str_repeat('3', 64)],
    )->assertUnprocessable()
        ->assertJsonPath('message', 'The staking deposit memo does not match this request.');

    expect($transaction->fresh()->status)
        ->toBe(SolanaStakingTransaction::STATUS_PREPARED)
        ->and(SolanaStakingPosition::query()->sole()->principal_raw)
        ->toBe('0');
});

test('confirmed withdrawal returns CYBER sol and settles reserved principal', function () {
    config()->set('services.staking.keypair_path', '/secret/staking.json');
    Process::fake([
        '*relay-spl-transfer.ts*' => Process::result(
            output: json_encode(['txHash' => str_repeat('4', 64), 'status' => 'success']),
        ),
    ]);
    $user = User::factory()->create([
        'solana_wallet_address' => SOLANA_STAKER,
    ]);
    SolanaStakingPosition::create([
        'user_id' => $user->id,
        'solana_address' => SOLANA_STAKER,
        'principal_raw' => '2000000',
        'accrued_at' => now(),
    ]);

    $this->actingAs($user)->postJson(
        route('staking.solana.withdrawals.store'),
        ['amount_raw' => '1000000'],
    )->assertOk()
        ->assertJsonPath('transaction.status', SolanaStakingTransaction::STATUS_COMPLETED)
        ->assertJsonPath('position.principal_raw', '1000000');

    $transaction = SolanaStakingTransaction::query()->sole();

    expect($transaction->tx_hash)->toBe(str_repeat('4', 64))
        ->and($transaction->position->total_withdrawn_raw)->toBe('1000000');

    Process::assertRan(fn ($process): bool => str_contains(
        implode(' ', $process->command),
        'relay-spl-transfer.ts '.CYBER_SOL_MINT.' '.SOLANA_STAKER.' 1000000 token-2022',
    ));
});

test('confirmed reward claim pays ASH to the linked EVM wallet', function () {
    $evmWallet = '0x1111111111111111111111111111111111111111';
    config()->set('services.staking.evm_private_key', 'dedicated-payout-key');
    Process::fake([
        '*relay-erc20-transfer.ts*' => Process::result(
            output: json_encode(['txHash' => '0x'.str_repeat('5', 64)]),
        ),
    ]);
    $user = User::factory()->create([
        'wallet_address' => $evmWallet,
        'solana_wallet_address' => SOLANA_STAKER,
    ]);
    SolanaStakingPosition::create([
        'user_id' => $user->id,
        'solana_address' => SOLANA_STAKER,
        'accrued_ash_raw' => '7000000000000000000',
        'accrued_at' => now(),
    ]);

    $this->actingAs($user)->postJson(route('staking.solana.claims.store'))
        ->assertOk()
        ->assertJsonPath('transaction.status', SolanaStakingTransaction::STATUS_COMPLETED)
        ->assertJsonPath('position.accrued_ash_raw', '0');

    $transaction = SolanaStakingTransaction::query()->sole();

    expect($transaction->amount_raw)->toBe('7000000000000000000')
        ->and($transaction->position->total_claimed_ash_raw)->toBe('7000000000000000000');

    Process::assertRan(fn ($process): bool => str_contains(
        implode(' ', $process->command),
        'relay-erc20-transfer.ts 0x992Fca0a89DD95afb17751f6CC233Adb9B089df5 '.$evmWallet.' 7000000000000000000 0',
    ));
});

test('reward accrual uses integer raw units without losing fractions', function () {
    Carbon::setTestNow('2026-07-21 12:00:00');
    $user = User::factory()->create([
        'solana_wallet_address' => SOLANA_STAKER,
    ]);
    SolanaStakingPosition::create([
        'user_id' => $user->id,
        'solana_address' => SOLANA_STAKER,
        'principal_raw' => '1000000000000',
        'accrued_at' => now()->subDay(),
    ]);

    $snapshot = app(SolanaStakingService::class)->snapshot($user);

    expect($snapshot['accrued_ash_raw'])->toBe('1000000000000000000');
});

/** @return array<string, mixed> */
function tokenBalance(string $owner, string $amount): array
{
    return [
        'owner' => $owner,
        'mint' => CYBER_SOL_MINT,
        'uiTokenAmount' => ['amount' => $amount],
    ];
}
