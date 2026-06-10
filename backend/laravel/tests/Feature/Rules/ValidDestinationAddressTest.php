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
