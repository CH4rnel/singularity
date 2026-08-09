<?php

use App\Models\WalletChatKey;
use App\Models\WalletChatMessage;
use Elliptic\EC;
use Elliptic\EC\KeyPair;
use kornrunner\Keccak;

/**
 * The relay behind the wallet's encrypted chat.
 *
 * There is nothing here about whether the encryption works — that is pinned in
 * tests/Frontend/WalletChatTest.mjs, where the encryption lives. What is
 * checked here is everything the server is still able to get wrong: that it
 * only takes a key from the address it belongs to, only hands a mailbox to
 * whoever proved they hold it, and cannot be talked into attributing a message
 * to someone who did not send it.
 *
 * Keys are generated per test and never leave memory. The bodies are nonsense
 * base64 on purpose: the relay has no idea what a message is, and a test that
 * fed it real ciphertext would imply it could tell the difference.
 */
function chatWallet(): array
{
    $key = (new EC('secp256k1'))->genKeyPair();
    $public = hex2bin(substr($key->getPublic(false, 'hex'), 2));

    return [$key, '0x'.substr(Keccak::hash($public, 256), 24)];
}

/** Personal-sign a message exactly as the browser wallet would. */
function chatSign(KeyPair $key, string $message): string
{
    $digest = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
    $signature = $key->sign($digest, ['canonical' => true]);

    return '0x'
        .str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT)
        .str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT)
        .str_pad(dechex(27 + $signature->recoveryParam), 2, '0', STR_PAD_LEFT);
}

/** A messaging public key, in the compressed form the directory stores. */
function chatPublicKey(KeyPair $key): string
{
    return '0x'.$key->getPublic(true, 'hex');
}

/**
 * The statement a wallet signs to publish a key. Must match, byte for byte,
 * `chatKeyStatement` in resources/js/lib/wallet/chatCrypto.ts — this test is
 * the only place the two spellings are ever compared.
 */
function chatKeyStatement(string $address, string $publicKey, string $issuedAt): string
{
    return implode("\n", [
        'Cyberia wallet — publish my chat key.',
        'This signature publishes a public key that others use to encrypt messages to this address. It moves no funds and approves no transaction.',
        'Address: '.strtolower($address),
        'Chat key: '.strtolower($publicKey),
        'Issued: '.$issuedAt,
    ]);
}

/** Publish one address's messaging key. */
function chatPublish(KeyPair $key, string $address, ?string $issuedAt = null)
{
    $issuedAt ??= now()->toIso8601ZuluString('millisecond');
    $publicKey = chatPublicKey($key);

    return test()->postJson('/api/wallet/chat/keys', [
        'address' => $address,
        'publicKey' => $publicKey,
        'issuedAt' => $issuedAt,
        'signature' => chatSign($key, chatKeyStatement($address, $publicKey, $issuedAt)),
    ]);
}

/** Prove an address so the relay will hand over its mail. */
function chatProve(KeyPair $key, string $address)
{
    $challenge = test()->postJson('/api/wallet/chat/nonce', ['address' => $address])
        ->json('message');

    return test()->postJson('/api/wallet/chat/verify', [
        'address' => $address,
        'signature' => chatSign($key, $challenge),
    ]);
}

/** An envelope, opaque to everything on this side of the wire. */
function chatEnvelope(string $from, string $to, array $overrides = []): array
{
    return array_merge([
        'id' => bin2hex(random_bytes(16)),
        'from' => $from,
        'to' => $to,
        'sentAt' => now()->toIso8601ZuluString('millisecond'),
        'iv' => base64_encode(random_bytes(12)),
        'body' => base64_encode(random_bytes(272)),
    ], $overrides);
}

it('signs the same words the browser does', function () {
    // The one duplicated string in the whole feature. Every wallet in
    // existence verifies published keys against its own copy of these words,
    // so if the two spellings ever drift the directory silently stops
    // verifying — and both test suites would go on passing, each happy with
    // its own copy. This is the only place they are compared.
    $source = file_get_contents(base_path('resources/js/lib/wallet/chatCrypto.ts'));

    expect($source)->toBeString();
    preg_match('/export const chatKeyStatement =.*?\[(.*?)\]\.join/s', (string) $source, $body);
    preg_match_all('/^\s*[`\'](.+?)[`\'],$/m', $body[1] ?? '', $found);

    $browser = str_replace(
        ['${address.toLowerCase()}', '${publicKey.toLowerCase()}', '${issuedAt}'],
        ['0xabc', '0x02def', '2026-08-09T12:00:00.000Z'],
        implode("\n", $found[1]),
    );

    expect($found[1])->toHaveCount(5)
        ->and($browser)->toBe(chatKeyStatement('0xabc', '0x02def', '2026-08-09T12:00:00.000Z'));
});

