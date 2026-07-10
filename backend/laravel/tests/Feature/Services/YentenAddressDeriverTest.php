<?php

use App\Services\Yenten\YentenAddressDeriver;

const TEST_SEED = 'abababababababababababababababababababababababababababababababab';

test('derives valid Yenten P2PKH addresses', function () {
    $deriver = new YentenAddressDeriver(TEST_SEED);

    // Yenten P2PKH addresses start with Y (version byte 0x4e).
    expect($deriver->depositAddress(1))->toStartWith('Y')
        ->and($deriver->depositAddress(1))->toMatch('/^Y[1-9A-HJ-NP-Za-km-z]{25,34}$/');
});

test('derivation matches the pinned pre-refactor vector', function () {
    // Live one-time deposit addresses were issued under this exact KDF
    // ('ytn-deposit:{index}:{counter}'); any drift strands real deposits.
    expect((new YentenAddressDeriver(TEST_SEED))->depositAddress(7))
        ->toBe('YjfVNJXfykmVCdRXLRx6ssySnBnPtQweqQ');
});

test('derivation is deterministic per index', function () {
    $a = new YentenAddressDeriver(TEST_SEED);
    $b = new YentenAddressDeriver(TEST_SEED);

    expect($a->depositAddress(42))->toBe($b->depositAddress(42))
        ->and($a->childWif(42))->toBe($b->childWif(42));
});

test('different indices yield different addresses', function () {
    $deriver = new YentenAddressDeriver(TEST_SEED);

    $addresses = array_map(fn ($i) => $deriver->depositAddress($i), range(1, 10));

    expect(array_unique($addresses))->toHaveCount(10);
});

test('a different seed yields different addresses', function () {
    $one = new YentenAddressDeriver(TEST_SEED);
    $two = new YentenAddressDeriver(str_repeat('cd', 32));

    expect($one->depositAddress(1))->not->toBe($two->depositAddress(1));
});

test('emits compressed-key WIFs (start with K or L)', function () {
    $deriver = new YentenAddressDeriver(TEST_SEED);

    // Yenten WIF version 0x7b with the 0x01 compression flag base58checks to
    // a leading K or L.
    expect($deriver->childWif(1))->toMatch('/^[KL][1-9A-HJ-NP-Za-km-z]{50,52}$/');
});

test('rejects an unconfigured seed', function () {
    expect(fn () => (new YentenAddressDeriver(''))->depositAddress(1))
        ->toThrow(RuntimeException::class);
});
