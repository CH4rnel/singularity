import assert from 'node:assert/strict';
import test from 'node:test';
import { Wallet } from 'ethers';
import {
    MAX_MESSAGE_BYTES,
    chatFingerprint,
    chatKeyStatement,
    chatMessageId,
    chatPrivateKey,
    chatPublicKey,
    conversationId,
    conversationKey,
    openMessage,
    sealMessage,
    verifyChatKey,
} from '@/lib/wallet';

/**
 * The wallet's encrypted chat, pinned at the layer that makes it end-to-end.
 *
 * Everything above this file — the relay, the screens, the local cache — is
 * allowed to be wrong in ways that are visible and fixable. This layer is not:
 * a mistake here is a conversation that was never private, and nothing further
 * up would show it. So the properties that carry the whole claim are asserted
 * directly, including the ones that are supposed to *fail*.
 */

/** Two wallets, fixed so a failure here is reproducible rather than flaky. */
const ALICE = new Wallet(`0x${'11'.repeat(32)}`);
const BOB = new Wallet(`0x${'22'.repeat(32)}`);
const MALLORY = new Wallet(`0x${'33'.repeat(32)}`);

const meta = (from, to) => ({
    id: 'a'.repeat(32),
    from: from.address.toLowerCase(),
    to: to.address.toLowerCase(),
    sentAt: '2026-08-09T12:00:00.000Z',
});

/** The key each side would use for the conversation between the two. */
const keyFor = (self, peer) =>
    conversationKey(
        chatPrivateKey(self.privateKey),
        chatPublicKey(chatPrivateKey(peer.privateKey)),
        self.address,
        peer.address,
    );

test('the messaging key is derived, separate from the spending key', () => {
    const derived = chatPrivateKey(ALICE.privateKey);

    // Deterministic: the account's own backup restores it, and no separate
    // secret has to be written down anywhere.
    assert.equal(derived, chatPrivateKey(ALICE.privateKey));

    // Accepts the key with or without the prefix, since both spellings exist
    // in the wild.
    assert.equal(derived, chatPrivateKey(ALICE.privateKey.slice(2)));

    // And it is emphatically not the key that signs transactions: whoever
    // learns it can read messages, never spend.
    assert.notEqual(derived.toLowerCase(), ALICE.privateKey.toLowerCase());
    assert.notEqual(derived, chatPrivateKey(BOB.privateKey));

    assert.match(chatPublicKey(derived), /^0x0[23][0-9a-f]{64}$/);
    assert.throws(() => chatPrivateKey('not a key'));
});

test('a published key verifies only for the address that signed it', async () => {
    const publicKey = chatPublicKey(chatPrivateKey(ALICE.privateKey));
    const issuedAt = '2026-08-09T12:00:00.000Z';
    const address = ALICE.address.toLowerCase();

    const record = {
        address,
        publicKey,
        issuedAt,
        signature: await ALICE.signMessage(
            chatKeyStatement(address, publicKey, issuedAt),
        ),
    };

    assert.equal(verifyChatKey(record), true);

    // The whole point of the signature: a relay cannot answer a lookup for
    // Alice with a key of its own and read everything sent to her.
    const substituted = {
        ...record,
        publicKey: chatPublicKey(chatPrivateKey(MALLORY.privateKey)),
    };
    assert.equal(verifyChatKey(substituted), false);

    // Nor can it re-address Alice's own signed record to another address.
    assert.equal(
        verifyChatKey({ ...record, address: BOB.address.toLowerCase() }),
        false,
    );

    // Nor edit when it was issued, which is what decides key rotation.
    assert.equal(
        verifyChatKey({ ...record, issuedAt: '2020-01-01T00:00:00.000Z' }),
        false,
    );

    assert.equal(verifyChatKey({ ...record, publicKey: '0xdead' }), false);
    assert.equal(verifyChatKey({ ...record, signature: '0x00' }), false);
});