it('publishes a key only for the address that signed for it', function () {
    [$key, $address] = chatWallet();

    chatPublish($key, $address)->assertCreated();

    expect(WalletChatKey::where('address', strtolower($address))->exists())->toBeTrue();

    // The signature is what makes this directory usable at all: without this
    // check anyone could claim any address and read its mail.
    [$mallory] = chatWallet();
    $issuedAt = now()->toIso8601ZuluString('millisecond');
    $publicKey = chatPublicKey($mallory);

    $this->postJson('/api/wallet/chat/keys', [
        'address' => $address,
        'publicKey' => $publicKey,
        'issuedAt' => $issuedAt,
        'signature' => chatSign($mallory, chatKeyStatement($address, $publicKey, $issuedAt)),
    ])->assertStatus(401);

    // And the stored record is untouched by the attempt.
    expect(WalletChatKey::where('address', strtolower($address))->value('public_key'))
        ->toBe(strtolower(chatPublicKey($key)));
});

it('serves a published key with its signature, and 404s an address that has none', function () {
    [$key, $address] = chatWallet();

    $this->getJson('/api/wallet/chat/keys/'.$address)->assertNotFound();

    chatPublish($key, $address);

    $this->getJson('/api/wallet/chat/keys/'.$address)
        ->assertOk()
        ->assertJsonPath('address', strtolower($address))
        ->assertJsonPath('publicKey', strtolower(chatPublicKey($key)))
        // Without the signature the browser could not tell this record from
        // one the server invented, which is the whole point of storing it.
        ->assertJsonStructure(['address', 'publicKey', 'issuedAt', 'signature']);
});

it('refuses to roll a key back to an older one', function () {
    [$key, $address] = chatWallet();

    $current = now()->subMinute()->toIso8601ZuluString('millisecond');
    $stale = now()->subDay()->toIso8601ZuluString('millisecond');

    chatPublish($key, $address, $current)->assertCreated();

    // Replaying an older signed record is answered with the one on file rather
    // than accepted: a rotated key must not be undoable by anyone who kept a
    // copy of what it replaced.
    chatPublish($key, $address, $stale)->assertOk()->assertJsonPath('issuedAt', $current);

    expect(WalletChatKey::where('address', strtolower($address))->value('issued_at'))
        ->toBe($current);
});

it('refuses a key dated in the future', function () {
    [$key, $address] = chatWallet();

    chatPublish($key, $address, now()->addDay()->toIso8601ZuluString('millisecond'))
        ->assertStatus(422);

    expect(WalletChatKey::count())->toBe(0);
});

it('will not hand over a mailbox without a signature', function () {
    [, $address] = chatWallet();

    $this->getJson('/api/wallet/chat/messages')->assertStatus(403);
    $this->postJson('/api/wallet/chat/messages', chatEnvelope($address, $address))
        ->assertStatus(403);
});

it('rejects a challenge signed by another key', function () {
    [, $address] = chatWallet();
    [$mallory] = chatWallet();

    $challenge = $this->postJson('/api/wallet/chat/nonce', ['address' => $address])
        ->json('message');

    $this->postJson('/api/wallet/chat/verify', [
        'address' => $address,
        'signature' => chatSign($mallory, $challenge),
    ])->assertStatus(401);

    // A challenge answers once: replaying the correct signature after it has
    // been spent gets nothing either.
    [$key, $second] = chatWallet();
    $challenge = $this->postJson('/api/wallet/chat/nonce', ['address' => $second])
        ->json('message');
    $signature = chatSign($key, $challenge);

    $this->postJson('/api/wallet/chat/verify', ['address' => $second, 'signature' => $signature])
        ->assertOk();
    $this->postJson('/api/wallet/chat/verify', ['address' => $second, 'signature' => $signature])
        ->assertStatus(422);
});

it('carries an envelope between two wallets without reading it', function () {
    [$aliceKey, $alice] = chatWallet();
    [$bobKey, $bob] = chatWallet();

    chatPublish($aliceKey, $alice);
    chatPublish($bobKey, $bob);
    chatProve($aliceKey, $alice)->assertOk();

    $envelope = chatEnvelope(strtolower($alice), strtolower($bob));

    $this->postJson('/api/wallet/chat/messages', $envelope)
        ->assertCreated()
        ->assertJsonPath('message.from', strtolower($alice))
        ->assertJsonPath('message.to', strtolower($bob))
        // Echoed back exactly as sent: both are inside the sender's AEAD tag,
        // and a reformatted timestamp would make the message unopenable.
        ->assertJsonPath('message.sentAt', $envelope['sentAt'])
        ->assertJsonPath('message.body', $envelope['body']);

    // Stored opaquely. The relay holds ciphertext and metadata, nothing else.
    $stored = WalletChatMessage::where('message_id', $envelope['id'])->firstOrFail();
    expect($stored->body)->toBe($envelope['body']);

    // Alice sees her own sent message — she can decrypt it, so a second device
    // recovers her half of the thread.
    $this->getJson('/api/wallet/chat/messages')
        ->assertOk()
        ->assertJsonPath('messages.0.id', $envelope['id']);

    // And so does Bob, once he proves the address it was addressed to.
    chatProve($bobKey, $bob)->assertOk();

    $this->getJson('/api/wallet/chat/messages')
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.body', $envelope['body']);
});

