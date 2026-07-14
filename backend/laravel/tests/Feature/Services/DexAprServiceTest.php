<?php

use App\Services\DexAprService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const APR_PAIR = '0x9298d13f57d1e5bd14c443144b500aaa210a1175';
const APR_WCYBER = '0x1111111111111111111111111111111111111111';
const APR_USDC = '0x2222222222222222222222222222222222222222';
const APR_CHEF = '0x3333333333333333333333333333333333333333';
const APR_REWARD = '0x4444444444444444444444444444444444444444';

beforeEach(function () {
    // The dex_pools / token_prices tables are created by the Telegram bot's
    // indexer, not by migrations — mirror their shape here.
    Schema::create('dex_pools', function (Blueprint $table) {
        $table->string('pair_address')->primary();
        $table->string('token0')->nullable();
        $table->string('token1')->nullable();
        $table->string('symbol0')->nullable();
        $table->string('symbol1')->nullable();
        $table->float('reserve0')->nullable();
        $table->float('reserve1')->nullable();
        $table->float('tvl_usd')->nullable();
        $table->text('updated_at')->nullable();
    });
    Schema::create('token_prices', function (Blueprint $table) {
        $table->string('address')->primary();
        $table->string('symbol')->nullable();
        $table->float('price_usd')->nullable();
        $table->text('updated_at')->nullable();
    });

    DB::table('dex_pools')->insert([
        'pair_address' => APR_PAIR,
        'token0' => APR_WCYBER,
        'token1' => APR_USDC,
        'symbol0' => 'WCYBER',
        'symbol1' => 'USDC',
        'reserve0' => 1000.0,
        'reserve1' => 500.0,
        'tvl_usd' => 1000.0,
        'updated_at' => now('UTC')->toDateTimeString(),
    ]);
    DB::table('token_prices')->insert([
        ['address' => APR_WCYBER, 'symbol' => 'WCYBER', 'price_usd' => 0.5, 'updated_at' => now('UTC')->toDateTimeString()],
        ['address' => APR_USDC, 'symbol' => 'USDC', 'price_usd' => 1.0, 'updated_at' => now('UTC')->toDateTimeString()],
        ['address' => APR_REWARD, 'symbol' => 'LAIN', 'price_usd' => 0.5, 'updated_at' => now('UTC')->toDateTimeString()],
    ]);

    config()->set('bridge.chains.cyberia.rpc_url', 'https://rpc.test');
    config()->set('services.dex.apr_window_blocks', 2000);
    config()->set('services.dex.apr_chunk_blocks', 1000);
    config()->set('services.dex.masterchef', APR_CHEF);

    // 100 WCYBER in (100e18), 50 USDC out (50e6) — a $50 swap.
    $word = fn (string $hex) => str_pad($hex, 64, '0', STR_PAD_LEFT);
    $swapData = '0x'
        .$word('56bc75e2d63100000')
        .$word('0')
        .$word('0')
        .$word('2faf080');

    $word = fn (string $hex) => '0x'.str_pad($hex, 64, '0', STR_PAD_LEFT);

    // Fake MasterChef: one active pool staking half of APR_PAIR's LP supply,
    // emitting 1 LAIN (18 dec, $0.5) per ~1s block.
    $ethCall = function (array $params) use ($word) {
        $data = (string) ($params[0]['data'] ?? '');

        $result = match (true) {
            str_starts_with($data, '0x081e3eda') => $word('1'), // poolLength
            str_starts_with($data, '0x17caf6f1') => $word('64'), // totalAllocPoint = 100
            str_starts_with($data, '0x8ae39cac') => $word(dechex(10 ** 18)), // rewardPerBlock
            str_starts_with($data, '0xf7c618c1') => $word(substr(APR_REWARD, 2)), // rewardToken
            // poolInfo(0): lpToken = APR_PAIR, allocPoint = 100
            str_starts_with($data, '0x1526fe27') => '0x'
                .str_pad(substr(APR_PAIR, 2), 64, '0', STR_PAD_LEFT)
                .str_pad('64', 64, '0', STR_PAD_LEFT)
                .str_repeat('0', 128),
            str_starts_with($data, '0x70a08231') => $word('1f4'), // staked = 500
            str_starts_with($data, '0x18160ddd') => $word('3e8'), // totalSupply = 1000
            default => $word('12'), // decimals() = 18
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    };

    Http::fake(function ($request) use ($swapData, $ethCall) {
        $body = $request->data();

        return match ($body['method']) {
            'eth_blockNumber' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x7d0']),
            'eth_call' => $ethCall($body['params']),
            // The swap lands in the first 1000-block chunk only; the second
            // chunk is empty. Double-counting across chunks would double APR.
            'eth_getLogs' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => $body['params'][0]['fromBlock'] === '0x1'
                    ? [[
                        'address' => '0x9298d13f57D1e5bD14C443144b500aaa210a1175',
                        'topics' => [],
                        'data' => $swapData,
                    ]]
                    : [],
            ]),
            default => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]),
        };
    });
});

