<?php

use App\Services\Achievements\ChainIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Detection reads the explorer's whole history, and says so when it cannot.
 *
 * The bug this replaces was not a wrong answer, it was a confident one: the
 * announcer feed starts at the head of the chain, so "no lending events" meant
 * "none since August" and was read as "never". Everything pinned here is about
 * keeping a "no" honest — a truncated scan, an unreachable explorer and a real
 * absence must not look alike.
 */
const COMPTROLLER = '0x00000000000000000000000000000000000000c0';
const MARKET = '0x00000000000000000000000000000000000000aa';
const ROUTER = '0x00000000000000000000000000000000000000b0';
const SWAPPER = '0x00000000000000000000000000000000000000d0';
const HOLDER = '0x1111111111111111111111111111111111111111';

beforeEach(function () {
    Cache::flush();
    config()->set('cyber.chain.explorer', 'https://explorer.test');
    config()->set('cyber.chain.rpc', 'https://rpc.test');
    config()->set('cyber.contracts.lending_comptroller', COMPTROLLER);
    config()->set('cyber.contracts.dex_router', ROUTER);
    config()->set('cyber.contracts.cyber_sol_swap', SWAPPER);
});

/** getAllMarkets() -> [MARKET] */
function marketsResponse(): array
{
    return ['result' => '0x'
        .str_pad('20', 64, '0', STR_PAD_LEFT)
        .str_pad('1', 64, '0', STR_PAD_LEFT)
        .str_pad(substr(MARKET, 2), 64, '0', STR_PAD_LEFT)];
}

function txlist(array $rows): array
{
    return ['message' => 'OK', 'result' => $rows];
}

function tx(string $to, string $input): array
{
    return ['to' => $to, 'input' => $input, 'hash' => '0x'.str_repeat('1', 64)];
}

function fakeChain(array $rows): void
{
    Http::fake([
        'rpc.test' => Http::response(marketsResponse()),
        'explorer.test/api*' => Http::response(txlist($rows)),
    ]);
}

it('finds lending far older than the announcer feed', function () {
    fakeChain([tx(MARKET, '0xa0712d68000000')]);

    expect(app(ChainIndex::class)->usedLending(HOLDER))->toBeTrue();
});

it('counts entering a market as using it', function () {
    fakeChain([tx(COMPTROLLER, '0xc2998238000000')]);

    expect(app(ChainIndex::class)->usedLending(HOLDER))->toBeTrue();
});

it('does not mistake a swap for adding liquidity', function () {
    // Same router, different selector: swapExactTokensForTokens.
    fakeChain([tx(ROUTER, '0x38ed1739000000')]);

    expect(app(ChainIndex::class)->addedLiquidity(HOLDER))->toBeFalse();
});

it('recognises adding liquidity', function () {
    fakeChain([tx(ROUTER, '0xe8e33700000000')]);

    expect(app(ChainIndex::class)->addedLiquidity(HOLDER))->toBeTrue();
});

it('takes any call to the converter as a conversion', function () {
    fakeChain([tx(SWAPPER, '0xdeadbeef')]);

    expect(app(ChainIndex::class)->convertedCyberSol(HOLDER))->toBeTrue();
});

it('answers a genuine absence with no', function () {
    fakeChain([tx('0x9999999999999999999999999999999999999999', '0xa0712d68')]);

    expect(app(ChainIndex::class)->usedLending(HOLDER))->toBeFalse();
});

it('answers null rather than no when the explorer is down', function () {
    Http::fake([
        'rpc.test' => Http::response(marketsResponse()),
        'explorer.test/api*' => Http::response('nope', 503),
    ]);

    expect(app(ChainIndex::class)->usedLending(HOLDER))->toBeNull();
});

it('answers null rather than no when the history is longer than the budget', function () {
    // Every page full means the scan never reached the end: what it holds is a
    // prefix, and a "no" from a prefix is not a no.
    Http::fake([
        'rpc.test' => Http::response(marketsResponse()),
        'explorer.test/api*' => Http::response(txlist(
            array_fill(0, 100, tx('0x9999999999999999999999999999999999999999', '0x00000000')),
        )),
    ]);

    expect(app(ChainIndex::class)->usedLending(HOLDER))->toBeNull();
});

it('answers null when the comptroller cannot be read', function () {
    Http::fake([
        'rpc.test' => Http::response(['error' => ['message' => 'boom']], 500),
        'explorer.test/api*' => Http::response(txlist([])),
    ]);

    expect(app(ChainIndex::class)->usedLending(HOLDER))->toBeNull();
});

it('treats an empty history as an empty history, not a failure', function () {
    // Blockscout answers "no transactions found" with a string, not a list.
    Http::fake([
        'rpc.test' => Http::response(marketsResponse()),
        'explorer.test/api*' => Http::response(['message' => 'No transactions found', 'result' => 'x']),
    ]);

    expect(app(ChainIndex::class)->usedLending(HOLDER))->toBeFalse();
});

it('refuses to scan something that is not an address', function () {
    Http::fake();

    expect(app(ChainIndex::class)->callsBy('not-an-address'))->toBeNull();
    Http::assertNothingSent();
});

it('reads one address once', function () {
    fakeChain([tx(MARKET, '0xa0712d68')]);

    $index = app(ChainIndex::class);
    $index->usedLending(HOLDER);
    $index->addedLiquidity(HOLDER);
    $index->convertedCyberSol(HOLDER);

    expect(collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'explorer.test'))
        ->count())->toBe(1);
});
