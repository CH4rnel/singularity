import assert from 'node:assert/strict';
import test from 'node:test';
import { holdings, matchesHolding } from '@/lib/wallet/holdings';

/**
 * What the simple home lists and what Send picks from.
 *
 * Pinned: a priced or registry-known token is listed, a stranger with no price
 * is hidden and counted (or kept, tagged, when asked), a bridged token keeps
 * its tag, and the list runs from the most valuable down.
 */

const BRIDGED_USDC = '0xdc25597B19799010047F17e9591EFE08EFd40077';
const STRANGER = '0x7777777777777777777777777777777777777777';
const PRICED_STRANGER = '0x8888888888888888888888888888888888888888';

const token = (address, symbol, balance, decimals = 18) => ({
    address,
    symbol,
    name: symbol,
    decimals,
    balance,
});

const input = {
    accounts: [
        { chain: 'cyberia', label: 'Cyberia', symbol: 'CYBER', decimals: 18 },
    ],
    balances: { cyberia: 2n * 10n ** 18n },
    tokens: {
        cyberia: [
            token(BRIDGED_USDC, 'USDC', 150n * 10n ** 6n, 6),
            token(STRANGER, 'USDT', 10n ** 18n),
            token(PRICED_STRANGER, 'MEME', 10n ** 18n),
            token('0x9999999999999999999999999999999999999999', 'ZERO', 0n),
        ],
    },
    prices: { cyberia: 0.05 },
    tokenPrices: {
        cyberia: {
            [BRIDGED_USDC.toLowerCase()]: 1,
            [PRICED_STRANGER.toLowerCase()]: 3,
        },
    },
};

test('a stranger with no price is hidden and counted; zero balances are not rows', () => {
    const { rows, hidden } = holdings(input);

    assert.deepEqual(
        rows.map((row) => row.symbol),
        ['USDC', 'MEME', 'CYBER'],
    );
    assert.equal(hidden, 1);
});

test('a bridged token keeps its tag; a priced stranger is still unverified', () => {
    const { rows } = holdings(input);
    const tagOf = (symbol) => rows.find((row) => row.symbol === symbol).tag;

    assert.equal(tagOf('USDC'), 'bridged');
    assert.equal(tagOf('MEME'), 'unverified');
    assert.equal(tagOf('CYBER'), null);
});

test('asked for, strangers come back last and tagged', () => {
    const { rows, hidden } = holdings(input, { unverified: true });

    assert.equal(hidden, 0);
    assert.equal(rows.at(-1).symbol, 'USDT');
    assert.equal(rows.at(-1).tag, 'unverified');
});

test('an empty coin is kept only when asked for, and never when unread', () => {
    const empty = { ...input, balances: { cyberia: 0n }, tokens: {} };
    const unread = { ...input, balances: { cyberia: null }, tokens: {} };

    assert.equal(holdings(empty).rows.length, 0);
    assert.equal(holdings(empty, { emptyCoins: true }).rows[0].amount, '0');
    assert.equal(holdings(unread, { emptyCoins: true }).rows.length, 0);
});

test('search matches ticker, name, network and contract', () => {
    const [usdc] = holdings(input).rows;

    assert.ok(matchesHolding(usdc, 'usd'));
    assert.ok(matchesHolding(usdc, 'cyberia'));
    assert.ok(matchesHolding(usdc, '0xdc2559'));
    assert.ok(!matchesHolding(usdc, 'sol'));
});
