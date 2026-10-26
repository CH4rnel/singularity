import assert from 'node:assert/strict';
import test from 'node:test';
import { setShippedWalletChains, shippedChains } from '@/lib/wallet';
import {
    coinMarkets,
    exchangeSymbol,
    marketChainId,
    searchMarkets,
    tokenMarket,
} from '@/lib/wallet/markets';

/**
 * Which chart a market gets, and where its frame points.
 *
 * The whole risk in this feature is silent: TradingView answers an unknown
 * symbol with a blank chart rather than an error, so a market wrongly marked
 * `exchange` looks exactly like a market whose data has not loaded yet. These
 * tests pin the two halves that decide it — the listing table, and the fact
 * that everything else falls through to the chain's own pools or to a stated
 * "nobody prices this".
 */

/*
 * The coin list is drawn from the networks that are on, and a bare process has
 * none but Cyberia — being on is a choice a device makes. What these tests are
 * about is which chart a market gets, so they switch the shipped set on first.
 */
setShippedWalletChains(shippedChains());

test('a coin is charted from an exchange only where one was verified', () => {
    assert.equal(exchangeSymbol('bitcoin'), 'BINANCE:BTCUSDT');
    assert.equal(exchangeSymbol('solana'), 'BINANCE:SOLUSDT');

    // Monero was delisted from Binance in 2024; the venue is part of the
    // answer, and taking the default would draw an empty frame.
    assert.equal(exchangeSymbol('monero'), 'KRAKEN:XMRUSD');

    // Cyberia's coin is on no exchange anywhere, and no ticker-shaped guess is
    // made on its behalf.
    assert.equal(exchangeSymbol('cyberia'), null);
});

test('Cyberia falls through to its own pools rather than to nothing', () => {
    const markets = coinMarkets();
    const cyberia = markets.find((market) => market.chain === 'cyberia');

    assert.ok(cyberia, 'Cyberia is always a market');
    assert.equal(cyberia.source, 'onchain');
    assert.equal(cyberia.tvSymbol, undefined);
    assert.equal(marketChainId(cyberia), 49406);
});

test('a listed coin never carries an on-chain route it does not have', () => {
    const bitcoin = coinMarkets().find((market) => market.chain === 'bitcoin');

    assert.equal(bitcoin.source, 'exchange');
    // Bitcoin has no pool graph here; asking for one would be a lie.
    assert.equal(marketChainId(bitcoin), null);
});

test('a token is only charted where this wallet can walk the pools', () => {
    const onCyberia = tokenMarket({
        chain: 'cyberia',
        address: '0xAbC0000000000000000000000000000000000001',
        symbol: 'LAIN',
        name: 'Lain',
    });

    assert.equal(onCyberia.source, 'onchain');
    // The id is lowercased, so the same contract in two casings is one market.
    assert.equal(
        onCyberia.id,
        'token:cyberia:0xabc0000000000000000000000000000000000001',
    );

    const elsewhere = tokenMarket({
        chain: 'base',
        address: '0xAbC0000000000000000000000000000000000002',
        symbol: 'USDC',
    });

    assert.equal(elsewhere.source, 'none');
    assert.equal(elsewhere.note, 'noPool');
});

test('a token with no name falls back to its ticker, never to a blank row', () => {
    const nameless = tokenMarket({
        chain: 'cyberia',
        address: '0xabc0000000000000000000000000000000000003',
        symbol: 'MINE',
        name: '   ',
    });

    assert.equal(nameless.label, 'MINE');
});

test('search reads a ticker, a name and a pasted contract', () => {
    const markets = [
        tokenMarket({
            chain: 'cyberia',
            address: '0xabc0000000000000000000000000000000000004',
            symbol: 'LAIN',
            name: 'Lain',
        }),
        ...coinMarkets().filter((market) => market.chain === 'bitcoin'),
    ];

    assert.equal(searchMarkets(markets, 'lain').length, 1);
    assert.equal(searchMarkets(markets, 'BTC').length, 1);
    assert.equal(
        searchMarkets(markets, '0xABC00000000000000000000000000000000000')
            .length,
        1,
    );
    assert.equal(searchMarkets(markets, '   ').length, markets.length);
    assert.equal(searchMarkets(markets, 'nothing').length, 0);
});

test('the chart names the exchange quote currency rather than relabelling USDT as USD', () => {
    const markets = coinMarkets();

    assert.equal(
        markets.find((market) => market.chain === 'bitcoin').quote,
        'USDT',
    );
    assert.equal(
        markets.find((market) => market.chain === 'monero').quote,
        'USD',
    );
    assert.equal(
        markets.find((market) => market.chain === 'cyberia').quote,
        'USD',
    );
});
