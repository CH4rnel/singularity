<?php

use App\Services\DexAprService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const APR_PAIR = '0x9298d13f57d1e5bd14c443144b500aaa210a1175';
const APR_WCYBER = '0x1111111111111111111111111111111111111111';
const APR_USDC = '0x2222222222222222222222222222222222222222';

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
    ]);

    config()->set('bridge.chains.cyberia.rpc_url', 'https://rpc.test');
    config()->set('services.dex.apr_window_blocks', 2000);
    config()->set('services.dex.apr_chunk_blocks', 1000);

    // 100 WCYBER in (100e18), 50 USDC out (50e6) — a $50 swap.
    $word = fn (string $hex) => str_pad($hex, 64, '0', STR_PAD_LEFT);
    $swapData = '0x'
        .$word('56bc75e2d63100000')
        .$word('0')
        .$word('0')
        .$word('2faf080');

    Http::fake(function ($request) use ($swapData) {
        $body = $request->data();

        return match ($body['method']) {
            'eth_blockNumber' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x7d0']),
            'eth_call' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.str_pad('12', 64, '0', STR_PAD_LEFT)]),
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
