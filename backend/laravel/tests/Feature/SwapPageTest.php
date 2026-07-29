<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('renders swap without indexer tables', function () {
    $this->get('/swap')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Swap')
            ->where('indexerReady', false)
            ->has('pools', 0)
            ->has('daily', 0));
});

it('passes indexed pools and seven day swap volume to swap', function () {
    DB::statement('CREATE TABLE activity_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, usd REAL,
        sym_in TEXT, amt_in REAL, sym_out TEXT, amt_out REAL,
        user_addr TEXT, tx_hash TEXT, block INTEGER, meta TEXT,
        created_at TEXT DEFAULT (datetime(\'now\'))
    )');
    DB::statement('CREATE TABLE dex_pools (
        pair_address TEXT PRIMARY KEY, token0 TEXT, token1 TEXT,
        symbol0 TEXT, symbol1 TEXT, reserve0 REAL, reserve1 REAL,
        tvl_usd REAL, updated_at TEXT
    )');

    DB::table('activity_events')->insert([
        [
            'kind' => 'swap',
            'usd' => 7.5,
            'sym_in' => 'WCYBER',
            'amt_in' => 3,
            'sym_out' => 'ASH',
            'amt_out' => 150,
            'created_at' => now('UTC')->subDays(60)->format('Y-m-d H:i:s'),
        ],
        [
            'kind' => 'swap',
            'usd' => 12.5,
            'sym_in' => 'ASH',
            'amt_in' => 138,
            'sym_out' => 'WCYBER',
            'amt_out' => 2.5,
            'created_at' => now('UTC')->format('Y-m-d H:i:s'),
        ],
        [
            'kind' => 'liq_add',
            'usd' => 99.0,
            'sym_in' => null,
            'amt_in' => null,
            'sym_out' => null,
            'amt_out' => null,
            'created_at' => now('UTC')->format('Y-m-d H:i:s'),
        ],
    ]);
    DB::table('dex_pools')->insert([
        [
            'pair_address' => '0xpair',
            'token0' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'token1' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'symbol0' => 'AAA',
            'symbol1' => 'BBB',
            'reserve0' => 10,
            'reserve1' => 20,
            'tvl_usd' => 30,
            'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
        ],
    ]);

    $this->get('/swap')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Swap')
            ->where('indexerReady', true)
            ->has('pools', 1)
            ->has('daily', 1)
            ->where('daily.0.swap_usd', 12.5)
            ->where('daily.0.swaps', 1));
});
