import assert from 'node:assert/strict';
import test from 'node:test';
import { walletChain } from '@/lib/wallet';

/**
 * Reading Cyberia history out of the explorer's index.
 *
 * The direction of a row is decided here, and getting it backwards would tell
 * someone their money arrived when it left. Everything below pins that
 * decision, plus the two answers Blockscout gives that are not failures: an
 * empty history, and a transaction that reverted.
 */

const MINE = '0x9c4A7fD2E51b0aB83c6De19F4a7B2c85D0e3F714';

const OTHER = '0x4d2a91Bf7C0e5aD8b31f7Ee6c2A0947D5Ba39C1e';

const cyberia = walletChain('cyberia');

const withExplorer = async (body, run, { ok = true, status = 200 } = {}) => {
    const original = globalThis.fetch;
    let requested = null;

    globalThis.fetch = async (url) => {
        requested = String(url);

        return { ok, status, json: async () => body };
    };

    try {
        return await run(() => requested);
    } finally {
        globalThis.fetch = original;
    }
};

const transfer = (overrides) => ({
    hash: '0x7b1ec40a',
    from: OTHER,
    to: MINE,
    value: '340000000000000000000',
    timeStamp: '1785630543',
    blockNumber: '8398551',
    isError: '0',
    txreceipt_status: '1',
    ...overrides,
});

test('direction is read from our own address, in either case', async () => {
    await withExplorer(
        {
            status: '1',
            result: [
                transfer({}),
                // Blockscout lower-cases addresses; ours is checksummed.
                transfer({
                    from: MINE.toLowerCase(),
                    to: OTHER,
                    value: '120000000000000000000',
                }),
            ],
        },
        async (requested) => {
            const history = await cyberia.fetchHistory(MINE);

            assert.ok(requested().includes('action=txlist'));
            assert.equal(history[0].direction, 'in');
            assert.equal(history[0].amount, 340n * 10n ** 18n);
            assert.equal(history[0].counterparty, OTHER);
            assert.equal(history[1].direction, 'out');
            assert.equal(history[1].amount, -120n * 10n ** 18n);
            assert.equal(history[1].counterparty, OTHER);
        },
    );
});

test('a reverted transaction is a row, not an omission', async () => {
    await withExplorer(
        {
            status: '1',
            result: [transfer({ isError: '1', txreceipt_status: '0' })],
        },
        async () => {
            const [tx] = await cyberia.fetchHistory(MINE);

            assert.equal(tx.status, 'failed');
            assert.equal(tx.meta, 'block 8398551');
            assert.equal(tx.timestamp, 1785630543);
        },
    );
});

test('contract calls carrying no value stay out of a transfer list', async () => {
    await withExplorer(
        { status: '1', result: [transfer({ value: '0' })] },
        async () => {
            assert.deepEqual(await cyberia.fetchHistory(MINE), []);
        },
    );
});

test('"no transactions found" is an empty history, not an error', async () => {
    await withExplorer(
        { status: '0', message: 'No transactions found', result: [] },
        async () => {
            assert.deepEqual(await cyberia.fetchHistory(MINE), []);
        },
    );
});

test('an explorer outage is reported instead of being read as empty', async () => {
    await withExplorer(
        {},
        async () => {
            await assert.rejects(() => cyberia.fetchHistory(MINE), /502/);
        },
        { ok: false, status: 502 },
    );
});
