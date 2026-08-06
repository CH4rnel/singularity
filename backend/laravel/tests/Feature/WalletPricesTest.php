<?php

use App\Models\User;
use App\Services\WalletPriceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const DEXSCREENER_URL = 'https://api.dexscreener.com/*';

const COINGECKO_URL = 'https://api.coingecko.com/*';

beforeEach(function () {
    Cache::flush();
});

/** @param array<string, mixed> $overrides */
function fakePriceFeeds(array $overrides = []): void
{
    Http::fake([
        DEXSCREENER_URL => Http::response(['pairs' => [['priceUsd' => '0.0742', 'priceNative' => '0.0004']]]),
        COINGECKO_URL => Http::response([
            'solana' => ['usd' => 148.5],
            'monero' => ['usd' => 312.25],
            'ethereum' => ['usd' => 2410.0],
            'binancecoin' => ['usd' => 604.5],
        ]),
        ...$overrides,
    ]);
}

it('quotes every wallet chain in USD, including the ones sharing a coin', function () {
    fakePriceFeeds();

    $quotes = app(WalletPriceService::class)->quotes();

    // Robinhood Chain and Base both pay gas in ETH, so both quote the ETH price.
    expect($quotes['prices'])->toBe([
        'cyberia' => 0.0742,
        'robinhood' => 2410.0,
        'bnb' => 604.5,
        'base' => 2410.0,
        'solana' => 148.5,
        'monero' => 312.25,
    ])->and($quotes['fetchedAt'])->toBeString();
});

it('asks the price feed for each coin once, not once per chain', function () {
    fakePriceFeeds();

    app(WalletPriceService::class)->quotes();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'coingecko')) {
            return false;
        }

        $ids = explode(',', $request['ids']);

        return $ids === array_unique($ids) && in_array('ethereum', $ids, true);
    });
});

it('reports a missing price as null rather than zero', function () {
    fakePriceFeeds([COINGECKO_URL => Http::response('rate limited', 429)]);

    $prices = app(WalletPriceService::class)->quotes()['prices'];

    expect($prices['cyberia'])->toBe(0.0742)
        ->and($prices['solana'])->toBeNull()
        ->and($prices['monero'])->toBeNull()
        ->and($prices['base'])->toBeNull();
});

it('serves a cached quote instead of hitting the feeds on every page view', function () {
    fakePriceFeeds();

    app(WalletPriceService::class)->quotes();
    app(WalletPriceService::class)->quotes();

    Http::assertSentCount(2);
});

it('hands the wallet page its opening quotes', function () {
    fakePriceFeeds();

    $this->actingAs(User::factory()->create())
        ->get('/wallet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Wallet')
            ->where('quotes.prices.solana', 148.5));
});

it('exposes quotes to the browser without requiring a session', function () {
    fakePriceFeeds();

    $this->getJson('/api/wallet/prices')
        ->assertOk()
        ->assertJsonPath('prices.monero', 312.25);
});
