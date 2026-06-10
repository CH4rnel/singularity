<?php

use App\Models\SlotPool;
use App\Models\SlotPoolToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.slots.rpc_url', 'https://slot.rpc.test');
    config()->set('services.slots.pumpfun_api_base', 'https://frontend-api-v3.pump.fun');
    config()->set('services.slots.pumpfun_auto_enable', true);

    SlotPool::create([
        'name' => 'test',
        'status' => 'active',
        'hot_wallet_address' => 'HOT11111111111111111111111111111111111111',
        'burn_bps' => 200,
        'house_edge_bps' => 400,
        'jackpot_threshold_bps' => 0,
        'max_single_win_bps' => 2000,
        'jackpot_basket_bps' => 2500,
        'jackpot_basket_size' => 5,
    ]);
});

function fakeBulk(array $coins): void
{
    Http::fake(function ($request) use ($coins) {
        $url = $request->url();

        if (str_contains($url, 'frontend-api-v3.pump.fun/coins')) {
            return Http::response($coins);
        }

        // Helius DAS getAsset — return clean metadata for any mint.
        $body = $request->data();
        $mint = (string) ($body['params']['id'] ?? 'UNKNOWN');

        return Http::response([
            'result' => [
                'content' => [
                    'metadata' => ['symbol' => substr($mint, 0, 4), 'name' => $mint],
                    'links' => ['image' => 'https://cf-ipfs.com/ipfs/'.$mint],
                ],
                'token_info' => [
                    'symbol' => substr($mint, 0, 4),
                    'decimals' => 6,
                    'token_program' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA',
                    'freeze_authority' => null,
                ],
            ],
        ]);
    });
}

it('imports top pump.fun coins and marks them enabled', function () {
    fakeBulk([
        ['mint' => 'MintAAA1111111111111111111111111111111111111', 'symbol' => 'AAA', 'name' => 'A', 'image_uri' => 'x', 'usd_market_cap' => 50000],
        ['mint' => 'MintBBB1111111111111111111111111111111111111', 'symbol' => 'BBB', 'name' => 'B', 'image_uri' => 'y', 'usd_market_cap' => 30000],
    ]);

    $this->artisan('slots:import-pumpfun', ['--top' => 10, '--min-mcap' => 10000])
        ->assertSuccessful();

    expect(SlotPoolToken::count())->toBe(2);
    $aaa = SlotPoolToken::where('mint', 'MintAAA1111111111111111111111111111111111111')->first();
    expect($aaa->enabled)->toBeTrue();
    expect($aaa->source)->toBe(SlotPoolToken::SOURCE_PUMPFUN_BULK);
    expect((string) $aaa->pumpfun_market_cap_usd)->toBe('50000.00');
});

it('is idempotent and updates last_seen_at on rerun', function () {
    $coins = [
        ['mint' => 'MintAAA1111111111111111111111111111111111111', 'symbol' => 'AAA', 'name' => 'A', 'image_uri' => 'x', 'usd_market_cap' => 50000],
    ];

    fakeBulk($coins);
    $this->artisan('slots:import-pumpfun', ['--top' => 10, '--min-mcap' => 10000])->assertSuccessful();
    $first = SlotPoolToken::first()->pumpfun_last_seen_at;

    // Re-run after a moment.
    Carbon::setTestNow(now()->addMinute());
    fakeBulk($coins);
    $this->artisan('slots:import-pumpfun', ['--top' => 10, '--min-mcap' => 10000])->assertSuccessful();

    expect(SlotPoolToken::count())->toBe(1);
    expect(SlotPoolToken::first()->pumpfun_last_seen_at->gt($first))->toBeTrue();
});

it('disables stale bulk tokens with zero balance', function () {
    // Seed a previously-imported bulk token that no longer appears in the API.
    SlotPoolToken::create([
        'slot_pool_id' => SlotPool::first()->id,
        'mint' => 'OldMint1111111111111111111111111111111111111',
        'token_program' => 'token',
        'decimals' => 6,
        'symbol' => 'OLD',
        'enabled' => true,
        'current_balance' => '0',
        'source' => SlotPoolToken::SOURCE_PUMPFUN_BULK,
        'min_bet' => '0',
    ]);

    fakeBulk([
        ['mint' => 'MintAAA1111111111111111111111111111111111111', 'symbol' => 'AAA', 'name' => 'A', 'image_uri' => 'x', 'usd_market_cap' => 50000],
    ]);

    $this->artisan('slots:import-pumpfun', ['--top' => 10, '--min-mcap' => 10000])->assertSuccessful();

    $old = SlotPoolToken::where('mint', 'OldMint1111111111111111111111111111111111111')->first();
    expect($old->enabled)->toBeFalse();
});

it('keeps stale bulk tokens enabled when they hold a balance', function () {
    SlotPoolToken::create([
        'slot_pool_id' => SlotPool::first()->id,
        'mint' => 'FundedMint11111111111111111111111111111111111',
        'token_program' => 'token',
        'decimals' => 6,
        'symbol' => 'FUND',
        'enabled' => true,
        'current_balance' => '5000000', // non-zero
        'source' => SlotPoolToken::SOURCE_PUMPFUN_BULK,
        'min_bet' => '0',
    ]);

    fakeBulk([
        ['mint' => 'MintXYZ1111111111111111111111111111111111111', 'symbol' => 'XYZ', 'name' => 'X', 'image_uri' => 'x', 'usd_market_cap' => 50000],
    ]);

    $this->artisan('slots:import-pumpfun', ['--top' => 10, '--min-mcap' => 10000])->assertSuccessful();

    $funded = SlotPoolToken::where('mint', 'FundedMint11111111111111111111111111111111111')->first();
    expect($funded->enabled)->toBeTrue();
});
