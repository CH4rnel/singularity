import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import test from 'node:test';
import { Interface } from 'ethers';
import { CYBERIA_CHAIN_ID } from '@/lib/evmChains';
import {
    canAddLiquidity,
    mintedLp,
    pairedAmount,
    poolShareAfter,
    quoteAddLiquidity,
    sqrtBigInt,
    withdrawalLp,
    wrappedSide,
} from '@/lib/wallet/liquidity';
import { swapChainFor } from '@/lib/wallet/swap';

/**
 * The arithmetic behind making a pool position.
 *
 * All of it is the pair contract's own, reimplemented in a browser so that a
 * person can read what they are about to sign — which is exactly why it is
 * pinned. A swap that quotes badly is a bad price somebody can see; a deposit
 * that pairs badly is the router quietly keeping the side that fits and
 * handing back the rest, and the screen reporting a position nobody has.
 */

const ONE = 1_000_000_000_000_000_000n;

const asset = (address, symbol, decimals = 18) => ({
    address,
    symbol,
    decimals,
});

const COIN = asset(null, 'CYBER');
const USDC = asset('0x1111111111111111111111111111111111111111', 'USDC', 6);

test('the second side of a deposit is the pool price, not a guess', () => {
    // 1000 : 2000 — every unit of the first side is worth two of the second.
    assert.equal(pairedAmount(ONE, ONE * 1000n, ONE * 2000n), ONE * 2n);
    assert.equal(
        pairedAmount(ONE * 3n, ONE * 2000n, ONE * 1000n),
        ONE + ONE / 2n,
    );
});

test('an empty pool has no ratio to pair against', () => {
    assert.equal(pairedAmount(ONE, 0n, ONE * 10n), null);
    assert.equal(pairedAmount(ONE, ONE * 10n, 0n), null);
    assert.equal(pairedAmount(0n, ONE, ONE), null);
});

test('the square root is a floor and never a float', () => {
    assert.equal(sqrtBigInt(0n), 0n);
    assert.equal(sqrtBigInt(1n), 1n);
    assert.equal(sqrtBigInt(3n), 1n);
    assert.equal(sqrtBigInt(4n), 2n);
    assert.equal(sqrtBigInt(4n * ONE * ONE), 2n * ONE);
    // A number no float could hold to the unit, which is the whole point.
    assert.equal(sqrtBigInt(ONE * ONE + 1n), ONE);
});

test('the first deposit is paid the geometric mean, less what the pair burns', () => {
    // sqrt(1e18 * 4e18) = 2e18, and a thousand units stay in the pair forever.
    assert.equal(mintedLp(ONE, ONE * 4n, 0n, 0n, 0n), ONE * 2n - 1000n);
});

test('a first deposit too small to cover the burned minimum mints nothing', () => {
    assert.equal(mintedLp(10n, 10n, 0n, 0n, 0n), 0n);
});

test('every later deposit is paid for its smaller side', () => {
    const reserveA = ONE * 1000n;
    const reserveB = ONE * 2000n;
    const supply = ONE * 1000n;

    // Paired at the pool's own ratio: both sides agree, and both are paid.
    assert.equal(mintedLp(ONE, ONE * 2n, reserveA, reserveB, supply), ONE);

    // Five times too much of the second side buys nothing extra — which is
    // the pair refusing a lopsided deposit, and the reason this wallet
    // derives the second amount instead of letting somebody type it.
    assert.equal(mintedLp(ONE, ONE * 10n, reserveA, reserveB, supply), ONE);
});

test('the share is measured against the pool this deposit joins', () => {
    // One into a thousand is a thousandth of the pool *after* the mint.
    assert.equal(poolShareAfter(ONE, ONE * 999n), 0.001);
    assert.equal(poolShareAfter(0n, ONE * 999n), 0);
    assert.equal(poolShareAfter(ONE, 0n), 1);
});

