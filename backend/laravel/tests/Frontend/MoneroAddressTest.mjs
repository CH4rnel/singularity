import assert from 'node:assert/strict';
import test from 'node:test';
import {
    isValidMoneroAddress,
    moneroAddressKind,
} from '../../resources/js/lib/monero.ts';

// Public Monero project donation address (standard) and CCS subaddress. The
// same vectors back tests/Unit/MoneroAddressCodecTest.php, so the browser and
// the server agree on what a Monero address is.
const STANDARD =
    '44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A';
const SUBADDRESS =
    '888tNkZrPN6JsEgekjMnABU4TBzc2Dt29EPAvkRxbANsAnjyPbb3iQ1YBRk1UXcdRsiKc9dhwMVgN5S9cQUiyoogDavup3H';
// Integrated address built by MoneroAddressCodec::integratedAddress() from the
// address above with payment id 0011223344556677.
const INTEGRATED =
    '4DrvGduF3ynBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVPkNdRe7Ubq7EY9b9Ez';

test('recognises the three mainnet address kinds', () => {
    assert.equal(moneroAddressKind(STANDARD), 'standard');
    assert.equal(moneroAddressKind(SUBADDRESS), 'subaddress');
    assert.equal(moneroAddressKind(INTEGRATED), 'integrated');
    assert.equal(moneroAddressKind(`  ${STANDARD}  `), 'standard');
});

test('rejects a single mistyped character via the checksum', () => {
    const typo = `${STANDARD.slice(0, 40)}${STANDARD[40] === 'A' ? 'B' : 'A'}${STANDARD.slice(41)}`;

    assert.notEqual(typo, STANDARD);
    assert.equal(isValidMoneroAddress(typo), false);
});

test('rejects wrong lengths, foreign alphabets and other chains', () => {
    for (const bad of [
        '',
        STANDARD.slice(0, 94),
        `${STANDARD}A`,
        `${STANDARD.slice(0, 40)}0${STANDARD.slice(41)}`, // '0' is not base58
        '0x2170Ed0880ac9A755fd29B2688956BD959F933F8',
        '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
        '12ZEw5Hcv1hTb6YUQJ69y1V7uhcoDz92PH',
    ]) {
        assert.equal(isValidMoneroAddress(bad), false, bad);
    }
});
