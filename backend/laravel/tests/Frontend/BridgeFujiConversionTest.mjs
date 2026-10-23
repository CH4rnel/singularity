import assert from 'node:assert/strict';
import test, { beforeEach } from 'node:test';
import { capacityVerdict, parseCapacity } from '@/lib/bridgeCapacity';
import { explorerTxUrl, initBridgeConfig } from '@/lib/bridgeConfig';
import {
    bridgePayoutAmount,
    bridgeSourceAmount,
    convertBridgeUnits,
    feeTokenAmount,
} from '@/lib/bridgeFee';
import { EVM_CHAINS } from '@/lib/evmChains';
import { bridgePayoutUnits } from '@/lib/wallet/bridge';
import { shippedChains } from '@/lib/wallet/chains';

const toFuji = { numerator: '1000', denominator: '1' };
const fromFuji = { numerator: '1', denominator: '1000' };

const tokenChain = (decimals, native = false) => ({
    address: null,
    mint: null,
    master: null,
    native,
    decimals,
    tokenProgram: null,
});

const route = (direction, source, destination, conversionRates = {}) => ({
    direction,
    source,
    destination,
    sourceLabel: source,
    destinationLabel: destination,
    destinationAddressType: 'evm',
    autoProcess: true,
    operational: true,
    unavailableReason: null,
    tokens: ['CYBER', 'AVAX.FUJI', 'SCALED', 'FALLBACK'],
    conversionRates,
});

let config;

beforeEach(() => {
    config = {
        chains: [],
        relayer: null,
        feeBps: 0,
        feeFlatUsd: 0,
        routes: [
            route('evm_to_cyberia_fuji', 'cyberia', 'cyberia_fuji', {
                CYBER: toFuji,
                SCALED: { numerator: '3', denominator: '2' },
            }),
            route('cyberia_fuji_to_evm', 'cyberia_fuji', 'cyberia', {
                CYBER: fromFuji,
            }),
            route('evm_to_avalanche_fuji', 'cyberia', 'avalanche_fuji'),
        ],
        tokens: [
            {
                symbol: 'CYBER',
                model: 'mint',
                chains: {
                    cyberia: tokenChain(18, true),
                    cyberia_fuji: tokenChain(18, true),
                },
            },
            {
                symbol: 'AVAX.FUJI',
                model: 'mint',
                chains: {
                    cyberia: tokenChain(18),
                    avalanche_fuji: tokenChain(18, true),
                    cyberia_fuji: tokenChain(18),
                },
            },
            {
                symbol: 'SCALED',
                model: 'mint',
                chains: {
                    cyberia: tokenChain(6),
                    cyberia_fuji: tokenChain(9),
                },
            },
        ],
    };
    initBridgeConfig(config.chains, config.routes, config.tokens);
});

test('CYBER conversion preserves integers beyond floating-point precision in both directions', () => {
    const source = 123456789012345678901234567890123456789n;
    const converted = 123456789012345678901234567890123456789000n;

    assert.equal(convertBridgeUnits(source, 18, 18, toFuji), converted);
    assert.equal(convertBridgeUnits(converted, 18, 18, fromFuji), source);
    assert.equal(convertBridgeUnits(1999n, 18, 18, fromFuji), 1n);
    assert.equal(convertBridgeUnits(999n, 18, 18, fromFuji), 0n);
});

test('conversion scales source and destination decimals before truncating once', () => {
    assert.equal(
        convertBridgeUnits(1234567n, 6, 9, {
            numerator: '3',
            denominator: '2',
        }),
        1851850500n,
    );
    assert.equal(
        convertBridgeUnits(1999999n, 9, 6, {
            numerator: '3',
            denominator: '2',
        }),
        2999n,
    );
    assert.equal(convertBridgeUnits(1234567n, 6, 9), 1234567000n);
    assert.equal(convertBridgeUnits(1999999n, 9, 6), 1999n);
});

test('zero, negative and malformed conversion ratios fail closed', () => {
    for (const invalidRate of [
        null,
        {},
        { numerator: '1000' },
        { numerator: '0', denominator: '1' },
        { numerator: '1', denominator: '0' },
        { numerator: '-1', denominator: '1' },
        { numerator: '1', denominator: '-1' },
        { numerator: '1.5', denominator: '1' },
        { numerator: '1', denominator: '1.5' },
        { numerator: '1e3', denominator: '1' },
        { numerator: '', denominator: '1' },
        { numerator: '1', denominator: '' },
        { numerator: ' 1', denominator: '1' },
        { numerator: 1000, denominator: 1 },
    ]) {
        assert.equal(
            convertBridgeUnits(1000000000000000000n, 18, 18, invalidRate),
            null,
            JSON.stringify(invalidRate),
        );
    }

    assert.equal(convertBridgeUnits(-1n, 18, 18, toFuji), null);
    assert.equal(convertBridgeUnits(1n, -1, 18, toFuji), null);
    assert.equal(convertBridgeUnits(1n, 18, 1.5, toFuji), null);
});

