<?php

use App\Models\SlotPool;
use App\Models\SlotPoolToken;
use App\Models\SlotSpin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const SLOT_HOT_WALLET = 'SLOT11111111111111111111111111111111111111';
const USER_WALLET = 'UsErWaLLEt1111111111111111111111111111111111';
const MINT_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const MINT_B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
const MINT_C = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

beforeEach(function () {
    config()->set('services.slots.hot_wallet_address', SLOT_HOT_WALLET);
    config()->set('services.slots.hot_wallet_keypair_path', '/tmp/fake-slot-key.json');
    config()->set('services.slots.rpc_url', 'https://slot.rpc.test');

    $pool = SlotPool::create([
        'name' => 'test',
        'status' => 'active',
        'hot_wallet_address' => SLOT_HOT_WALLET,
        'burn_bps' => 200,
        'house_edge_bps' => 400,
        'jackpot_threshold_bps' => 0, // disable jackpot in tests for determinism
        'max_single_win_bps' => 2000,
        'jackpot_basket_bps' => 2500,
        'jackpot_basket_size' => 5,
    ]);

    foreach ([MINT_A, MINT_B, MINT_C] as $mint) {
        SlotPoolToken::create([
            'slot_pool_id' => $pool->id,
            'mint' => $mint,
            'token_program' => 'token',
            'decimals' => 6,
            'symbol' => substr($mint, 0, 4),
            'logo_url' => null,
            'current_balance' => bcmul('1000', '1000000', 0), // 1000 UI tokens
            'enabled' => true,
            'min_bet' => '0',
            'max_bet' => null,
        ]);
    }
});

function mockBalanceSync(array $balances): void
{
    Http::fake([
        'https://slot.rpc.test*' => Http::sequence()
            // syncBalances batch response, then verifyDeposit, then syncBalances again on settle.
            // Use a wildcard fallback via callback for repeat calls.
            ->push(array_values(array_map(fn ($amount, $i) => [
                'id' => $i,
                'result' => ['value' => [['account' => ['data' => ['parsed' => ['info' => ['tokenAmount' => ['amount' => $amount]]]]]]]],
            ], $balances, array_keys($balances)))),
    ]);
}

it('prepare returns a commit hash and deposit instructions', function () {
    Http::fake([
        'https://slot.rpc.test*' => Http::response([]),
    ]);

    $response = $this->postJson('/api/slots/spin/prepare', [
        'wallet_address' => USER_WALLET,
        'bet_mint' => MINT_A,
        'bet_amount' => '1000000',
        'client_seed' => 'tester',
    ]);

    $response->assertCreated();
    $response->assertJsonStructure([
        'spin_id', 'server_seed_hash', 'nonce', 'deposit_address', 'expected_amount', 'expires_at',
    ]);
    expect($response->json('deposit_address'))->toBe(SLOT_HOT_WALLET);
    expect($response->json('server_seed_hash'))->toHaveLength(64);
});

it('rejects bets in non-whitelisted mints', function () {
    $response = $this->postJson('/api/slots/spin/prepare', [
        'wallet_address' => USER_WALLET,
        'bet_mint' => 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD',
        'bet_amount' => '1000000',
        'client_seed' => 'tester',
    ]);

    $response->assertStatus(422);
});

it('settles a spin end-to-end with verified deposit and signer call', function () {
    // First call: getTransaction for verifyDeposit. Subsequent calls: balance sync.
    Http::fake(function ($request) {
        $body = $request->data();

        // Batch (array of envelopes) → balance sync
        if (array_is_list($body) && isset($body[0]['method'])) {
            return Http::response(array_map(fn ($e) => [
                'id' => $e['id'],
                'result' => ['value' => [['account' => ['data' => ['parsed' => ['info' => ['tokenAmount' => ['amount' => bcmul('1000', '1000000', 0)]]]]]]]],
            ], $body));
        }

        // Single getTransaction
        if (($body['method'] ?? null) === 'getTransaction') {
            return Http::response([
                'result' => [
                    'meta' => [
                        'err' => null,
                        'preTokenBalances' => [
                            ['owner' => USER_WALLET, 'mint' => MINT_A, 'uiTokenAmount' => ['amount' => '5000000']],
                            ['owner' => SLOT_HOT_WALLET, 'mint' => MINT_A, 'uiTokenAmount' => ['amount' => '0']],
                        ],
                        'postTokenBalances' => [
                            ['owner' => USER_WALLET, 'mint' => MINT_A, 'uiTokenAmount' => ['amount' => '4000000']],
                            ['owner' => SLOT_HOT_WALLET, 'mint' => MINT_A, 'uiTokenAmount' => ['amount' => '1000000']],
                        ],
                    ],
                ],
            ]);
        }

        return Http::response([]);
    });

    Process::fake([
        '*slot-burn-and-payout*' => Process::result(
            output: json_encode(['txHash' => 'SOLSIG_BURN_AND_PAYOUT', 'status' => 'success']),
            exitCode: 0,
        ),
    ]);

    $prepare = $this->postJson('/api/slots/spin/prepare', [
        'wallet_address' => USER_WALLET,
        'bet_mint' => MINT_A,
        'bet_amount' => '1000000',
        'client_seed' => 'tester',
    ])->assertCreated();

    $spinId = $prepare->json('spin_id');

    $confirm = $this->postJson('/api/slots/spin/confirm', [
        'spin_id' => $spinId,
        'deposit_tx_hash' => 'DEPTX1234567890123456789012345678901234567890123456',
    ]);

    $confirm->assertOk();

    $spin = SlotSpin::find($spinId);
    expect($spin->status)->toBe(SlotSpin::STATUS_SETTLED);
    expect(hash('sha256', $spin->server_seed))->toBe($spin->server_seed_hash);
    expect($confirm->json('server_seed'))->toBe($spin->server_seed);
    expect(in_array($spin->outcome_type, ['loss', 'win'], true))->toBeTrue();

    Process::assertRan(fn ($p) => str_contains(
        is_array($p->command) ? implode(' ', $p->command) : $p->command,
        'slot-burn-and-payout.ts',
    ));
});

it('refuses to reuse the same deposit transaction', function () {
    SlotSpin::create([
        'slot_pool_id' => SlotPool::first()->id,
        'wallet_address' => USER_WALLET,
        'bet_mint' => MINT_A,
        'bet_amount' => '1000000',
        'deposit_address' => SLOT_HOT_WALLET,
        'deposit_tx_hash' => 'TAKENTX1234567890123456789012345678901234567890',
        'server_seed' => str_repeat('a', 64),
        'server_seed_hash' => hash('sha256', str_repeat('a', 64)),
        'client_seed' => 'old',
        'nonce' => 1,
        'status' => SlotSpin::STATUS_SETTLED,
    ]);

    Http::fake(['*' => Http::response([])]);

    $prepare = $this->postJson('/api/slots/spin/prepare', [
        'wallet_address' => USER_WALLET,
        'bet_mint' => MINT_A,
        'bet_amount' => '1000000',
        'client_seed' => 'tester',
    ])->assertCreated();

    $response = $this->postJson('/api/slots/spin/confirm', [
        'spin_id' => $prepare->json('spin_id'),
        'deposit_tx_hash' => 'TAKENTX1234567890123456789012345678901234567890',
    ]);

    $response->assertStatus(409);
});
