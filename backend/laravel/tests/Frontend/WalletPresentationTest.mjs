import assert from 'node:assert/strict';
import test from 'node:test';
import { walletChain } from '@/lib/wallet';
import {
    formatUsd,
    formatUsdPrice,
    shortAddress,
    signedAmount,
    usdValue,
} from '@/lib/wallet/format';
import { qrMatrix, qrSvgPath } from '@/lib/wallet/qr';

/**
 * What the wallet page renders on top of the chain adapters: amounts, fiat and
 * the receive QR. A rounding slip here is a payment for the wrong number and a
 * QR slip is a payment to nobody, so both are pinned to known values rather
 * than eyeballed in the browser.
 */

const CYBERIA_ADDRESS = '0x9c4A7fD2E51b0aB83c6De19F4a7B2c85D0e3F714';

test('amounts are formatted from the smallest unit, never through a float', () => {
    // 1 wei under 1 CYBER: a double would round this to exactly 1.
    assert.equal(signedAmount(999999999999999999n, 18), '+0.9999');
    assert.equal(signedAmount(-120n * 10n ** 18n, 18), '−120.0000');
    assert.equal(signedAmount(0n, 18), '+0.0000');
    // Lamports and piconero keep their own precision.
    assert.equal(signedAmount(8_500_000_000n, 9), '+8.5000');
    assert.equal(signedAmount(-240_000_000_000n, 12), '−0.2400');
});

test('an address keeps both ends so it can be checked against paper', () => {
    const short = shortAddress(CYBERIA_ADDRESS);

    assert.ok(CYBERIA_ADDRESS.startsWith(short.split('…')[0]));
    assert.ok(CYBERIA_ADDRESS.endsWith(short.split('…')[1]));
    // Nothing short enough to read whole is ever abbreviated.
    assert.equal(shortAddress('0x9c4A7fD2'), '0x9c4A7fD2');
});

test('a missing price is unknown, not zero', () => {
    assert.equal(usdValue(10n ** 18n, 18, null), null);
    assert.equal(usdValue(null, 18, 7), null);
    assert.equal(usdValue(2n * 10n ** 18n, 18, 7), 14);
    assert.equal(formatUsd(null, 'en'), '—');
    assert.equal(formatUsd(0, 'en'), '$0.00');
});

test('a coin worth a fraction of a cent is not rendered as an empty wallet', () => {
    // CYBER trades in the tens of microdollars. Two fixed decimals printed
    // every Cyberia balance in this wallet as "$0.00" — a claim about the
    // balance rather than about the price.
    assert.equal(formatUsd(0.0000221, 'en'), '$0.0000221');
    assert.equal(formatUsd(0.0284, 'en'), '$0.03');
    assert.equal(formatUsd(-0.000004567, 'en'), '-$0.00000457');
    // Zero stays two decimals: that one really is a fact about the balance.
    assert.equal(formatUsd(0, 'en'), '$0.00');
    assert.equal(formatUsd(1284.5, 'en'), '$1,284.50');
});

test('a rate keeps enough digits to be compared between screens', () => {
    assert.equal(formatUsdPrice(null, 'en'), '—');
    assert.equal(formatUsdPrice(0.0000221, 'en'), '$0.0000221');
    assert.equal(formatUsdPrice(0.000123456, 'en'), '$0.0001235');
    assert.equal(formatUsdPrice(1881.44, 'en'), '$1,881.44');
    assert.equal(formatUsdPrice(1.00042, 'en'), '$1.0004');
});

/** A finder pattern is a 7×7 dark ring, a light ring, and a 3×3 dark core. */
const isFinderPattern = (matrix, originX, originY) => {
    for (let y = 0; y < 7; y++) {
        for (let x = 0; x < 7; x++) {
            const ring = Math.max(Math.abs(x - 3), Math.abs(y - 3));
            const dark = ring === 3 || ring <= 1;

            if (
                matrix.modules[(originY + y) * matrix.size + (originX + x)] !==
                dark
            ) {
                return false;
            }
        }
    }

    return true;
};

test('a receive QR is a well-formed symbol for the address it is drawn for', () => {
    for (const address of [
        CYBERIA_ADDRESS,
        '7xKXtg2CW3iBc9pQmS4vRz1nHf6YuJd8LpAe5TbNqWzM',
        // A 95-character Monero standard address, the longest case.
        'A'.repeat(95),
    ]) {
        const matrix = qrMatrix(address);
        const last = matrix.size - 7;

        assert.equal(matrix.modules.length, matrix.size * matrix.size);
        assert.ok(isFinderPattern(matrix, 0, 0), 'top-left finder');
        assert.ok(isFinderPattern(matrix, last, 0), 'top-right finder');
        assert.ok(isFinderPattern(matrix, 0, last), 'bottom-left finder');

        // Every dark module becomes exactly one subpath.
        const path = qrSvgPath(matrix);
        const dark = matrix.modules.filter(Boolean).length;

        assert.equal(path.match(/M/g).length, dark);
    }
});

test('the QR grows with the data instead of truncating it', () => {
    assert.ok(qrMatrix('A'.repeat(95)).size > qrMatrix('0x00').size);
});

test('the same address always draws the same symbol', () => {
    assert.deepEqual(qrMatrix(CYBERIA_ADDRESS), qrMatrix(CYBERIA_ADDRESS));
    assert.notDeepEqual(
        qrMatrix(CYBERIA_ADDRESS).modules,
        qrMatrix(CYBERIA_ADDRESS.replace(/4$/, '5')).modules,
    );
});

test('each chain reports the decimals its amounts are formatted with', () => {
    assert.equal(walletChain('cyberia').decimals, 18);
    assert.equal(walletChain('solana').decimals, 9);
    assert.equal(walletChain('monero').decimals, 12);
});
