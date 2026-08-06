import assert from 'node:assert/strict';
import test from 'node:test';
import { fetchCyberBalance, formatCyberBalance } from '@/lib/cyberiaBalance';

test('formats CYBER balances without losing wei precision', () => {
    assert.equal(formatCyberBalance(0n), '0');
    assert.equal(formatCyberBalance(1n), '<0.0001');
    assert.equal(formatCyberBalance(1_234_567_890_000_000_000n), '1.2345');
    assert.equal(
        formatCyberBalance(12_345_678_000_000_000_000_000n),
        '12,345.678',
    );
});

test('reads the native balance through the Cyberia RPC proxy', async () => {
    const requests = [];
    const request = async (url, options) => {
        requests.push({ url, options });

        return new Response(
            JSON.stringify({ jsonrpc: '2.0', id: 1, result: '0x2a' }),
            { status: 200 },
        );
    };

    assert.equal(await fetchCyberBalance('0xabc', request), 42n);
    assert.equal(requests[0].url, '/api/rpc/cyberia');

    const payload = JSON.parse(requests[0].options.body);

    assert.equal(payload.method, 'eth_getBalance');
    assert.deepEqual(payload.params, ['0xabc', 'latest']);
});

test('rejects an unreadable RPC balance instead of presenting it as zero', async () => {
    const request = async () =>
        new Response(JSON.stringify({ result: null }), { status: 200 });

    await assert.rejects(
        fetchCyberBalance('0xabc', request),
        /Invalid Cyberia balance/,
    );
});
