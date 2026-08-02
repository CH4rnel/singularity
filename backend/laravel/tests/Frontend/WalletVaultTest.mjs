import assert from 'node:assert/strict';
import test from 'node:test';
import {
    createMnemonic,
    forgetVault,
    hasVault,
    isValidMnemonic,
    normalizeMnemonic,
    openVault,
    readVault,
    saveVault,
} from '@/lib/wallet';

/**
 * The vault is the only place the seed phrase is ever at rest. What matters is
 * that it is unreadable without the password and that nothing else about the
 * wallet leaks into storage — everything the app persists is checked here.
 */

const storage = () => {
    const store = new Map();

    return {
        getItem: (key) => store.get(key) ?? null,
        setItem: (key, value) => void store.set(key, value),
        removeItem: (key) => void store.delete(key),
        dump: () =>
            [...store.entries()]
                .map(([key, value]) => `${key}${value}`)
                .join(''),
    };
};

test('a sealed phrase comes back with the right password only', async () => {
    const disk = storage();
    const phrase = createMnemonic();

    assert.equal(hasVault(disk), false);
    await saveVault(phrase, 'correct horse battery', disk);

    assert.equal(hasVault(disk), true);
    assert.equal(await openVault('correct horse battery', disk), phrase);
    await assert.rejects(
        () => openVault('correct horse batteries', disk),
        /Wrong wallet password/,
    );
});

test('nothing readable about the seed reaches storage', async () => {
    const disk = storage();
    const phrase = createMnemonic(24);

    await saveVault(phrase, 'a very long password', disk);

    const dump = disk.dump();

    for (const word of phrase.split(' ')) {
        assert.equal(dump.includes(` ${word} `), false);
    }

    assert.equal(dump.includes(phrase), false);

    const record = readVault(disk);

    assert.equal(record.version, 1);
    assert.equal(record.kdf, 'PBKDF2-SHA-256');
    assert.ok(record.iterations >= 200_000);
    // Salt and IV are unique per save, so two vaults never share a key.
    const other = storage();

    await saveVault(phrase, 'a very long password', other);
    assert.notEqual(readVault(other).salt, record.salt);
    assert.notEqual(readVault(other).ciphertext, record.ciphertext);
});

test('a weak password or a bad phrase is refused before anything is stored', async () => {
    const disk = storage();

    await assert.rejects(
        () => saveVault(createMnemonic(), 'short', disk),
        /at least 8 characters/,
    );
    await assert.rejects(
        () => saveVault('not a real seed phrase at all', 'long enough', disk),
        /valid BIP-39/,
    );
    assert.equal(hasVault(disk), false);
});

test('phrases are normalised the way BIP-39 expects', () => {
    const phrase = createMnemonic();

    assert.equal(normalizeMnemonic(`  ${phrase.toUpperCase()}  `), phrase);
    assert.equal(isValidMnemonic(`  ${phrase.toUpperCase()}\n`), true);
    assert.equal(isValidMnemonic('abandon abandon abandon'), false);
});

test('forgetting a wallet leaves nothing behind', async () => {
    const disk = storage();

    await saveVault(createMnemonic(), 'a very long password', disk);
    forgetVault(disk);

    assert.equal(hasVault(disk), false);
    assert.equal(readVault(disk), null);
    assert.equal(disk.dump(), '');
});
