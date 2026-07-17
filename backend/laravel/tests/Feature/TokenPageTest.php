<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// USDC is a documented $1 anchor; WCYBER is documented and priced via the pool
// graph. Addresses match config/tokens.php (lowercased).
const USDC = '0xdc25597b19799010047f17e9591efe08efd40077';
const WCYBER = '0x78272aad03e4b9d7a9134e874ba6d419b534f6c9';
const HATCHER = '0x621021f18b6404123f98b1395c418868418acf36';

it('lists the token directory grouped by category', function () {
    $this->get('/tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tokens')
            ->where('count', count(config('tokens.list')))
            ->has('groups.0.tokens.0.address'));
});

it('renders a documented token by address', function () {
    $this->get('/token/'.USDC)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Token')
            ->where('token.symbol', 'USDC')
            ->where('token.address', USDC)
            ->where('token.isKnown', true)
            ->whereNot('token.what', null)
            ->whereNot('token.why', null));
});

it('resolves a token by symbol, case-insensitively', function () {
    $this->get('/token/usdc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Token')
            ->where('token.address', USDC));
});

it('renders the Hatcher platform copy', function () {
    $this->get('/token/'.HATCHER)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Token')
            ->where('token.symbol', 'HATCHER')
            ->where('token.what', 'Hatcher is a managed AI Agents hosting platform. It has features like easy agent deployment just by chatting, out-of-box preconfigured agents, mobile apps available, a full 3D city and rooms for agents, e-mails and many more.'));
});

it('still renders an undocumented but well-formed address instead of 404', function () {
    $this->get('/token/0x000000000000000000000000000000000000dEaD')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Token')
            ->where('token.isKnown', false));
});

it('404s on an unknown symbol', function () {
    $this->get('/token/NOTATOKEN')->assertNotFound();
});

it('prices a token and lists its pools from the DEX pool graph', function () {
    DB::statement('CREATE TABLE dex_pools (
        pair_address TEXT PRIMARY KEY, token0 TEXT, token1 TEXT,
        symbol0 TEXT, symbol1 TEXT, reserve0 REAL, reserve1 REAL,
        tvl_usd REAL, updated_at TEXT
    )');
    // WCYBER/USDC at 1000:400 makes WCYBER = 400/1000 = $0.40.
    DB::table('dex_pools')->insert([
        'pair_address' => '0xpair1', 'token0' => WCYBER, 'token1' => USDC,
        'symbol0' => 'WCYBER', 'symbol1' => 'USDC', 'reserve0' => 1000.0,
        'reserve1' => 400.0, 'tvl_usd' => null, 'updated_at' => '2026-06-11 12:00:00',
    ]);

    $this->get('/token/'.WCYBER)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Token')
            ->where('token.price', 0.4)
            ->has('pools', 1)
            ->where('pools.0.other_symbol', 'USDC')
            ->where('pools.0.other_known', true));
});
