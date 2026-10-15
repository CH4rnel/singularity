/**
 * Gas the Cyberia node gets wrong, pinned to what it actually answers.
 *
 * Both rules came from money lost on the site's wallet first: a coin sent to a
 * contract at the 21000 the node estimated (reverted, fee kept), and a gas
 * price under the pool floor the node itself suggested (refused at the pool).
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    CONTRACT_SEND_GAS,
    CYBERIA_CHAIN_ID,
    TRANSFER_GAS,
    chainById,
    flooredGasPrice,
    plainTransferGas,
} from '../src/shared/chains.js';

test('a coin sent to an address with no code costs 21000, estimate or not', () => {
    assert.equal(plainTransferGas('0x', 21_000n), TRANSFER_GAS);
    assert.equal(plainTransferGas('0x', null), TRANSFER_GAS);
    assert.equal(plainTransferGas('0x0', null), TRANSFER_GAS);
});

test('a coin sent to a contract is never signed at the 21000 the node claims', () => {
    // What rpc.cyberia.church answers for a value transfer to a contract.
    assert.equal(plainTransferGas('0x6080604052', 21_000n), CONTRACT_SEND_GAS);
    assert.equal(plainTransferGas('0x6080604052', null), CONTRACT_SEND_GAS);
    // A node that does run receive() and needs more is believed.
    assert.equal(plainTransferGas('0x6080604052', 150_000n), 150_000n);
});

test("Cyberia's gas price never goes under its pool floor", () => {
    const floor = chainById(CYBERIA_CHAIN_ID).minGasPrice;

    assert.equal(floor, 1_500_000_000n);
    // 0x4d8637ba — the node's own eth_gasPrice on 2026-10-08, under its floor.
    assert.equal(flooredGasPrice(0x4d8637ban, floor), floor);
    assert.equal(flooredGasPrice(2_000_000_000n, floor), 2_000_000_000n);
});

test('a chain without a floor takes the node at its word', () => {
    assert.equal(flooredGasPrice(50_000_000n, undefined), 50_000_000n);
    assert.equal(chainById(56).minGasPrice, undefined);
});
