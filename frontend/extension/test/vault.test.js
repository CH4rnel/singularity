import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createVault, exportKey, importKey, openVault, openWithKey, sealWith } from '../src/background/vault.js';

const DOCUMENT = {
    // The published BIP-39 vector, as everywhere else in this repository.
    phrase:
        'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
    accounts: [{ index: 0, name: 'Main account' }],
    activeIndex: 0,
};

test('a sealed vault opens with its password and nothing else', async () => {
    const { record } = await createVault(DOCUMENT, 'correct horse battery');

    assert.equal(record.version, 2);
    assert.equal(record.kdf, 'PBKDF2-SHA-256');
    assert.equal(record.iterations, 310_000);

    const opened = await openVault(record, 'correct horse battery');
    assert.deepEqual(opened.document, DOCUMENT);

    await assert.rejects(() => openVault(record, 'correct horse batteryy'), /wrong password/);
});

test('nothing recognisable survives into the stored record', async () => {
    const { record } = await createVault(DOCUMENT, 'correct horse battery');
    const stored = JSON.stringify(record);

    assert.ok(!stored.includes('abandon'), 'a phrase word must not appear in the ciphertext');
    assert.ok(!stored.includes('Main account'));
});

test('two vaults over the same document share no bytes', async () => {
    const first = await createVault(DOCUMENT, 'same password');
    const second = await createVault(DOCUMENT, 'same password');

    assert.notEqual(first.record.salt, second.record.salt);
    assert.notEqual(first.record.iv, second.record.iv);
    assert.notEqual(first.record.data, second.record.data);
});

test('an unlocked session re-seals without seeing the password again', async () => {
    const { record } = await createVault(DOCUMENT, 'correct horse battery');
    const opened = await openVault(record, 'correct horse battery');

    const next = { ...DOCUMENT, accounts: [...DOCUMENT.accounts, { index: 1, name: 'Airdrops' }] };
    const resealed = await opened.reseal(next);

    assert.deepEqual((await openVault(resealed, 'correct horse battery')).document, next);
});

test('the session key round-trips through storage and still opens the vault', async () => {
    const { record, key } = await createVault(DOCUMENT, 'correct horse battery');
    const restored = await importKey(await exportKey(key));

    assert.deepEqual(await openWithKey(record, restored), DOCUMENT);

    const resealed = await sealWith({ ...DOCUMENT, activeIndex: 0 }, restored, record);
    assert.deepEqual(await openWithKey(resealed, restored), DOCUMENT);
});