test('payout uses the public route ratio without changing the source input', () => {
    const amount = '123456789012345678.123456789012345678';

    assert.equal(
        bridgePayoutAmount('evm_to_cyberia_fuji', 'CYBER', amount),
        '123456789012345678123.456789012345678',
    );
    assert.equal(
        bridgePayoutAmount('cyberia_fuji_to_evm', 'CYBER', '1'),
        '0.001',
    );
    assert.equal(amount, '123456789012345678.123456789012345678');
});

test('source fees are deducted before applying the reciprocal conversion', () => {
    assert.equal(
        bridgePayoutAmount('evm_to_cyberia_fuji', 'CYBER', '2', '0.1'),
        '1900',
    );
    assert.equal(
        bridgePayoutAmount('cyberia_fuji_to_evm', 'CYBER', '1000', '10'),
        '0.99',
    );
    assert.equal(
        bridgePayoutAmount('evm_to_cyberia_fuji', 'CYBER', '0.1', '0.1'),
        '0',
    );
    assert.equal(
        bridgePayoutAmount('evm_to_cyberia_fuji', 'CYBER', '0.09', '0.1'),
        '0',
    );
});

test('payout uses configured token decimals and keeps non-converting Fuji AVAX at one-to-one', () => {
    assert.equal(
        bridgePayoutAmount('evm_to_cyberia_fuji', 'SCALED', '1.234567'),
        '1.8518505',
    );
    assert.equal(
        bridgePayoutAmount('cyberia_fuji_to_evm', 'SCALED', '1.234567899'),
        '1.234567',
    );
    assert.equal(
        bridgePayoutAmount('evm_to_avalanche_fuji', 'AVAX.FUJI', '0.125'),
        '0.125',
    );
    assert.equal(
        bridgePayoutAmount(
            'evm_to_cyberia_fuji',
            'FALLBACK',
            '1.000000000000000001',
        ),
        '1.000000000000000001',
    );
});

test('invalid payout amounts, fees and route ratios never produce a quote', () => {
    for (const amount of ['', '.', 'abc', '-1', '1e3']) {
        assert.equal(
            bridgePayoutAmount('evm_to_cyberia_fuji', 'CYBER', amount),
            null,
            amount,
        );
    }

    for (const fee of ['abc', '-1', '1e3']) {
        assert.equal(
            bridgePayoutAmount('evm_to_cyberia_fuji', 'CYBER', '2', fee),
            null,
            fee,
        );
    }

    for (const invalidRate of [
        null,
        { numerator: '1000', denominator: '0' },
        { numerator: 1000, denominator: 1 },
    ]) {
        initBridgeConfig(undefined, [
            route('evm_to_cyberia_fuji', 'cyberia', 'cyberia_fuji', {
                CYBER: invalidRate,
            }),
        ]);

        assert.equal(
            bridgePayoutAmount('evm_to_cyberia_fuji', 'CYBER', '1'),
            null,
            JSON.stringify(invalidRate),
        );
    }
});

test('the wallet calculates destination units after deducting source fees', () => {
    assert.equal(
        bridgePayoutUnits(
            config,
            'evm_to_cyberia_fuji',
            'CYBER',
            2000000000000000000n,
            100000000000000000n,
        ),
        1900000000000000000000n,
    );
    assert.equal(
        bridgePayoutUnits(
            config,
            'cyberia_fuji_to_evm',
            'CYBER',
            123456789012345678901234567890123456789n,
            1000n,
        ),
        123456789012345678901234567890123455n,
    );
    assert.equal(
        bridgePayoutUnits(config, 'evm_to_cyberia_fuji', 'CYBER', 1000n, 1001n),
        0n,
    );
});

test('the wallet refuses a payout with missing token identity or an invalid rate', () => {
    assert.equal(bridgePayoutUnits(config, 'missing', 'CYBER', 1000n), null);
    assert.equal(
        bridgePayoutUnits(config, 'evm_to_cyberia_fuji', 'FALLBACK', 1000n),
        null,
    );

    delete config.tokens[0].chains.cyberia_fuji;
    assert.equal(
        bridgePayoutUnits(config, 'evm_to_cyberia_fuji', 'CYBER', 1000n),
        null,
    );

    config.tokens[0].chains.cyberia_fuji = tokenChain(18, true);
    config.routes[0].conversionRates.CYBER = null;
    assert.equal(
        bridgePayoutUnits(config, 'evm_to_cyberia_fuji', 'CYBER', 1000n),
        null,
    );
});

