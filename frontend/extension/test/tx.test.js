import assert from 'node:assert/strict';
import { test } from 'node:test';
import { addressAt, describeMessage, describeTransaction, selectorOf, wordsOf } from '../src/shared/tx.js';

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
    assert.deepEqual(described.rows[0], { key: 'TO', value: '0x4d2a91…a39C1e' });
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
