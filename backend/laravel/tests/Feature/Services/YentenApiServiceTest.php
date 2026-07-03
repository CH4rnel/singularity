<?php

use App\Services\YentenApiService;
use Illuminate\Support\Facades\Http;

const YENTEN_TX_HASH = '62cfd7ed37b4471a5949aae2c7b09e4db34d2d9dac7b43c925d81cf583c8536f';

function fakeYentenTransaction(array $overrides = []): array
{
    return array_replace_recursive([
        'txid' => YENTEN_TX_HASH,
        'confirmations' => 7,
        'vin' => [[
            'scriptPubKey' => ['addresses' => ['YSender1111111111111111111111111111']],
        ]],
        'vout' => [
            [
                'value' => 125000000,
                'scriptPubKey' => ['addresses' => ['YHotWallet11111111111111111111111111']],
            ],
            [
                'value' => 50000000,
                'scriptPubKey' => ['addresses' => ['YChange111111111111111111111111111']],
            ],
        ],
    ], $overrides);
}

test('verifies a confirmed Yenten deposit and returns satoshis', function () {
    Http::fake([
        'api.yentencoin.info/transaction/*' => Http::response([
            'result' => fakeYentenTransaction(),
            'error' => null,
        ]),
    ]);

    $amount = app(YentenApiService::class)->verifyDeposit(
        YENTEN_TX_HASH,
        'YSender1111111111111111111111111111',
        'YHotWallet11111111111111111111111111',
    );

    expect($amount)->toBe('125000000');
});

test('sums multiple outputs sent to the Yenten hot wallet', function () {
    $transaction = fakeYentenTransaction();
    $transaction['vout'][] = [
        'value' => '25000000',
        'scriptPubKey' => ['addresses' => ['YHotWallet11111111111111111111111111']],
    ];

    Http::fake([
        'api.yentencoin.info/transaction/*' => Http::response([
            'result' => $transaction,
            'error' => null,
        ]),
    ]);

    $amount = app(YentenApiService::class)->verifyDeposit(
        YENTEN_TX_HASH,
        'YSender1111111111111111111111111111',
        'YHotWallet11111111111111111111111111',
    );

    expect($amount)->toBe('150000000');
});

test('rejects an unconfirmed or mismatched Yenten deposit', function (array $overrides) {
    Http::fake([
        'api.yentencoin.info/transaction/*' => Http::response([
            'result' => fakeYentenTransaction($overrides),
            'error' => null,
        ]),
    ]);

    $amount = app(YentenApiService::class)->verifyDeposit(
        YENTEN_TX_HASH,
        'YSender1111111111111111111111111111',
        'YHotWallet11111111111111111111111111',
    );

    expect($amount)->toBeNull();
})->with([
    'unconfirmed' => [['confirmations' => 0]],
    'wrong sender' => [['vin' => [[
        'scriptPubKey' => ['addresses' => ['YSomeoneElse111111111111111111111111']],
    ]]]],
    'wrong recipient' => [['vout' => [[
        'value' => 125000000,
        'scriptPubKey' => ['addresses' => ['YSomeoneElse111111111111111111111111']],
    ]]]],
]);