test('a refusal names which side is the problem', () => {
    const base = {
        first: COIN,
        second: USDC,
        amountFirst: ONE,
        amountSecond: 1_000_000n,
        balanceFirst: ONE * 10n,
        balanceSecond: 10_000_000n,
    };

    assert.equal(canAddLiquidity(base), 'ok');
    assert.equal(canAddLiquidity({ ...base, second: null }), 'empty');
    assert.equal(canAddLiquidity({ ...base, second: COIN }), 'sameAsset');
    assert.equal(canAddLiquidity({ ...base, amountSecond: 0n }), 'empty');
    assert.equal(
        canAddLiquidity({ ...base, balanceFirst: ONE / 2n }),
        'shortFirst',
    );
    assert.equal(
        canAddLiquidity({ ...base, balanceSecond: 1n }),
        'shortSecond',
    );
});

test('the same token as itself is refused however it is spelled', () => {
    const upper = asset(USDC.address.toUpperCase(), 'USDC', 6);

    assert.equal(
        canAddLiquidity({
            first: USDC,
            second: upper,
            amountFirst: 1n,
            amountSecond: 1n,
            balanceFirst: null,
            balanceSecond: null,
        }),
        'sameAsset',
    );
});

test('a balance nobody could read never refuses a deposit on its own', () => {
    assert.equal(
        canAddLiquidity({
            first: COIN,
            second: USDC,
            amountFirst: ONE,
            amountSecond: 1_000_000n,
            balanceFirst: null,
            balanceSecond: null,
        }),
        'ok',
    );
});

test('a withdrawal takes a percentage of the LP and never more than all', () => {
    assert.equal(withdrawalLp(ONE, 100), ONE);
    assert.equal(withdrawalLp(ONE, 25), ONE / 4n);
    assert.equal(withdrawalLp(ONE, 0), 0n);
    assert.equal(withdrawalLp(ONE, 250), ONE);
    assert.equal(withdrawalLp(ONE, -5), 0n);
});

test('the wrapped coin is found on either side of a pool, or not at all', () => {
    const config = swapChainFor(CYBERIA_CHAIN_ID);
    const wrapped = config.wrappedNative;
    const other = '0x2222222222222222222222222222222222222222';

    const position = (tokens) => ({
        pair: '0x3333333333333333333333333333333333333333',
        tokens,
        symbols: ['A', 'B'],
        decimals: [18, 18],
        balance: ONE,
        totalSupply: ONE,
        reserves: [ONE, ONE],
    });

    assert.equal(wrappedSide(position([wrapped, other]), config), 0);
    assert.equal(wrappedSide(position([other, wrapped]), config), 1);
    // Lowercased, because an address off an explorer arrives however it likes.
    assert.equal(
        wrappedSide(position([other, wrapped.toLowerCase()]), config),
        1,
    );
    assert.equal(wrappedSide(position([other, other]), config), null);
});

/*
 * A chain with one pool on it, answering every read a quote makes.
 *
 * `balances` is keyed by token address (lowercase); the coin's balance is
 * `eth_getBalance`. Allowances are zero, so every token side needs approving
 * — which is the state a second deposit finds, since the first one spent them.
 */
