import assert from 'node:assert/strict';
import test from 'node:test';
import { accountBackupPhrase } from '@/lib/wallet/accounts';

test('backup follows the selected account and never substitutes the vault phrase', () => {
    const seed = { id: 'seed-0', kind: 'seed', index: 0, label: null };
    const imported = {
        id: 'imported',
        kind: 'phrase',
        phrase: 'imported backup',
        index: 0,
        label: null,
    };
    assert.equal(accountBackupPhrase('vault backup', seed), 'vault backup');
    assert.equal(
        accountBackupPhrase('vault backup', imported),
        'imported backup',
    );
    assert.equal(
        accountBackupPhrase('vault backup', { ...seed, index: 1 }),
        'vault backup',
    );
    for (const kind of ['key', 'watch']) {
        assert.throws(
            () =>
                accountBackupPhrase('vault backup', {
                    id: kind,
                    kind,
                    label: null,
                }),
            /no seed phrase/,
        );
    }
    assert.throws(
        () => accountBackupPhrase('vault backup', null),
        /no seed phrase/,
    );
});
