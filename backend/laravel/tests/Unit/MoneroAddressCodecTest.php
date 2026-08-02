<?php

use App\Services\Monero\MoneroAddressCodec;
use kornrunner\Keccak;

// Public Monero project donation address (standard, netbyte 0x12).
const XMR_STANDARD = '44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A';

// Public Monero CCS donation SUBADDRESS (netbyte 0x2a).
const XMR_SUBADDRESS = '888tNkZrPN6JsEgekjMnABU4TBzc2Dt29EPAvkRxbANsAnjyPbb3iQ1YBRk1UXcdRsiKc9dhwMVgN5S9cQUiyoogDavup3H';

test('recognises the three mainnet address kinds', function () {
    $integrated = MoneroAddressCodec::integratedAddress(XMR_STANDARD, hex2bin('0011223344556677'));

    expect(MoneroAddressCodec::kind(XMR_STANDARD))->toBe('standard')
        ->and(MoneroAddressCodec::kind(XMR_SUBADDRESS))->toBe('subaddress')
        ->and(MoneroAddressCodec::kind($integrated))->toBe('integrated')
        ->and(strlen($integrated))->toBe(106);
});

test('re-encodes a decoded address byte for byte', function () {
    $payload = MoneroAddressCodec::decodeChecked(XMR_STANDARD);
    $checksum = substr(Keccak::hash($payload, 256, true), 0, 4);

    expect(MoneroAddressCodec::encode($payload.$checksum))->toBe(XMR_STANDARD);
});

test('rejects a single mistyped character', function () {
    // Same length, same alphabet, one character off — only the checksum can
    // tell, and it must, because an XMR payout cannot be recalled.
    $typo = substr_replace(XMR_STANDARD, XMR_STANDARD[40] === 'A' ? 'B' : 'A', 40, 1);

    expect($typo)->not->toBe(XMR_STANDARD)
        ->and(MoneroAddressCodec::isValid($typo))->toBeFalse();
});

test('rejects truncated, overlong and foreign-alphabet strings', function () {
    expect(MoneroAddressCodec::isValid(substr(XMR_STANDARD, 0, 94)))->toBeFalse()
        ->and(MoneroAddressCodec::isValid(XMR_STANDARD.'A'))->toBeFalse()
        // '0' and 'l' are not in the Monero base58 alphabet.
        ->and(MoneroAddressCodec::isValid(substr_replace(XMR_STANDARD, '0', 40, 1)))->toBeFalse()
        ->and(MoneroAddressCodec::isValid(''))->toBeFalse();
});

test('rejects addresses of other chains', function () {
    expect(MoneroAddressCodec::isValid('0x2170Ed0880ac9A755fd29B2688956BD959F933F8'))->toBeFalse()
        ->and(MoneroAddressCodec::isValid('9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM'))->toBeFalse()
        ->and(MoneroAddressCodec::isValid('12ZEw5Hcv1hTb6YUQJ69y1V7uhcoDz92PH'))->toBeFalse();
});

test('integrated addresses carry the payment id and are deterministic', function () {
    $paymentId = hex2bin('cafebabe0badf00d');
    $integrated = MoneroAddressCodec::integratedAddress(XMR_STANDARD, $paymentId);

    expect($integrated)->toBe(MoneroAddressCodec::integratedAddress(XMR_STANDARD, $paymentId))
        ->and(substr(MoneroAddressCodec::decodeChecked($integrated), -8))->toBe($paymentId)
        // The spend/view keys are untouched: same wallet, different label.
        ->and(substr(MoneroAddressCodec::decodeChecked($integrated), 1, 64))
        ->toBe(substr(MoneroAddressCodec::decodeChecked(XMR_STANDARD), 1, 64));
});

test('refuses to build an integrated address from a subaddress', function () {
    // Payment ids do not combine with subaddresses — silently producing
    // garbage here would send a user's deposit nowhere.
    expect(MoneroAddressCodec::integratedAddress(XMR_SUBADDRESS, hex2bin('0011223344556677')))->toBeNull()
        ->and(MoneroAddressCodec::integratedAddress('not an address', hex2bin('0011223344556677')))->toBeNull();
});

test('rejects a payment id that is not 8 bytes', function () {
    expect(fn () => MoneroAddressCodec::integratedAddress(XMR_STANDARD, hex2bin('0011')))
        ->toThrow(InvalidArgumentException::class);
});