const mockChain = async (t, { tokens, reserves, balances, coin = 0n }) => {
    const pairAddress = '0x4444444444444444444444444444444444444444';
    const erc20 = new Interface([
        'function allowance(address,address) view returns (uint256)',
        'function balanceOf(address) view returns (uint256)',
    ]);
    const pair = new Interface([
        'function token0() view returns (address)',
        'function getReserves() view returns (uint112,uint112,uint32)',
        'function totalSupply() view returns (uint256)',
    ]);
    const factory = new Interface([
        'function getPair(address,address) view returns (address)',
    ]);

    const result = ({ to, data }) => {
        const selector = data.slice(0, 10);
        const encode = (iface, name, values) =>
            iface.encodeFunctionResult(name, values);

        if (selector === factory.getFunction('getPair').selector) {
            return encode(factory, 'getPair', [pairAddress]);
        }

        if (to.toLowerCase() === pairAddress) {
            if (selector === pair.getFunction('token0').selector) {
                return encode(pair, 'token0', [tokens[0]]);
            }

            if (selector === pair.getFunction('getReserves').selector) {
                return encode(pair, 'getReserves', [...reserves, 0]);
            }

            return encode(pair, 'totalSupply', [ONE * 1000n]);
        }

        if (selector === erc20.getFunction('allowance').selector) {
            return encode(erc20, 'allowance', [0n]);
        }

        return encode(erc20, 'balanceOf', [balances[to.toLowerCase()]]);
    };

    const server = createServer(async (request, response) => {
        let text = '';

        for await (const chunk of request) {
            text += chunk;
        }

        const messages = JSON.parse(text);
        const answer = (call) => ({
            jsonrpc: '2.0',
            id: call.id,
            result:
                call.method === 'eth_getBalance'
                    ? `0x${coin.toString(16)}`
                    : result(call.params[0]),
        });
        response.setHeader('Content-Type', 'application/json');
        response.end(
            JSON.stringify(
                Array.isArray(messages)
                    ? messages.map(answer)
                    : answer(messages),
            ),
        );
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    t.after(
        () =>
            new Promise((resolve) => {
                server.close(resolve);
                server.closeAllConnections();
            }),
    );

    return `http://127.0.0.1:${server.address().port}`;
};

test('a deposit the token list forgot is weighed against the chain', async (t) => {
    // The deposit that reverted on Cyberia: all of a token went into a pool,
    // the list dropped it at zero, and the same amount was pressed again.
    const mine = asset('0x5555555555555555555555555555555555555555', 'MINE');
    const lain = asset('0x6666666666666666666666666666666666666666', 'LAIN');
    const rpcUrl = await mockChain(t, {
        tokens: [lain.address, mine.address],
        reserves: [ONE * 2n, ONE * 1000n],
        balances: {
            [mine.address]: 0n,
            [lain.address]: ONE * 300n,
        },
    });

    const quote = await quoteAddLiquidity({
        chainId: CYBERIA_CHAIN_ID,
        first: mine,
        second: lain,
        amountFirst: ONE * 500n,
        amountSecond: ONE,
        slippageBps: 50,
        account: '0x0000000000000000000000000000000000000001',
        gasPrice: 1_875_000_000n,
        rpcUrl,
    });

    // In the order the boxes were filled, not the pair's token0/token1.
    assert.deepEqual(quote.balances, [0n, ONE * 300n]);
    assert.equal(quote.approvals.length, 2);

    // The screen knew no balance for MINE, and unknown refuses nothing...
    const fromList = {
        first: mine,
        second: lain,
        amountFirst: quote.amountFirst,
        amountSecond: quote.amountSecond,
        balanceFirst: null,
        balanceSecond: ONE * 300n,
    };

    assert.equal(canAddLiquidity(fromList), 'ok');

    // ...while the chain's zero does, and a zero is not "unknown".
    assert.equal(
        canAddLiquidity({
            ...fromList,
            balanceFirst: quote.balances[0] ?? fromList.balanceFirst,
            balanceSecond: quote.balances[1] ?? fromList.balanceSecond,
        }),
        'shortFirst',
    );
});

test('the coin side of a deposit is weighed against the account balance', async (t) => {
    const token = asset('0x7777777777777777777777777777777777777777', 'TKN');
    const rpcUrl = await mockChain(t, {
        tokens: [token.address, swapChainFor(CYBERIA_CHAIN_ID).wrappedNative],
        reserves: [ONE, ONE],
        balances: { [token.address]: ONE * 5n },
        coin: ONE / 2n,
    });

    const quote = await quoteAddLiquidity({
        chainId: CYBERIA_CHAIN_ID,
        first: COIN,
        second: token,
        amountFirst: ONE,
        amountSecond: ONE,
        slippageBps: 50,
        account: '0x0000000000000000000000000000000000000001',
        gasPrice: 1_875_000_000n,
        rpcUrl,
    });

    assert.deepEqual(quote.balances, [ONE / 2n, ONE * 5n]);
    assert.equal(quote.kind, 'native-first');
});
