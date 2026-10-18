import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    addressAt,
    describeMessage,
    describeSignature,
    describeTransaction,
    describeTypedData,
    selectorOf,
    visibleText,
    wordsOf,
} from '../src/shared/tx.js';

const CYBERIA = { id: 49406, symbol: 'CYBER', decimals: 18 };

const word = (value) => value.replace('0x', '').padStart(64, '0');

test('a plain transfer is described by its amount', () => {
    const described = describeTransaction(
        { to: '0x4d2a91Bf7C0e5aD8b31f7Ee6c2A0947D5Ba39C1e', value: '0x16345785d8a0000' },
        CYBERIA,
    );

    assert.equal(described.subject, 'YOU SEND');
    assert.equal(described.headline, '0.1 CYBER');
    assert.equal(described.isContract, false);
    assert.equal(described.warning, null);
    // Whole, in groups of four: a look-alike shares both ends of a truncation.
    assert.deepEqual(described.rows[0], { key: 'TO', value: '0x4d2a 91Bf 7C0e 5aD8 b31f 7Ee6 c2A0 947D 5Ba3 9C1e' });
});

test('an approval with no ceiling is named, not rendered as a big number', () => {
    const data = `0x095ea7b3${word('0x5B1e0Bd45178aE0d6C1b37F9042e5A8Db02A7c90')}${'f'.repeat(64)}`;
    const described = describeTransaction({ to: '0x2B67dA9105fE4c83b0aE71c9D4260fA35e8b7c12', data }, CYBERIA);

    assert.equal(described.rows.find((row) => row.key === 'FUNCTION').value, 'approve');
    assert.equal(described.rows.find((row) => row.key === 'AMOUNT').value, 'UNLIMITED');
    assert.match(described.warning, /no limit/);
});

test('an approval for an exact amount carries no warning', () => {
    const data = `0x095ea7b3${word('0x5B1e0Bd45178aE0d6C1b37F9042e5A8Db02A7c90')}${word('0x64')}`;
    const described = describeTransaction({ to: '0x2B67dA', data }, CYBERIA);

    assert.equal(described.warning, null);
    assert.equal(described.rows.find((row) => row.key === 'AMOUNT').value, '100 base units');
});

test('an unknown call says it is unknown instead of guessing', () => {
    const described = describeTransaction({ to: '0x2B67dA', data: '0xdeadbeef' }, CYBERIA);

    assert.equal(described.rows[0].value, 'UNKNOWN · 0xdeadbeef');
    assert.equal(described.isContract, true);
});

test('calldata is read positionally, and refuses to invent words', () => {
    assert.equal(selectorOf('0xA9059CBB0000'), '0xa9059cbb');
    assert.equal(selectorOf('0x'), '');
    assert.equal(wordsOf(`0xa9059cbb${word('0x01')}`).length, 1);
    assert.equal(
        addressAt(`0x${word('0x4d2a91bf7c0e5ad8b31f7ee6c2a0947d5ba39c1e')}`),
        '0x4d2a91bf7c0e5ad8b31f7ee6c2a0947d5ba39c1e',
    );
    assert.equal(addressAt('0x1234'), null);
});

test('a message is shown as text when it is text, and as hex when it is not', () => {
    assert.equal(describeMessage('0x48656c6c6f'), 'Hello');
    assert.equal(describeMessage('sign in to cyberia'), 'sign in to cyberia');
    assert.equal(describeMessage('0xfffefd'), '0xfffefd');
});

test('an approval leads with what it permits, not with "0 CYBER"', () => {
    const data = `0x095ea7b3${word('0x5B1e0Bd45178aE0d6C1b37F9042e5A8Db02A7c90')}${'f'.repeat(64)}`;
    const described = describeTransaction(
        { to: '0x2B67dA9105fE4c83b0aE71c9D4260fA35e8b7c12', data },
        CYBERIA,
        { symbol: 'USDC', decimals: 6 },
    );

    assert.equal(described.title, 'SPENDING PERMISSION');
    assert.equal(described.headline, 'UNLIMITED USDC');
    assert.match(described.consequence, /all your USDC/);
    assert.equal(
        described.rows.find((row) => row.key === 'SPENDER').value,
        '0x5b1e 0bd4 5178 ae0d 6c1b 37f9 042e 5a8d b02a 7c90',
    );
});

test('a limited approval is in token units once the token is known', () => {
    const data = `0x095ea7b3${word('0x5B1e0Bd45178aE0d6C1b37F9042e5A8Db02A7c90')}${word('0x5f5e100')}`;
    const described = describeTransaction({ to: '0x2B67dA', data }, CYBERIA, { symbol: 'USDC', decimals: 6 });

    assert.equal(described.headline, '100 USDC');
    assert.equal(described.warning, null);
});

test('a collection-wide approval names its operator', () => {
    const data = `0xa22cb465${word('0x7a2b53b0d5ce6ff8c6bf09b2a4f5e8e4e2c1a3d9')}${word('0x01')}`;
    const described = describeTransaction({ to: '0xA827D058a5738EaC28DB1d658D3e8cFb82CBb66B', data }, CYBERIA);

    assert.equal(described.title, 'COLLECTION-WIDE PERMISSION');
    assert.equal(described.headline, 'EVERY ITEM');
    assert.equal(
        described.rows.find((row) => row.key === 'OPERATOR').value,
        '0x7a2b 53b0 d5ce 6ff8 c6bf 09b2 a4f5 e8e4 e2c1 a3d9',
    );
});

test('a Permit is read as a spending permission with its token, spender and expiry', () => {
    const now = Date.parse('2026-10-18T00:00:00Z');
    const described = describeTypedData(
        {
            primaryType: 'Permit',
            domain: { chainId: 49406, verifyingContract: '0xdc25597B19799010047F17e9591EFE08EFd40077' },
            message: { spender: '0x7a2b53b0d5ce6ff8c6bf09b2a4f5e8e4e2c1a3d9', value: `0x${'f'.repeat(64)}`, deadline: 4102444800 },
        },
        49406,
        now,
    );

    assert.equal(described.title, 'OFF-CHAIN SPENDING PERMISSION');
    assert.equal(described.rows.find((row) => row.key === 'AMOUNT').value, 'UNLIMITED');
    assert.match(described.rows.find((row) => row.key === 'EXPIRES').value, /^2100-01-01 · in /);
    assert.match(described.rows.find((row) => row.key === 'TOKEN').value, /^0xdc25 597B/);
    assert.ok(described.warning);
});

test('a typed signature for another chain says so', () => {
    const described = describeTypedData({ primaryType: 'Mail', domain: { chainId: 1, name: 'x' }, message: {} }, 49406);

    assert.equal(described.mismatch, true);
    assert.match(described.warning, /chain 1, but the wallet is on 49406/);
});

test('a sign-in for another site than the one asking is flagged', () => {
    const text = 'cyberia.church wants you to sign in with your Ethereum account:\n0x25F999438A0CD3b661D7f4b572Ab639c4Bb3a9F2\n';
    const flagged = describeSignature(text, 'http://127.0.0.1:8793');
    const same = describeSignature(text, 'https://cyberia.church');

    assert.equal(flagged.title, 'SIGN-IN REQUEST');
    assert.match(flagged.warning, /different site/);
    assert.equal(same.warning, null);
});

test('a 32-byte hash is a blind signature, and invisible characters are shown', () => {
    assert.equal(describeSignature(`0x${'5c'.repeat(32)}`, 'https://a.example').title, 'BLIND SIGNATURE');
    assert.equal(visibleText('pay\u202Eto'), 'pay\\u{202e}to');
});