test('both ends of a conversation derive the same key', async () => {
    const envelope = await sealMessage(
        await keyFor(ALICE, BOB),
        meta(ALICE, BOB),
        'the wired is not a place you go',
    );

    // The recipient reads it — ECDH from the other side.
    assert.equal(
        await openMessage(await keyFor(BOB, ALICE), meta(ALICE, BOB), envelope),
        'the wired is not a place you go',
    );

    // And so does the sender: the key is shared, so a wallet restored on a
    // second device recovers its own half of the thread without the relay
    // ever holding a readable copy.
    assert.equal(
        await openMessage(await keyFor(ALICE, BOB), meta(ALICE, BOB), envelope),
        'the wired is not a place you go',
    );

    // Nobody else does, which is the entire claim.
    await assert.rejects(
        openMessage(await keyFor(MALLORY, ALICE), meta(ALICE, BOB), envelope),
    );

    assert.equal(
        conversationId(ALICE.address, BOB.address),
        conversationId(BOB.address, ALICE.address),
    );
});

test('the metadata around a message is authenticated, not just carried', async () => {
    const key = await keyFor(ALICE, BOB);
    const original = meta(ALICE, BOB);
    const envelope = await sealMessage(key, original, 'send it to 0xdead');

    // A relay that rewrites who sent a message, when, or which message it is
    // produces something that fails to open — not a plausible lie.
    for (const forged of [
        { ...original, from: MALLORY.address.toLowerCase() },
        { ...original, to: MALLORY.address.toLowerCase() },
        { ...original, sentAt: '2026-08-09T13:00:00.000Z' },
        { ...original, id: 'b'.repeat(32) },
    ]) {
        await assert.rejects(
            openMessage(await keyFor(BOB, ALICE), forged, envelope),
            'metadata tampering must fail loudly',
        );
    }

    // As does editing the ciphertext itself.
    const flipped = Buffer.from(envelope.body, 'base64');
    flipped[0] ^= 0x01;

    await assert.rejects(
        openMessage(await keyFor(BOB, ALICE), original, {
            ...envelope,
            body: flipped.toString('base64'),
        }),
    );
});

test('ciphertext length is padded, and unicode survives the round trip', async () => {
    const key = await keyFor(ALICE, BOB);

    const short = await sealMessage(key, meta(ALICE, BOB), 'yes');
    const longer = await sealMessage(
        key,
        meta(ALICE, BOB),
        'no, and here is a much longer answer than that one',
    );

    // Two different short messages are indistinguishable by size: the relay
    // can measure what it cannot read, so it should learn as little as
    // possible from the measurement.
    assert.equal(short.body.length, longer.body.length);

    const text = 'приняли — 0.5 CYBER 🌐 лэйн';
    const envelope = await sealMessage(key, meta(ALICE, BOB), text);

    assert.equal(
        await openMessage(await keyFor(BOB, ALICE), meta(ALICE, BOB), envelope),
        text,
    );

    // A fresh nonce per message, or AES-GCM under a static key would break.
    const again = await sealMessage(key, meta(ALICE, BOB), text);
    assert.notEqual(envelope.iv, again.iv);
    assert.notEqual(envelope.body, again.body);

    await assert.rejects(
        sealMessage(key, meta(ALICE, BOB), 'x'.repeat(MAX_MESSAGE_BYTES + 1)),
    );
});

test('a wallet can message itself, and ids are unique', async () => {
    // Notes to self: ECDH between a key and its own public half is a valid
    // shared secret, so the thread with your own address just works.
    const key = await keyFor(ALICE, ALICE);
    const envelope = await sealMessage(key, meta(ALICE, ALICE), 'remember');

    assert.equal(
        await openMessage(key, meta(ALICE, ALICE), envelope),
        'remember',
    );

    const ids = new Set(Array.from({ length: 64 }, () => chatMessageId()));
    assert.equal(ids.size, 64);
    assert.match(chatMessageId(), /^[0-9a-f]{32}$/);
});

test('a fingerprint is stable and readable out loud', () => {
    const publicKey = chatPublicKey(chatPrivateKey(ALICE.privateKey));
    const fingerprint = chatFingerprint(publicKey);

    assert.equal(fingerprint, chatFingerprint(publicKey));
    assert.match(fingerprint, /^[0-9A-F]{4}( [0-9A-F]{4}){5}$/);
    assert.notEqual(
        fingerprint,
        chatFingerprint(chatPublicKey(chatPrivateKey(BOB.privateKey))),
    );
});
