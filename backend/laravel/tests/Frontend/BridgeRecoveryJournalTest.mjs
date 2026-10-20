import assert from 'node:assert/strict';
import test from 'node:test';
import {
    loadBridgeRecovery,
    markBridgeDepositRegistered,
    rememberBridgeDeposit,
} from '@/lib/bridgeRecovery';

const memory = () => {
    const items = new Map();

    return {
        getItem: (key) => items.get(key) ?? null,
        setItem: (key, value) => items.set(key, value),
    };
};

const submission = {
    direction: 'bnb_to_evm',
    token: 'USDT',
    sourceTxHash: '0xABC',
    sourceNonce: 0,
    sender: '0x5555555555555555555555555555555555555555',
    recipient: '0x6666666666666666666666666666666666666666',
    amount: '5',
    reservation: 'hold-1',
    intentSignature: '0xsigned',
};

test('a sent deposit is remembered with its recipient signature until the server answers', () => {
    const storage = memory();
    const entry = rememberBridgeDeposit(submission, true, storage);

    // Without the signature a re-registration would be held as unproven.
    assert.equal(loadBridgeRecovery(storage)[0].submission.intentSignature, '0xsigned');
    assert.equal(loadBridgeRecovery(storage)[0].requestId, null);

    markBridgeDepositRegistered(
        entry,
        { id: 12, status: 'pending', destinationTxHash: null, message: null },
        storage,
    );

    const [saved] = loadBridgeRecovery(storage);
    assert.equal(saved.requestId, 12);
    assert.equal(loadBridgeRecovery(storage).length, 1);
});

test('an entry the journal could not read back is refused rather than written', () => {
    const storage = memory();

    assert.throws(() =>
        rememberBridgeDeposit({ ...submission, sender: '' }, true, storage),
    );
    assert.deepEqual(loadBridgeRecovery(storage), []);
});
