import assert from 'node:assert/strict';
import test from 'node:test';
import {
    bridgeBlockFor,
    bridgeOptions,
    bridgeRateFee,
    depositAddressFor,
} from '@/lib/wallet/bridge';
import { CYBERIA_DAPPS, dappBridgeMode } from '@/lib/wallet/dapps';

/**
 * Which corridors the wallet will sign, and why it refuses the rest.
 *
 * Every refusal here is a different sentence to a user and, more importantly, a
 * different consequence. Refusing "this wallet has no account there" costs
 * somebody a switch; failing to refuse a corridor whose lock goes through the
 * bridge contract costs them the transfer — the coin arrives at an address the
 * relayer never credits, and there is no cancel behind it.
 */

const config = {
    relayer: '0xfA4100000000000000000000000000000000517a',
    feeBps: 25,
    feeFlatUsd: 0.1,
    chains: [
        {
            key: 'cyberia',
            label: 'Cyberia',
            type: 'evm',
            addressType: 'evm',
            wallet: 'evm',
            evmChainId: 49406,
            rpcUrl: 'https://rpc.cyberia.church',
            explorerTx: 'https://explorer.cyberia.church/tx/{hash}',
            nativeCurrency: { name: 'Cyber', symbol: 'CYBER', decimals: 18 },
            depositAddress: '0xdEp0517000000000000000000000000000000001',
        },
        {
            key: 'robinhood',
            label: 'Robinhood Chain',
            type: 'evm',
            addressType: 'evm',
            wallet: 'evm',
            evmChainId: 4663,
            rpcUrl: 'https://rpc.mainnet.chain.robinhood.com',
            explorerTx: null,
            nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 },
            depositAddress: null,
        },
        {
            key: 'solana',
            label: 'Solana',
            type: 'solana',
            addressType: 'solana',
            wallet: 'solana',
            evmChainId: null,
            rpcUrl: '/api/solana/rpc',
            explorerTx: null,
            nativeCurrency: { name: 'Solana', symbol: 'SOL', decimals: 9 },
            depositAddress: 'So1anaHotWa11et',
        },
    ],
    routes: [
        {
            direction: 'robinhood_to_evm',
            source: 'robinhood',
            destination: 'cyberia',
            sourceLabel: 'Robinhood Chain',
            destinationLabel: 'Cyberia',
            destinationAddressType: 'evm',
            autoProcess: true,
            operational: true,
            unavailableReason: null,
            tokens: ['ETH', 'CYBER'],
        },
        {
            direction: 'evm_to_sol',
            source: 'cyberia',
            destination: 'solana',
            sourceLabel: 'Cyberia',
            destinationLabel: 'Solana',
            destinationAddressType: 'solana',
            autoProcess: true,
            operational: true,
            unavailableReason: null,
            tokens: ['CYBER.sol', 'USDC'],
        },
        {
            direction: 'sol_to_evm',
            source: 'solana',
            destination: 'cyberia',
            sourceLabel: 'Solana',
            destinationLabel: 'Cyberia',
            destinationAddressType: 'evm',
            autoProcess: true,
            operational: true,
            unavailableReason: null,
            tokens: ['CYBER.sol'],
        },
        {
            direction: 'evm_to_robinhood',
            source: 'cyberia',
            destination: 'robinhood',
            sourceLabel: 'Cyberia',
            destinationLabel: 'Robinhood Chain',
            destinationAddressType: 'evm',
            autoProcess: true,
            operational: false,
            unavailableReason: 'Coming soon',
            tokens: ['CYBER'],
        },
    ],
    tokens: [
        {
            symbol: 'CYBER.sol',
            model: 'native',
            chains: {
                cyberia: {
                    address: '0xc45e0000000000000000000000000000000000c5',
                    mint: null,
                    master: null,
                    native: false,
                    decimals: 18,
                    tokenProgram: null,
                },
            },
        },
        {
            symbol: 'USDC',
            model: 'direct',
            chains: {
                cyberia: {
                    address: '0xdc25597b19799010047f17e9591efe08efd40077',
                    mint: null,
                    master: null,
                    native: false,
                    decimals: 6,
                    tokenProgram: null,
                },
            },
        },
        {
            symbol: 'ETH',
            model: 'mint',
            chains: {
                robinhood: {
                    address: null,
                    mint: null,
                    master: null,
                    native: true,
                    decimals: 18,
                    tokenProgram: null,
                },
            },
        },
    ],
};

/** The EVM chains a seed-derived wallet holds here. */
const HELD = [49406, 4663];

const routeOf = (direction) =>
    config.routes.find((route) => route.direction === direction);

