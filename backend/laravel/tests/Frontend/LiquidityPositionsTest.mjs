import assert from 'node:assert/strict';
import test from 'node:test';
import { scanLiquidityBalances } from '../../resources/js/lib/liquidityPositions.ts';

test('finds LP balances across every pair without hiding failed reads', async () => {
    const balances = new Map([
        ['0xempty', 0n],
        ['0xowned', 42n],
    ]);

    const result = await scanLiquidityBalances(
        ['0xempty', '0xunreadable', '0xowned'],
        async (pairAddress) => {
            if (pairAddress === '0xunreadable') {
                throw new Error('RPC request failed');
            }

            return balances.get(pairAddress) ?? 0n;
        },
    );

    assert.deepEqual(result, {
        ownedPairs: [{ pairAddress: '0xowned', lpBalance: 42n }],
        failedReads: 1,
    });
});
