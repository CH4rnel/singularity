import assert from 'node:assert/strict';
import test from 'node:test';
import {
    bridgeIntentMemo,
    bridgeIntentMessage,
    bridgeIntentRequirement,
} from '@/lib/bridgeIntent';
import { withBridgeReservation } from '@/lib/bridgeReservation';
import { submitBridge } from '@/lib/wallet/bridge';

// The same vector as BridgeRecipientProofTest.php: the server rebuilds this
// text and checks the signature against it, so a single differing byte
// strands a real deposit as unproven.
test('the signed statement matches App\\Support\\BridgeIntent byte for byte', () => {
    assert.equal(
        bridgeIntentMessage({
            direction: 'bnb_to_evm',
            token: 'USDT',
            sender: '0xAbCdEf0123456789aBcDeF0123456789AbCdEf01',
            recipient: ' 0x00000000000000000000000000000000000000AA ',
            reference: 'res-123',
        }),
        [
            'Cyberia bridge: payout recipient',
            '',
            'I sent this deposit and I choose where it is paid out.',
            'This is not a login and it moves no funds.',
            '',
            'Direction: bnb_to_evm',
            'Token: USDT',
            'Sender: 0xabcdef0123456789abcdef0123456789abcdef01',
            'Recipient: 0x00000000000000000000000000000000000000aa',
            'Reference: res-123',
        ].join('\n'),
    );
    assert.equal(
        bridgeIntentMemo('0x00000000000000000000000000000000000000AA'),
        'cyberia-bridge:0x00000000000000000000000000000000000000aa',
    );
});

test('only corridors that bind nothing else ask for a signature', () => {
    const of = (direction, sourceType, tokenModel) =>
        bridgeIntentRequirement({ direction, sourceType, tokenModel });

    assert.equal(of('bnb_to_evm', 'evm', 'direct'), 'signature');
    assert.equal(of('sol_to_evm', 'solana', 'native'), 'signature');
    assert.equal(of('evm_to_sol', 'evm', 'direct'), 'signature');
    assert.equal(of('evm_to_sol', 'evm', 'native'), 'onchain');
    assert.equal(of('ton_to_evm', 'ton', 'mint'), 'memo');
    assert.equal(of('xmr_to_evm', 'monero', 'mint'), 'deposit_address');
});

const reserveThenSubmit = (calls) => async (url, options) => {
    calls.push({ url, body: JSON.parse(options.body) });

    return new Response(
        JSON.stringify(
            url.endsWith('/reserve')
                ? {
                      reservation: {
                          reference: 'hold-9',
                          expires_at: new Date(
                              Date.now() + 900000,
                          ).toISOString(),
                      },
                  }
                : { bridge_request: { id: 3, status: 'pending' } },
        ),
    );
};

const intent = {
    direction: 'bnb_to_evm',
    token: 'USDT',
    amount: '5',
    senderAddress: '0x5555555555555555555555555555555555555555',
    recipientAddress: '0x6666666666666666666666666666666666666666',
};

test('the recipient is signed over the reservation before anything is sent', async (t) => {
    const calls = [];
    const order = [];
    t.mock.method(globalThis, 'fetch', reserveThenSubmit(calls));

    const locked = await withBridgeReservation(
        intent,
        async () => {
            order.push('deposit');

            return { txHash: '0xabc', nonce: 0 };
        },
        async (message) => {
            order.push('sign');
            assert.equal(
                message,
                bridgeIntentMessage({
                    direction: intent.direction,
                    token: intent.token,
                    sender: intent.senderAddress,
                    recipient: intent.recipientAddress,
                    reference: 'hold-9',
                }),
            );

            return '0xsigned';
        },
    );

    assert.deepEqual(order, ['sign', 'deposit']);
    assert.equal(locked.intentSignature, '0xsigned');

    await submitBridge({
        direction: intent.direction,
        token: intent.token,
        amount: intent.amount,
        sender: intent.senderAddress,
        recipient: intent.recipientAddress,
        sourceTxHash: locked.deposit.txHash,
        sourceNonce: 0,
        reservation: locked.reservation,
        intentSignature: locked.intentSignature,
    });

    assert.equal(calls[1].body.intent_signature, '0xsigned');
});

test('declining the recipient signature never reaches the transfer', async (t) => {
    t.mock.method(globalThis, 'fetch', reserveThenSubmit([]));
    let sent = false;

    await assert.rejects(
        withBridgeReservation(
            intent,
            async () => {
                sent = true;
            },
            async () => null,
        ),
        /Nothing was sent/,
    );
    assert.equal(sent, false);
});