test('destination capacity permits equality after multiplying old CYBER and refuses one more source wei', () => {
    const capacity = parseCapacity({
        state: 'available',
        available_raw: '1000000000000000000000',
        decimals: 18,
    });

    assert.equal(
        capacityVerdict(
            capacity,
            bridgePayoutAmount('evm_to_cyberia_fuji', 'CYBER', '1', '0'),
        ),
        'ok',
    );
    assert.equal(
        capacityVerdict(
            capacity,
            bridgePayoutAmount(
                'evm_to_cyberia_fuji',
                'CYBER',
                '1.000000000000000001',
                '0',
            ),
        ),
        'exceeded',
    );
});

test('destination capacity compares the reciprocal payout in old CYBER units', () => {
    const payout = bridgePayoutAmount(
        'cyberia_fuji_to_evm',
        'CYBER',
        '1000',
        '0',
    );

    assert.equal(payout, '1');
    assert.equal(
        capacityVerdict(
            parseCapacity({
                state: 'available',
                available_raw: '1000000000000000000',
                decimals: 18,
            }),
            payout,
        ),
        'ok',
    );
    assert.equal(
        capacityVerdict(
            parseCapacity({
                state: 'available',
                available_raw: '999999999999999999',
                decimals: 18,
            }),
            payout,
        ),
        'exceeded',
    );
});

test('MAX turns destination capacity back into source units in both directions', () => {
    assert.equal(
        bridgeSourceAmount('evm_to_cyberia_fuji', 'CYBER', '1000'),
        '1',
    );
    assert.equal(
        bridgeSourceAmount('cyberia_fuji_to_evm', 'CYBER', '1'),
        '1000',
    );
});

test('small numeric fees retain plain decimal precision before conversion', () => {
    assert.equal(feeTokenAmount(1e-9), '0.000000001');
    assert.equal(feeTokenAmount(1.26e-7), '0.000000126');
    assert.equal(
        bridgePayoutAmount(
            'evm_to_cyberia_fuji',
            'CYBER',
            '1',
            feeTokenAmount(1e-9),
        ),
        '999.999999',
    );
});

test('the wallet registry distinguishes Avalanche Fuji AVAX from Cyberia Fuji CYBER', () => {
    const avalanche = EVM_CHAINS.find((chain) => chain.chainId === 43113);
    const cyberia = EVM_CHAINS.find((chain) => chain.chainId === 49407);

    assert.ok(
        avalanche,
        'Avalanche Fuji must be available to wallet network switching',
    );
    assert.ok(
        cyberia,
        'Cyberia Fuji must be available to wallet network switching',
    );
    assert.match(avalanche.name, /Avalanche Fuji/);
    assert.equal(avalanche.nativeCurrency.symbol, 'AVAX');
    assert.equal(avalanche.nativeCurrency.decimals, 18);
    assert.match(cyberia.name, /Cyberia Fuji/);
    assert.equal(cyberia.nativeCurrency.symbol, 'CYBER');
    assert.equal(cyberia.nativeCurrency.decimals, 18);
    assert.deepEqual(cyberia.rpcUrls, ['https://cyberia.church/api/fuji/rpc']);

    const walletAvalanche = shippedChains().find(
        (chain) => chain.id === 'avalanche_fuji',
    );
    const walletCyberia = shippedChains().find(
        (chain) => chain.id === 'cyberia_fuji',
    );

    assert.equal(walletAvalanche?.chainId, 43113);
    assert.equal(walletAvalanche?.symbol, 'AVAX');
    assert.equal(walletCyberia?.chainId, 49407);
    assert.equal(walletCyberia?.symbol, 'CYBER');
});

test('a Fuji transaction never links to the old Cyberia explorer when its explorer is absent', () => {
    initBridgeConfig([
        {
            key: 'cyberia_fuji',
            label: 'Cyberia Fuji (testnet)',
            type: 'evm',
            addressType: 'evm',
            wallet: 'evm',
            evmChainId: 49407,
            rpcUrl: 'https://cyberia.church/api/fuji/rpc',
            explorerTx: null,
            nativeCurrency: { name: 'Cyber', symbol: 'CYBER', decimals: 18 },
            depositAddress: null,
        },
    ]);

    assert.equal(explorerTxUrl('cyberia_fuji', '0x1234'), null);
    assert.equal(explorerTxUrl('unknown_network', '0x1234'), null);
    assert.equal(
        explorerTxUrl('cyberia', '0x1234'),
        'https://explorer.cyberia.church/tx/0x1234',
    );
});
