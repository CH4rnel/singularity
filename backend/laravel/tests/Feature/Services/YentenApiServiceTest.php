<?php

use App\Services\YentenApiService;
use Illuminate\Support\Facades\Http;

const YENTEN_DEPOSIT_ADDR = 'YDepositAddr1111111111111111111111';

beforeEach(function () {
    config()->set('bridge.chains.yenten.balance_api_urls', ['https://api.yentencoin.info']);
});

test('sums the unspent balance of a deposit address', function () {
    Http::fake([
        'api.yentencoin.info/unspent/*' => Http::response([
            'result' => [
                ['txid' => str_repeat('a', 64), 'index' => 0, 'value' => 125000000, 'height' => 10],
                ['txid' => str_repeat('b', 64), 'index' => 1, 'value' => 25000000, 'height' => 11],
            ],
            'error' => null,
        ]),
    ]);

    expect(app(YentenApiService::class)->addressBalance(YENTEN_DEPOSIT_ADDR))
        ->toBe('150000000');
});

test('checks explorer2 blockbook before the legacy light-wallet API', function () {
    config()->set('bridge.chains.yenten.balance_api_urls', [
        'https://explorer2.yentencoin.info',
        'https://api.yentencoin.info',
    ]);

    Http::fake([
        'explorer2.yentencoin.info/api/address/*' => Http::response([
            'balance' => '13800000000',
            'unconfirmedBalance' => '0',
        ]),
        'api.yentencoin.info/*' => Http::response(['error' => 'legacy should not be called'], 500),
    ]);

    expect(app(YentenApiService::class)->addressBalances(YENTEN_DEPOSIT_ADDR))
        ->toBe(['confirmed' => '13800000000', 'pending' => '0']);

    Http::assertSentCount(1);
});

test('falls back to the legacy unspent API when explorer2 is unavailable', function () {
    config()->set('bridge.chains.yenten.balance_api_urls', [
        'https://explorer2.yentencoin.info',
        'https://api.yentencoin.info',
    ]);

    Http::fake([
        'explorer2.yentencoin.info/api/address/*' => Http::response([], 503),
        'explorer2.yentencoin.info/unspent/*' => Http::response([], 503),
        'api.yentencoin.info/api/address/*' => Http::response(['unsupported' => true]),
        'api.yentencoin.info/unspent/*' => Http::response([
            'result' => [
                ['txid' => str_repeat('a', 64), 'index' => 0, 'value' => 13800000000, 'height' => 10],
            ],
            'error' => null,
        ]),
    ]);

    expect(app(YentenApiService::class)->addressBalances(YENTEN_DEPOSIT_ADDR))
        ->toBe(['confirmed' => '13800000000', 'pending' => '0']);
});

test('unconfirmed UTXOs count as pending, never as mintable balance', function () {
    Http::fake([
        'api.yentencoin.info/unspent/*' => Http::response([
            'result' => [
                ['txid' => str_repeat('a', 64), 'index' => 0, 'value' => 125000000, 'height' => 10],
                ['txid' => str_repeat('b', 64), 'index' => 1, 'value' => 25000000, 'height' => 0],
            ],
            'error' => null,
        ]),
    ]);

    $service = app(YentenApiService::class);

    expect($service->addressBalances(YENTEN_DEPOSIT_ADDR))
        ->toBe(['confirmed' => '125000000', 'pending' => '25000000']);
    expect($service->addressBalance(YENTEN_DEPOSIT_ADDR))->toBe('125000000');
});

test('returns zero when the address has no unspent outputs', function () {
    Http::fake([
        'api.yentencoin.info/unspent/*' => Http::response(['result' => [], 'error' => null]),
    ]);

    expect(app(YentenApiService::class)->addressBalance(YENTEN_DEPOSIT_ADDR))->toBe('0');
});

test('returns null when the Yenten API errors', function () {
    Http::fake([
        'api.yentencoin.info/unspent/*' => Http::response(['error' => 'boom'], 500),
    ]);

    expect(app(YentenApiService::class)->addressBalance(YENTEN_DEPOSIT_ADDR))->toBeNull();
});
