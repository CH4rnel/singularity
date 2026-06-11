<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x10'], 200)]);
});

it('renders without the bot-owned tables', function () {
    // The indexer tables (activity_events, token_prices, dex_pools) are
    // created by the Telegram bot, not by migrations — the page must still
    // render when they are absent.
    $this->get('/analytics')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics')
            ->where('indexerReady', false)
            ->where('cyberPrice', null));
});

it('aggregates indexer data when the tables exist', function () {
    DB::statement('CREATE TABLE activity_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, usd REAL,
        sym_in TEXT, amt_in REAL, sym_out TEXT, amt_out REAL,
        user_addr TEXT, tx_hash TEXT, block INTEGER, meta TEXT,
        created_at TEXT DEFAULT (datetime(\'now\'))
    )');
    DB::statement('CREATE TABLE token_prices (
        address TEXT PRIMARY KEY, symbol TEXT, price_usd REAL, updated_at TEXT
    )');
    DB::statement('CREATE TABLE dex_pools (
        pair_address TEXT PRIMARY KEY, token0 TEXT, token1 TEXT,
        symbol0 TEXT, symbol1 TEXT, reserve0 REAL, reserve1 REAL,
        tvl_usd REAL, updated_at TEXT
    )');

    DB::table('activity_events')->insert([
        ['kind' => 'swap', 'usd' => 100.0, 'sym_in' => 'WCYBER', 'amt_in' => 60, 'sym_out' => 'USDC', 'amt_out' => 100, 'user_addr' => '0xaaa', 'tx_hash' => '0x1'],
        ['kind' => 'swap', 'usd' => null, 'sym_in' => 'RUB', 'amt_in' => 10, 'sym_out' => 'TRUR', 'amt_out' => 0.5, 'user_addr' => '0xbbb', 'tx_hash' => '0x2'],
        ['kind' => 'liq_add', 'usd' => 50.0, 'sym_in' => 'WCYBER', 'amt_in' => 1, 'sym_out' => 'USDC', 'amt_out' => 1, 'user_addr' => '0xaaa', 'tx_hash' => '0x3'],
    ]);
    DB::table('token_prices')->insert([
        ['address' => '0x7dcd', 'symbol' => 'CYBER.sol', 'price_usd' => 0.0001, 'updated_at' => '2026-06-11 12:00:00'],
    ]);
    DB::table('dex_pools')->insert([
        ['pair_address' => '0xpair', 'token0' => '0x1', 'token1' => '0x2', 'symbol0' => 'WCYBER', 'symbol1' => 'USDC', 'reserve0' => 1.5, 'reserve1' => 0.6, 'tvl_usd' => 2.1, 'updated_at' => '2026-06-11 12:00:00'],
    ]);

    $this->get('/analytics?days=7')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics')
            ->where('indexerReady', true)
            ->where('days', 7)
            ->where('swaps.count', 2)
            ->where('swaps.volume_usd', 100)
            ->where('swaps.traders', 2)
            ->where('swaps.unpriced', 1)
            ->where('cyberPrice', 0.0001)
            ->where('tvlUsd', 2.1)
            ->has('pools', 1)
            ->has('recent', 3));
});