test('a closed corridor is refused before anything about the wallet is asked', () => {
    assert.equal(
        bridgeBlockFor(config, routeOf('evm_to_robinhood'), 'CYBER', HELD),
        'closed',
    );
});

test('a corridor that starts off-EVM is named, not silently dropped', () => {
    // Solana → Cyberia is a real, open corridor; it just is not one this
    // screen can sign the source leg of.
    assert.equal(
        bridgeBlockFor(config, routeOf('sol_to_evm'), 'CYBER.sol', HELD),
        'sourceUnsupported',
    );
});

test('an asset locked through the bridge contract is never sent by transfer', () => {
    // The whole point: `model: native` moves through the contract, which emits
    // the nonce the payout is matched to. A plain transfer to the deposit
    // address would be credited to nobody.
    assert.equal(
        bridgeBlockFor(config, routeOf('evm_to_sol'), 'CYBER.sol', HELD),
        'contractLock',
    );

    // The same corridor carries an ordinary ERC-20 too, and that one is fine.
    assert.equal(
        bridgeBlockFor(config, routeOf('evm_to_sol'), 'USDC', HELD),
        'ok',
    );
});

test('a chain this wallet has no account on is refused for that reason', () => {
    assert.equal(
        bridgeBlockFor(config, routeOf('robinhood_to_evm'), 'ETH', [49406]),
        'noAccount',
    );
    assert.equal(
        bridgeBlockFor(config, routeOf('robinhood_to_evm'), 'ETH', HELD),
        'ok',
    );
});

test('a corridor with nowhere to deposit is refused rather than guessed at', () => {
    const nowhere = { ...config, relayer: null };

    // Robinhood names no deposit address of its own, so without the relayer
    // fallback there is no address to send to at all.
    assert.equal(depositAddressFor(nowhere, 'robinhood'), null);
    assert.equal(
        bridgeBlockFor(nowhere, routeOf('robinhood_to_evm'), 'ETH', HELD),
        'noDeposit',
    );
});

test('a chain names its own deposit address before the relayer fallback', () => {
    assert.equal(
        depositAddressFor(config, 'cyberia'),
        '0xdEp0517000000000000000000000000000000001',
    );
    assert.equal(depositAddressFor(config, 'robinhood'), config.relayer);
});

test('every corridor is listed, open or not', () => {
    const options = bridgeOptions(config, HELD);

    assert.equal(options.length, config.routes.length);
    assert.deepEqual(
        options.map((option) => option.block),
        ['ok', 'ok', 'sourceUnsupported', 'closed'],
    );

    // The source chain id travels with the row, because it is what decides
    // which key signs.
    assert.equal(options[0].sourceChainId, 4663);
    assert.equal(options[2].sourceChainId, null);
});

test('the bridge rate is taken from the amount, and zero stays zero', () => {
    const amount = 1_000_000_000_000_000_000n;

    assert.equal(bridgeRateFee(amount, 25), amount / 400n);
    assert.equal(bridgeRateFee(amount, 0), 0n);
    assert.equal(bridgeRateFee(0n, 25), 0n);

    // Rounding is towards the user's side of the trade: integer division
    // never charges more than the rate.
    assert.equal(bridgeRateFee(399n, 25), 0n);
});

/**
 * The Web tab's one real decision: what this shell can honestly offer a page.
 *
 * Getting it wrong in the generous direction is the failure that matters — a
 * wallet that says "pages here can reach me" in a plain browser tab sends
 * somebody to a dapp that will find nothing and blame itself.
 */
test('a page is only told a wallet exists when something actually offers one', () => {
    assert.equal(dappBridgeMode(null, true), 'extension');
    assert.equal(dappBridgeMode(null, false), 'browser');

    // The desktop shell owns its connection but hosts no other site's page,
    // and an injected provider there is still what mediates.
    assert.equal(dappBridgeMode('desktop', false), 'desktop');
    assert.equal(dappBridgeMode('desktop', true), 'extension');

    // Inside Telegram and on the phone shell the pages open elsewhere.
    assert.equal(dappBridgeMode('mobile', false), 'mobile');
    assert.equal(dappBridgeMode('telegram', false), 'mobile');
});

test('every listed dapp is a path on this site, not an outside link', () => {
    for (const dapp of CYBERIA_DAPPS) {
        assert.match(dapp.path, /^\/[a-z-]+$/);
        assert.match(dapp.tag, /^[A-Z]{2}$/);
    }

    // The ones the wallet does itself are marked, because that row offers to
    // do it here instead of handing the user to a page that needs a provider.
    const internal = CYBERIA_DAPPS.filter((dapp) => dapp.inWallet).map(
        (dapp) => dapp.key,
    );

    assert.deepEqual(internal, ['swap', 'farm', 'launchpad', 'dao', 'bridge']);
});
