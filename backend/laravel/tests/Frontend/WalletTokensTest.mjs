import assert from 'node:assert/strict';
import test from 'node:test';
import { blockscoutTokens, mergeTokens, sameToken } from '@/lib/wallet/erc20';
import { withToken, withoutToken } from '@/lib/wallet/tokenList';

/**
 * ERC20 support turns on two numbers a wallet cannot afford to get wrong: the
 * decimals a balance is rendered with, and which balance pays the fee. Both are
 * pinned here, because a six-decimal token displayed as eighteen is off by a
 * factor of a trillion and a token amount signed against the chain's decimals
 * moves a millionth of what was typed.
 */

const USDC = '0xdc25597B19799010047F17e9591EFE08EFd40077';
const USDT = '0x94845aF24a3E431593A2b941b2b31836dE45185D';

/** A Blockscout `tokenlist` response, in the shape the live explorer returns. */
const respondWith = (body, ok = true) => {
    const calls = [];

    globalThis.fetch = async (url) => {
        calls.push(String(url));

        return {
            ok,
            status: ok ? 200 : 502,
            json: async () => body,
        };
    };

    return calls;
};

test('a token carries the decimals its own contract declares', async () => {
    respondWith({
        message: 'OK',
        status: '1',
        result: [
            {
                balance: '87117233',
                contractAddress: USDC.toLowerCase(),
                decimals: '6',
                name: 'USD Coin',
                symbol: 'USDC',
                type: 'ERC-20',
            },
        ],
    });

    const [token] = await blockscoutTokens('https://explorer/api', '0xabc');

    // Six, not eighteen. Cyberia's USDC really is six-decimal, which is the
    // whole reason the index is asked instead of a hard-coded list.
    assert.equal(token.decimals, 6);
    assert.equal(token.balance, 87117233n);
    assert.equal(
        token.address,
        USDC,
        'the address is checksummed, not left lowercase as the API sends it',
    );
});

test('"no tokens found" is an empty wallet, not a failure', async () => {
    respondWith({ message: 'No tokens found', result: [], status: '0' });

    assert.deepEqual(
        await blockscoutTokens('https://explorer/api', '0xabc'),
        [],
    );
});

test('an explorer outage is raised, never read as holding nothing', async () => {
    respondWith('gateway timeout', false);

    await assert.rejects(() =>
        blockscoutTokens('https://explorer/api', '0xabc'),
    );
});

test('a token whose decimals cannot be read is dropped, never guessed at', async () => {
    respondWith({
        status: '1',
        result: [
            { balance: '1', contractAddress: USDC, decimals: '', symbol: 'A' },
            { balance: '1', contractAddress: 'not-an-address', decimals: '18' },
            {
                balance: '1',
                contractAddress: USDT,
                decimals: '6',
                symbol: 'USDT',
                type: 'ERC-20',
            },
            {
                balance: '1',
                contractAddress: USDC,
                decimals: '0',
                symbol: 'NFT',
                type: 'ERC-721',
            },
        ],
    });

    const tokens = await blockscoutTokens('https://explorer/api', '0xabc');

    assert.deepEqual(
        tokens.map((token) => token.symbol),
        ['USDT'],
        'unreadable decimals, bad addresses and NFTs are all not spendable here',
    );
});

test('the same contract written two ways is one token', () => {
    assert.ok(sameToken(USDC, USDC.toLowerCase()));
    assert.ok(!sameToken(USDC, USDT));
});

test('a hand-added token survives at zero; an indexed one at zero does not', () => {
    const indexed = [
        { address: USDC, symbol: 'USDC', name: '', decimals: 6, balance: 0n },
        { address: USDT, symbol: 'USDT', name: '', decimals: 6, balance: 5n },
    ];
    const manual = [
        {
            address: USDC.toLowerCase(),
            symbol: 'USDC',
            name: '',
            decimals: 6,
            balance: 0n,
            manual: true,
        },
    ];

    const merged = mergeTokens(indexed, manual);

    assert.deepEqual(
        merged.map((token) => token.symbol),
        ['USDC', 'USDT'],
    );
    assert.equal(
        merged.filter((token) => sameToken(token.address, USDC)).length,
        1,
        'the index and the user naming the same contract is one row, not two',
    );
    assert.equal(merged[0].manual, true);
});

test('an indexed token at zero is noise the explorer remembers', () => {
    const merged = mergeTokens(
        [{ address: USDC, symbol: 'USDC', name: '', decimals: 6, balance: 0n }],
        [],
    );

    assert.deepEqual(merged, []);
});

test('tracking a token twice tracks it once, and untracking is exact', () => {
    const once = withToken([], 'cyberia', USDC);
    const twice = withToken(once, 'cyberia', USDC.toLowerCase());

    assert.equal(twice.length, 1);
    assert.equal(
        twice[0].address,
        USDC,
        'stored checksummed, compared loosely',
    );

    // The same contract on another chain is a different token: an address is
    // only unique within the chain it was deployed on.
    const both = withToken(twice, 'robinhood', USDC);
    assert.equal(both.length, 2);

    assert.deepEqual(withoutToken(both, 'cyberia', USDC.toLowerCase()), [
        { chain: 'robinhood', address: USDC },
    ]);
});
