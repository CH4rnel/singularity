<?php

use App\Rules\ValidDestinationAddress;
use Illuminate\Support\Facades\Validator;

function validateAddr(string $direction, string $value): array
{
    $v = Validator::make(
        ['address' => $value],
        ['address' => [new ValidDestinationAddress($direction)]],
    );

    return $v->errors()->get('address');
}

test('accepts a valid lowercase EVM address for sol_to_evm', function () {
    expect(validateAddr('sol_to_evm', '0x1234567890123456789012345678901234567890'))
        ->toBeEmpty();
});

test('accepts a valid EIP-55 checksum EVM address', function () {
    // Vitalik's address (known valid EIP-55)
    expect(validateAddr('sol_to_evm', '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045'))
        ->toBeEmpty();
});

test('rejects EVM address with broken checksum', function () {
    expect(validateAddr('sol_to_evm', '0xD8DA6bf26964af9d7EEd9E03e53415d37aa96045'))
        ->not->toBeEmpty();
});

test('rejects Solana-looking address for sol_to_evm direction', function () {
    $errors = validateAddr('sol_to_evm', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w');
    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('Solana');
});

test('accepts a valid Solana address for evm_to_sol', function () {
    expect(validateAddr('evm_to_sol', 'E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w'))
        ->toBeEmpty();
});

test('rejects EVM address for evm_to_sol direction', function () {
    $errors = validateAddr('evm_to_sol', '0x1234567890123456789012345678901234567890');
    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('EVM');
});

test('skips empty string (delegated to required rule)', function () {
    expect(validateAddr('sol_to_evm', ''))->toBeEmpty();
    expect(validateAddr('evm_to_sol', ''))->toBeEmpty();
});

test('rejects garbage', function () {
    expect(validateAddr('sol_to_evm', 'not-an-address'))->not->toBeEmpty();
    expect(validateAddr('evm_to_sol', '!!!!!'))->not->toBeEmpty();
});

test('rejects short base58 string', function () {
    expect(validateAddr('evm_to_sol', 'abc123'))->not->toBeEmpty();
});

test('rejects EVM hex of wrong length', function () {
    expect(validateAddr('sol_to_evm', '0xabc'))->not->toBeEmpty();
    expect(validateAddr('sol_to_evm', '0x'.str_repeat('a', 41)))->not->toBeEmpty();
});

test('accepts Bitcoin addresses for evm_to_btc', function () {
    config()->set('bridge.routes.evm_to_btc.enabled', true);

    expect(validateAddr('evm_to_btc', '12ZEw5Hcv1hTb6YUQJ69y1V7uhcoDz92PH'))
        ->toBeEmpty()
        ->and(validateAddr('evm_to_btc', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kygt080'))
        ->toBeEmpty();
});

test('accepts Litecoin addresses for evm_to_ltc', function () {
    config()->set('bridge.routes.evm_to_ltc.enabled', true);

    expect(validateAddr('evm_to_ltc', 'LLnCCHbSzfwWquEdaS5TF2Yt7uz5Qb1SZ1'))
        ->toBeEmpty()
        ->and(validateAddr('evm_to_ltc', 'M9TQAWC2R2sGUmWodGk6DH6TNvVxiqXnU6'))
        ->toBeEmpty()
        ->and(validateAddr('evm_to_ltc', 'ltc1qgghl4v0w4d4w7j7zh4j3jy8az6h7x38wqx4u0s'))
        ->toBeEmpty();
});

test('accepts Monero addresses for evm_to_xmr', function () {
    config()->set('bridge.routes.evm_to_xmr.enabled', true);

    expect(validateAddr('evm_to_xmr', '4'.str_repeat('A', 94)))
        ->toBeEmpty()
        ->and(validateAddr('evm_to_xmr', '8'.str_repeat('A', 94)))
        ->toBeEmpty();
});

test('rejects wrong native address type for external chain routes', function () {
    config()->set('bridge.routes.evm_to_btc.enabled', true);
    config()->set('bridge.routes.evm_to_ltc.enabled', true);
    config()->set('bridge.routes.evm_to_xmr.enabled', true);

    expect(validateAddr('evm_to_btc', 'LLnCCHbSzfwWquEdaS5TF2Yt7uz5Qb1SZ1'))->not->toBeEmpty()
        ->and(validateAddr('evm_to_ltc', '12ZEw5Hcv1hTb6YUQJ69y1V7uhcoDz92PH'))->not->toBeEmpty()
        ->and(validateAddr('evm_to_xmr', '12ZEw5Hcv1hTb6YUQJ69y1V7uhcoDz92PH'))->not->toBeEmpty();
});