test('snapshot annualizes 24h swap fees against pool TVL', function () {
    $snapshot = app(DexAprService::class)->snapshot();

    expect($snapshot['pools'])->toHaveCount(1);

    $pool = $snapshot['pools'][0];

    // $50 volume → $0.15 LP fees → 0.15 * 365 / 1000 TVL = 5.48% APR.
    expect($pool['pair_address'])->toBe(APR_PAIR)
        ->and($pool['volume_24h_usd'])->toBe(50.0)
        ->and($pool['fees_24h_usd'])->toBe(0.15)
        ->and($pool['apr'])->toBe(5.48);

    expect(DexAprService::cached()['pools'][0]['apr'])->toBe(5.48);
});

test('an unpriced reward token is valued through dex_pools reserves', function () {
    // The bot's price walker often misses fresh launchpad tokens (ASH on
    // prod): price_usd stays null and every farm APY would vanish. The spot
    // fallback derives it from any pair with a priced other side.
    DB::table('token_prices')->where('address', APR_REWARD)->update(['price_usd' => null]);
    DB::table('dex_pools')->insert([
        'pair_address' => '0x5555555555555555555555555555555555555555',
        'token0' => APR_REWARD,
        'token1' => APR_USDC,
        'symbol0' => 'LAIN',
        'symbol1' => 'USDC',
        'reserve0' => 200.0,
        'reserve1' => 100.0, // 100 USDC / 200 LAIN → $0.5, same as before
        'tvl_usd' => 200.0,
        'updated_at' => now('UTC')->toDateTimeString(),
    ]);

    $snapshot = app(DexAprService::class)->snapshot();
    $farm = collect($snapshot['farms'])->firstWhere('label', 'WCYBER/USDC LP');

    expect($farm['apy'])->toBe(3153600.0);
});

test('farm APY annualizes emissions against staked value', function () {
    $snapshot = app(DexAprService::class)->snapshot();

    expect($snapshot['farms'])->toHaveCount(1);

    $farm = $snapshot['farms'][0];

    // 1 LAIN/block × 86400 × 365 × $0.5 = $15,768,000/year of emissions.
    // Staked: half the LP supply of a $1000 pool = $500 → APY 3,153,600%.
    expect($farm['label'])->toBe('WCYBER/USDC LP')
        ->and($farm['staked_usd'])->toBe(500.0)
        ->and($farm['reward_share'])->toBe(1.0)
        ->and($farm['apy'])->toBe(3153600.0);
});

test('apr endpoint serves the cached snapshot', function () {
    $this->getJson('/api/dex/apr')
        ->assertOk()
        ->assertJson(['updated_at' => null, 'pools' => []]);

    app(DexAprService::class)->snapshot();

    $this->getJson('/api/dex/apr')
        ->assertOk()
        ->assertJsonPath('pools.0.apr', 5.48);
});

test('dex:apr command reports the snapshot', function () {
    $this->artisan('dex:apr')
        ->expectsOutputToContain('best APR 5.48%')
        ->assertSuccessful();
});
