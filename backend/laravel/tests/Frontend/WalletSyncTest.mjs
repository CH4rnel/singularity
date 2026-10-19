import assert from 'node:assert/strict';
import test from 'node:test';
import {
    installVault,
    openFromSync,
    openUnprotectedVault,
    sealForSync,
} from '@/lib/wallet/vault';

/**
 * Sync: what leaves the device is ciphertext that only the sync password
 * opens, and what comes back becomes this device's wallet unchanged.
 */

const PHRASE = 'test test test test test test test test test test test junk';

const contents = {
    phrase: PHRASE,
    accounts: [{ id: 'seed-0', kind: 'seed', label: 'Main', index: 0 }],
    activeId: 'seed-0',
    backedUp: false,
};

const memory = () => {
    const store = new Map();

    return {
        getItem: (key) => store.get(key) ?? null,
        setItem: (key, value) => void store.set(key, value),
        removeItem: (key) => void store.delete(key),
    };
};

test('the sealed copy carries no phrase and opens with its password', async () => {
    const record = await sealForSync(contents, 'correct horse');

    assert.ok(!JSON.stringify(record).includes('junk'));
    assert.equal(record.kdf, 'PBKDF2-SHA-256');

    const opened = await openFromSync(record, 'correct horse');

    assert.equal(opened.phrase, PHRASE);
    assert.equal(opened.activeId, 'seed-0');
    assert.equal(opened.backedUp, false);
});

test('a wrong password opens nothing', async () => {
    const record = await sealForSync(contents, 'correct horse');

    await assert.rejects(openFromSync(record, 'wrong horse'));
});

test('a short sync password is refused before anything is sealed', async () => {
    await assert.rejects(sealForSync(contents, 'short'));
});

test('a restored wallet becomes this device’s open vault', () => {
    const storage = memory();

    installVault(contents, storage);

    assert.equal(openUnprotectedVault(storage).phrase, PHRASE);
});
