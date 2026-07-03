<?php

use App\Support\TokenAmount;

test('toRaw scales human amounts to smallest units', function () {
    expect(TokenAmount::toRaw('1.5', 9))->toBe('1500000000')
        ->and(TokenAmount::toRaw('1', 18))->toBe('1000000000000000000')
        ->and(TokenAmount::toRaw('0.000001', 6))->toBe('1')
        ->and(TokenAmount::toRaw('0', 18))->toBe('0');
});

test('toRaw truncates precision beyond the token decimals', function () {
    expect(TokenAmount::toRaw('1.2345678', 6))->toBe('1234567')
        ->and(TokenAmount::toRaw('0.9999999999', 6))->toBe('999999');
});

test('fromRaw converts smallest units back to human amounts', function () {
    expect(TokenAmount::fromRaw('1500000000', 9))->toBe('1.500000000')
        ->and(TokenAmount::fromRaw('1', 6))->toBe('0.000001')
        ->and(TokenAmount::fromRaw('42', 0))->toBe('42');
});

test('round-trips 18/9/6 decimal scales', function (string $amount, int $decimals) {
    $raw = TokenAmount::toRaw($amount, $decimals);

    expect(bccomp(TokenAmount::fromRaw($raw, $decimals), $amount, $decimals))->toBe(0);
})->with([
    ['123.456789', 18],
    ['0.000000001', 9],
    ['999999.999999', 6],
]);

test('hexToDec decodes evm quantities', function () {
    expect(TokenAmount::hexToDec('0x0'))->toBe('0')
        ->and(TokenAmount::hexToDec('0xde0b6b3a7640000'))->toBe('1000000000000000000')
        ->and(TokenAmount::hexToDec('ff'))->toBe('255');
});
