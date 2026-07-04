<?php

use App\Services\YentenApiService;
use Illuminate\Support\Facades\Http;

const YENTEN_DEPOSIT_ADDR = 'YDepositAddr1111111111111111111111';

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
