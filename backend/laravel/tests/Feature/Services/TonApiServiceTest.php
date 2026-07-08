<?php

use App\Services\TonApiService;
use Illuminate\Support\Facades\Http;

const KRSQ_MASTER_FRIENDLY = 'EQBcumfGKvl8jD1eAjRMggu7xf0JV7D1n5mj4zfYTOnuCXhp';

function tonService(): TonApiService
{
    return new TonApiService('https://tonapi.test', null, 2, 0);
}

/** Re-encode a friendly address with a different tag byte (EQ→UQ flavour). */
function reencodeWithTag(string $friendly, int $tag): string
{
    $binary = base64_decode(strtr($friendly, '-_', '+/'), true);
    $binary[0] = chr($tag);

    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

test('normalizeAddress converts friendly to raw form', function () {
    $raw = TonApiService::normalizeAddress(KRSQ_MASTER_FRIENDLY);

    expect($raw)->not->toBeNull()
        ->and($raw)->toMatch('/^0:[0-9a-f]{64}$/');
});

test('bounceable and non-bounceable flavours normalize identically', function () {
    $bounceable = TonApiService::normalizeAddress(KRSQ_MASTER_FRIENDLY);
    $nonBounceable = TonApiService::normalizeAddress(
        reencodeWithTag(KRSQ_MASTER_FRIENDLY, 0x51),
    );

    expect($nonBounceable)->toBe($bounceable);
});

test('normalizeAddress passes raw form through lowercased', function () {
    $raw = '0:'.strtoupper(str_repeat('ab', 32));

    expect(TonApiService::normalizeAddress($raw))
        ->toBe('0:'.str_repeat('ab', 32));
});

test('normalizeAddress rejects garbage', function () {
    expect(TonApiService::normalizeAddress('0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045'))->toBeNull()
        ->and(TonApiService::normalizeAddress('not-an-address'))->toBeNull()
        ->and(TonApiService::normalizeAddress(''))->toBeNull();
});

test('normalizeTxHash accepts hex and base64 encodings', function () {
    $hex = str_repeat('AB', 32);

    expect(TonApiService::normalizeTxHash($hex))->toBe(strtolower($hex));

    $base64 = base64_encode(hex2bin(strtolower($hex)));

    expect(TonApiService::normalizeTxHash($base64))->toBe(strtolower($hex))
        ->and(TonApiService::normalizeTxHash('short'))->toBeNull();
});

test('verifyJettonDeposit returns the raw amount for a matching transfer', function () {
    $sender = '0:'.str_repeat('11', 32);
    $recipient = '0:'.str_repeat('22', 32);
    $master = TonApiService::normalizeAddress(KRSQ_MASTER_FRIENDLY);

    Http::fake([
        'tonapi.test/*' => Http::response([
            'event_id' => str_repeat('ef', 32),
            'in_progress' => false,
            'actions' => [
                [
                    'type' => 'JettonTransfer',
                    'status' => 'ok',
                    'JettonTransfer' => [
                        'sender' => ['address' => $sender],
                        'recipient' => ['address' => $recipient],
                        'jetton' => ['address' => $master],
                        'amount' => '1500000000',
                    ],
                ],
            ],
        ]),
    ]);

    $verified = tonService()->verifyJettonDeposit(
        str_repeat('ab', 32),
        $sender,
        $master,
        $recipient,
    );

    expect($verified)->toBe([
        'amount' => '1500000000',
        'event_id' => str_repeat('ef', 32),
    ]);
});

test('verifyNativeDeposit returns nanotons for a matching TonTransfer', function () {
    $sender = '0:'.str_repeat('11', 32);
    $recipient = '0:'.str_repeat('22', 32);

    Http::fake([
        'tonapi.test/*' => Http::response([
            'event_id' => str_repeat('cd', 32),
            'in_progress' => false,
            'actions' => [
                [
                    'type' => 'TonTransfer',
                    'status' => 'ok',
                    // tonapi encodes TonTransfer amounts as JSON integers.
                    'TonTransfer' => [
                        'sender' => ['address' => $sender],
                        'recipient' => ['address' => $recipient],
                        'amount' => 1500000000,
                    ],
                ],
            ],
        ]),
    ]);

    expect(tonService()->verifyNativeDeposit(str_repeat('ab', 32), $sender, $recipient))
        ->toBe(['amount' => '1500000000', 'event_id' => str_repeat('cd', 32)]);
});

test('verifyNativeDeposit rejects a transfer to the wrong recipient', function () {
    $sender = '0:'.str_repeat('11', 32);

    Http::fake([
        'tonapi.test/*' => Http::response([
            'in_progress' => false,
            'actions' => [
                [
                    'type' => 'TonTransfer',
                    'status' => 'ok',
                    'TonTransfer' => [
                        'sender' => ['address' => $sender],
                        'recipient' => ['address' => '0:'.str_repeat('99', 32)],
                        'amount' => 1500000000,
                    ],
                ],
            ],
        ]),
    ]);

    expect(tonService()->verifyNativeDeposit(str_repeat('ab', 32), $sender, '0:'.str_repeat('22', 32)))
        ->toBeNull();
});

test('an external-message hash resolves to its transaction before event lookup', function () {
    $sender = '0:'.str_repeat('11', 32);
    $recipient = '0:'.str_repeat('22', 32);
    $msgHash = str_repeat('aa', 32);
    $txHash = str_repeat('bb', 32);

    // The event id 404s (TON Connect returned only the message hash), the
    // message lookup resolves the real transaction, then the event succeeds.
    Http::fake([
        "tonapi.test/v2/events/{$msgHash}" => Http::response(['error' => 'not found'], 404),
        "tonapi.test/v2/blockchain/messages/{$msgHash}/transaction" => Http::response(['hash' => $txHash]),
        "tonapi.test/v2/events/{$txHash}" => Http::response([
            'event_id' => $txHash,
            'in_progress' => false,
            'actions' => [
                [
                    'type' => 'TonTransfer',
                    'status' => 'ok',
                    'TonTransfer' => [
                        'sender' => ['address' => $sender],
                        'recipient' => ['address' => $recipient],
                        'amount' => 777,
                    ],
                ],
            ],
        ]),
    ]);

    expect(tonService()->verifyNativeDeposit($msgHash, $sender, $recipient))
        ->toBe(['amount' => '777', 'event_id' => $txHash]);
});

test('verifyJettonDeposit rejects wrong jetton or recipient', function () {
    $sender = '0:'.str_repeat('11', 32);
    $recipient = '0:'.str_repeat('22', 32);
    $master = '0:'.str_repeat('33', 32);

    Http::fake([
        'tonapi.test/*' => Http::response([
            'in_progress' => false,
            'actions' => [
                [
                    'type' => 'JettonTransfer',
                    'status' => 'ok',
                    'JettonTransfer' => [
                        'sender' => ['address' => $sender],
                        'recipient' => ['address' => $recipient],
                        'jetton' => ['address' => '0:'.str_repeat('44', 32)],
                        'amount' => '1000',
                    ],
                ],
            ],
        ]),
    ]);

    expect(tonService()->verifyJettonDeposit(str_repeat('ab', 32), $sender, $master, $recipient))
        ->toBeNull();
});

test('verifyJettonDeposit retries a 404 until the event is indexed', function () {
    $sender = '0:'.str_repeat('11', 32);
    $recipient = '0:'.str_repeat('22', 32);
    $master = '0:'.str_repeat('33', 32);
    $txHash = str_repeat('ab', 32);

    Http::fake([
        // Not a message hash — the resolution probe keeps 404ing.
        "tonapi.test/v2/blockchain/messages/{$txHash}/transaction" => Http::response(['error' => 'not found'], 404),
        "tonapi.test/v2/events/{$txHash}" => Http::sequence()
            ->push(['error' => 'not found'], 404)
            ->push([
                'in_progress' => false,
                'actions' => [
                    [
                        'type' => 'JettonTransfer',
                        'status' => 'ok',
                        'JettonTransfer' => [
                            'sender' => ['address' => $sender],
                            'recipient' => ['address' => $recipient],
                            'jetton' => ['address' => $master],
                            'amount' => '777',
                        ],
                    ],
                ],
            ]),
    ]);

    expect(tonService()->verifyJettonDeposit($txHash, $sender, $master, $recipient))
        ->toBe(['amount' => '777', 'event_id' => $txHash]);
});
