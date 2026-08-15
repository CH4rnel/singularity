import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    formatFiat,
    formatUnits,
    initials,
    parseUnits,
    shortAddress,
    toDecimal,
} from '../src/shared/format.js';

test('balances are truncated, never rounded up', () => {
    // 1.99999 CYBER must not read as 2.0000 next to a Send button.
    assert.equal(formatUnits(1_999_990_000_000_000_000n, 18, 4), '1.9999');
    assert.equal(formatUnits('0x0', 18), '0');
    assert.equal(formatUnits(1_000_000n, 6), '1');
    assert.equal(formatUnits(12_480_500_000_000_000_000_000n, 18, 4), '12,480.5');
});

test('decimals come from the token, never from a guess', () => {
    // Cyberia's USDC is 6, and reading it as 18 shows a millionth of the truth.
    assert.equal(formatUnits(2_500_000n, 6, 2), '2.5');
    assert.equal(formatUnits(2_500_000n, 18, 2), '0');
});

test('an amount typed by hand becomes base units without floating point', () => {
    assert.equal(parseUnits('0.1', 18), 100_000_000_000_000_000n);
    assert.equal(parseUnits('12480', 18), 12_480_000_000_000_000_000_000n);
    assert.equal(parseUnits('1.234567', 6), 1_234_567n);
    assert.equal(parseUnits('1.2345678', 6), null, 'more precision than the token has');
    assert.equal(parseUnits('', 18), null);
    assert.equal(parseUnits('1e18', 18), null);
    assert.equal(parseUnits('-1', 18), null);
});

test('a price that could not be read is a dash, never zero', () => {
    assert.equal(formatFiat(null), '—');
    assert.equal(formatFiat(undefined), '—');
    assert.equal(formatFiat(Number.NaN), '—');
    assert.equal(formatFiat(0), '$0.00');
    assert.equal(formatFiat(18_402.664), '$18,402.66');
});

test('an address is shown with both ends', () => {
    assert.equal(shortAddress('0x9c4A0Bd45178aE0d6C1b37F9042e5A8Db02F714a'), '0x9c4A…714a');
    assert.equal(shortAddress('0x9c4A'), '0x9c4A');
    assert.equal(initials('cyber'), 'CY');
});

test('base units convert to a number only for multiplying by a price', () => {
    assert.equal(toDecimal(1_500_000_000_000_000_000n, 18), 1.5);
    assert.equal(toDecimal(2_500_000n, 6), 2.5);
});