it('keeps one mailbox out of another', function () {
    [$aliceKey, $alice] = chatWallet();
    [$bobKey, $bob] = chatWallet();
    [$malloryKey, $mallory] = chatWallet();

    foreach ([[$aliceKey, $alice], [$bobKey, $bob], [$malloryKey, $mallory]] as [$key, $address]) {
        chatPublish($key, $address);
    }

    chatProve($aliceKey, $alice);
    $envelope = chatEnvelope(strtolower($alice), strtolower($bob));
    $this->postJson('/api/wallet/chat/messages', $envelope)->assertCreated();

    // Mallory proves her own address honestly and still sees nothing: the
    // mailbox is decided by the proof, not by anything in the request.
    chatProve($malloryKey, $mallory)->assertOk();

    $this->getJson('/api/wallet/chat/messages')
        ->assertOk()
        ->assertJsonCount(0, 'messages');
});

it('will not let a proven wallet send as somebody else', function () {
    [$aliceKey, $alice] = chatWallet();
    [$bobKey, $bob] = chatWallet();

    chatPublish($aliceKey, $alice);
    chatPublish($bobKey, $bob);
    chatProve($aliceKey, $alice);

    // Claiming Bob as the sender is refused outright rather than quietly
    // rewritten: the client computed its tag over the sender it named, so a
    // silent correction would produce a message nobody could open.
    $this->postJson(
        '/api/wallet/chat/messages',
        chatEnvelope(strtolower($bob), strtolower($bob))
    )->assertStatus(403);

    expect(WalletChatMessage::count())->toBe(0);
});

it('refuses an envelope addressed to a wallet that never opened chat', function () {
    [$aliceKey, $alice] = chatWallet();
    [, $stranger] = chatWallet();

    chatPublish($aliceKey, $alice);
    chatProve($aliceKey, $alice);

    $this->postJson(
        '/api/wallet/chat/messages',
        chatEnvelope(strtolower($alice), strtolower($stranger))
    )->assertStatus(422);
});

it('treats a resent envelope as the same message', function () {
    [$aliceKey, $alice] = chatWallet();
    [$bobKey, $bob] = chatWallet();

    chatPublish($aliceKey, $alice);
    chatPublish($bobKey, $bob);
    chatProve($aliceKey, $alice);

    $envelope = chatEnvelope(strtolower($alice), strtolower($bob));

    $this->postJson('/api/wallet/chat/messages', $envelope)->assertCreated();
    $this->postJson('/api/wallet/chat/messages', $envelope)->assertOk();

    expect(WalletChatMessage::count())->toBe(1);
});

it('caps what one envelope may weigh', function () {
    [$aliceKey, $alice] = chatWallet();
    [$bobKey, $bob] = chatWallet();

    chatPublish($aliceKey, $alice);
    chatPublish($bobKey, $bob);
    chatProve($aliceKey, $alice);

    $this->postJson('/api/wallet/chat/messages', chatEnvelope(
        strtolower($alice),
        strtolower($bob),
        ['body' => str_repeat('A', (int) config('wallet.chat.max_body_chars') + 4)],
    ))->assertStatus(422);

    // And a body that is not base64 at all is not an envelope.
    $this->postJson('/api/wallet/chat/messages', chatEnvelope(
        strtolower($alice),
        strtolower($bob),
        ['body' => 'not base64 !!'],
    ))->assertStatus(422);
});

it('pages the mailbox from a cursor', function () {
    [$aliceKey, $alice] = chatWallet();
    [$bobKey, $bob] = chatWallet();

    chatPublish($aliceKey, $alice);
    chatPublish($bobKey, $bob);
    chatProve($aliceKey, $alice);

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/wallet/chat/messages', chatEnvelope(
            strtolower($alice),
            strtolower($bob),
        ))->assertCreated();
    }

    $first = $this->getJson('/api/wallet/chat/messages')->assertOk();
    $cursor = $first->json('cursor');

    expect($first->json('messages'))->toHaveCount(3);

    // Polling again from the cursor returns nothing rather than the same three.
    $this->getJson('/api/wallet/chat/messages?since='.$cursor)
        ->assertOk()
        ->assertJsonCount(0, 'messages')
        ->assertJsonPath('cursor', $cursor);
});

it('prunes envelopes past the retention window', function () {
    [$aliceKey, $alice] = chatWallet();
    [$bobKey, $bob] = chatWallet();

    chatPublish($aliceKey, $alice);
    chatPublish($bobKey, $bob);
    chatProve($aliceKey, $alice);

    $this->postJson('/api/wallet/chat/messages', chatEnvelope(
        strtolower($alice),
        strtolower($bob),
    ))->assertCreated();

    config()->set('wallet.chat.retention_days', 30);

    $this->artisan('wallet:chat-prune')->assertSuccessful();
    expect(WalletChatMessage::count())->toBe(1);

    // The relay is a queue, not an archive: past the window the envelope goes,
    // and with it the record of who was talking to whom.
    WalletChatMessage::query()->update(['created_at' => now()->subDays(31)]);

    $this->artisan('wallet:chat-prune')->assertSuccessful();
    expect(WalletChatMessage::count())->toBe(0);

    // The directory is not touched: a key is public and is what makes an
    // address reachable at all.
    expect(WalletChatKey::count())->toBe(2);
});
