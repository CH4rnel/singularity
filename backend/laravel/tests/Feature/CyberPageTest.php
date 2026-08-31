<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * /cyber states what the coin is for, and its value is entirely in being
 * checkable — so what is pinned here is the two ways the page could start
 * lying: printing a zero it did not measure, and citing the wrong contract
 * for a claim.
 */
beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
});

const WCYBER = '0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9';
const CYBER_USDC = '0xdc25597B19799010047F17e9591EFE08EFd40077';

it('renders without the indexer and reports unknown figures as null', function () {
    // `dex_pools` belongs to the bot, not to a migration here. A page that
    // rendered 0 CYBER in liquidity because it could not read the table would
    // be making the worst possible claim: a specific, wrong, alarming one.
    $this->get('/cyber')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Cyber')
            ->where('chain.id', 49406)
            ->where('market.price', null)
            ->where('market.pools', null)
            ->where('market.locked', null)
            ->where('market.locked_usd', null));
});

it('counts only the coin side of the pools quoted against it', function () {
    DB::statement('CREATE TABLE dex_pools (
        pair_address TEXT PRIMARY KEY, token0 TEXT, token1 TEXT,
        symbol0 TEXT, symbol1 TEXT, reserve0 REAL, reserve1 REAL,
        tvl_usd REAL, updated_at TEXT
    )');

    $rub = '0x3ce7d8e486e16bad2fb1487fe1da4dc33237d923';

    DB::table('dex_pools')->insert([
        // 1000 WCYBER against 400 USDC — the anchor puts the coin at $0.40.
        ['pair_address' => '0xp1', 'token0' => strtolower(WCYBER), 'token1' => strtolower(CYBER_USDC),
            'symbol0' => 'WCYBER', 'symbol1' => 'USDC', 'reserve0' => 1000, 'reserve1' => 400],
        // The coin on the *other* side of the pair, to catch a sum that always
        // reads reserve0.
        ['pair_address' => '0xp2', 'token0' => $rub, 'token1' => strtolower(WCYBER),
            'symbol0' => 'RUB', 'symbol1' => 'WCYBER', 'reserve0' => 900, 'reserve1' => 500],
        // Touches neither side: must not be counted as a pool quoted in CYBER.
        ['pair_address' => '0xp3', 'token0' => strtolower(CYBER_USDC), 'token1' => $rub,
            'symbol0' => 'USDC', 'symbol1' => 'RUB', 'reserve0' => 50, 'reserve1' => 100],
    ]);

    $this->get('/cyber')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Cyber')
            ->where('market.price', 0.4)
            ->where('market.pools', 2)
            // Whole numbers survive the JSON round trip as ints.
            ->where('market.locked', 1500)
            ->where('market.locked_usd', 600));
});

it('cites the lending market over the coin, not the one over the bridged Solana token', function () {
    // The deployment file calls its market over WrappedCyberSol "CYBER", which
    // is exactly the mistake this page exists to stop making: that market is
    // over a different asset. The coin's market is the one over WCYBER.
    expect(strtolower((string) config('cyber.contracts.lending_wcyber_market')))
        ->toBe('0x5ea7cfe8971ccbd521f0f9db6da7e019dbe2ab8d')
        ->and(strtolower((string) config('cyber.contracts.wcyber')))
        ->toBe(strtolower(WCYBER));
});

it('reports each corridor with the state the bridge is actually in', function () {
    // Outbound to Robinhood ships closed ("Coming soon" until the relayer holds
    // gas there) while inbound is open. A page that described the coin as
    // travelling both ways would be wrong today and stale the day it flips, so
    // the state is read from the bridge rather than written into the copy.
    $this->get('/cyber')
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $corridors = collect($page->toArray()['props']['corridors']);

            $outbound = $corridors->firstWhere(fn (array $c): bool => $c['token'] === 'CYBER'
                && str_contains(strtolower($c['to']), 'robinhood'));

            expect($corridors)->not->toBeEmpty()
                ->and($outbound)->not->toBeNull()
                ->and($outbound['open'])->toBeFalse()
                ->and($outbound['note'])->not->toBeNull();
        });
});
