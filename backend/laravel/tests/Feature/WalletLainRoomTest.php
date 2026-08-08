<?php

use App\Http\Controllers\Api\WalletLainController;
use Elliptic\EC;
use Elliptic\EC\KeyPair;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use kornrunner\Keccak;

/**
 * The $LAIN holders' room inside the unified wallet.
 *
 * The signing key here is generated per test and never leaves memory: the gate
 * is about proving an address, so a fixture key would be a private key checked
 * into the repository for no reason at all.
 */
const ROOM_RPC_URL = 'https://rpc.cyberia.church';
const ROOM_OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';
const ROOM_SUPPLY = '1000000000000000000000';
const ROOM_TEN_PERCENT = '100000000000000000000';
const ROOM_ONE_PERCENT = '10000000000000000000';

function roomHex(string $decimal): string
{
    $hex = '';

    while (bccomp($decimal, '0') > 0) {
        $hex = dechex((int) bcmod($decimal, '16')).$hex;
        $decimal = bcdiv($decimal, '16', 0);
    }

    return '0x'.($hex === '' ? '0' : $hex);
}

/**
 * Fake the Cyberia reads the gate makes: balanceOf, then totalSupply.
 *
 * The balance is read out of the container at call time rather than closed
 * over, so a test can move it mid-conversation — which is the whole point of
 * a gate that re-checks instead of trusting the proof it issued.
 */
function roomRpc(string $balance): void
{
    app()->instance('room.balance', $balance);

    Http::fake([
        ROOM_RPC_URL => function (Request $request) {
            $data = (string) ($request->data()['params'][0]['data'] ?? '');

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => roomHex(
                    str_starts_with($data, '0x18160ddd')
                        ? ROOM_SUPPLY
                        : app('room.balance'),
                ),
            ]);
        },
        ROOM_OPENROUTER_URL => Http::response([
            'model' => 'openrouter/free',
            'choices' => [['message' => ['content' => 'present.']]],
        ]),
    ]);
}

/** A throwaway EVM key and the address it owns. The key stays in memory. */
function roomWallet(): array
{
    $key = (new EC('secp256k1'))->genKeyPair();
    $public = hex2bin(substr($key->getPublic(false, 'hex'), 2));

    return [$key, '0x'.substr(Keccak::hash($public, 256), 24)];
}

/** Personal-sign the challenge exactly as the browser wallet would. */
function roomSign(KeyPair $key, string $message): string
{
    $digest = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
    $signature = $key->sign($digest, ['canonical' => true]);

    return '0x'
        .str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT)
        .str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT)
        .str_pad(dechex(27 + $signature->recoveryParam), 2, '0', STR_PAD_LEFT);
}

/** Walk the whole flow: challenge, signature, proof. Returns the verify call. */
function roomEnter(KeyPair $key, string $address)
{
    $challenge = test()->postJson('/api/wallet/lain/nonce', ['address' => $address])
        ->json('message');

    return test()->postJson('/api/wallet/lain/verify', [
        'address' => $address,
        'signature' => roomSign($key, $challenge),
    ]);
}

beforeEach(function () {
    config()->set('services.lain.openrouter_api_key', 'test-key');
    config()->set('services.lain.model', 'openrouter/free');
    config()->set('services.lain.fallback_model', 'openrouter/free');
    config()->set('services.ethereum.rpc_url', ROOM_RPC_URL);
    config()->set('services.lain.token_address', '0x05cd1afd5b2df3cca6ceab80cbc21168ec981e8b');
    config()->set('services.lain.minimum_share_bps', 1000);
});

it('tells the wallet page which contract the room counts', function () {
    $this->get('/wallet')->assertInertia(fn (Assert $page) => $page
        ->component('Wallet')
        ->where('lain.minimumShareBps', 1000)
        ->where('lain.tokenAddress', '0x05cd1afd5b2df3cca6ceab80cbc21168ec981e8b')
        ->where('lain.enabled', true));
});

it('opens the room for a wallet holding its share of the supply', function () {
    roomRpc(ROOM_TEN_PERCENT);
    [$key, $address] = roomWallet();

    roomEnter($key, $address)
        ->assertOk()
        ->assertJsonPath('gate.qualifies', true)
        ->assertJsonPath('gate.shareBps', 1000);

    $this->postJson('/api/wallet/lain/chat', ['text' => 'are you there'])
        ->assertOk()
        ->assertJsonPath('text', 'present.');
});

it('turns away a wallet holding less than the required share', function () {
    roomRpc(ROOM_ONE_PERCENT);
    [$key, $address] = roomWallet();

    roomEnter($key, $address)
        ->assertForbidden()
        ->assertJsonPath('gate.qualifies', false)
        ->assertJsonPath('gate.shareBps', 100);

    $this->postJson('/api/wallet/lain/chat', ['text' => 'let me in'])
        ->assertForbidden();
});

it('refuses a signature that was not made by the address it claims', function () {
    roomRpc(ROOM_TEN_PERCENT);
    [$key] = roomWallet();
    [, $other] = roomWallet();

    // A real signature over a real challenge — for somebody else's address.
    $challenge = $this->postJson('/api/wallet/lain/nonce', ['address' => $other])
        ->json('message');

    $this->postJson('/api/wallet/lain/verify', [
        'address' => $other,
        'signature' => roomSign($key, $challenge),
    ])->assertUnauthorized();

    $this->postJson('/api/wallet/lain/chat', ['text' => 'hello'])->assertForbidden();
});

it('answers a challenge exactly once', function () {
    roomRpc(ROOM_TEN_PERCENT);
    [$key, $address] = roomWallet();

    $challenge = $this->postJson('/api/wallet/lain/nonce', ['address' => $address])
        ->json('message');
    $signature = roomSign($key, $challenge);

    $this->postJson('/api/wallet/lain/verify', compact('address', 'signature'))->assertOk();
    $this->postJson('/api/wallet/lain/verify', compact('address', 'signature'))
        ->assertStatus(422);
});

it('refuses to answer a wallet that never signed', function () {
    roomRpc(ROOM_TEN_PERCENT);

    $this->postJson('/api/wallet/lain/chat', ['text' => 'hello'])
        ->assertForbidden()
        ->assertJsonPath('gate.state', 'unproven');

    Http::assertNotSent(fn (Request $request) => $request->url() === ROOM_OPENROUTER_URL);
});

it('closes the room again when the balance falls below the threshold', function () {
    roomRpc(ROOM_TEN_PERCENT);
    [$key, $address] = roomWallet();

    roomEnter($key, $address)->assertOk();

    // The holder status is cached for half a minute; drop it so the next turn
    // asks the chain rather than the answer it got when the room opened.
    Cache::flush();
    roomRpc(ROOM_ONE_PERCENT);

    $this->postJson('/api/wallet/lain/chat', ['text' => 'still here?'])
        ->assertForbidden()
        ->assertJsonPath('gate.qualifies', false);

    // And the proof is gone with it: the room stays shut until it is re-earned.
    $this->postJson('/api/wallet/lain/chat', ['text' => 'hello?'])
        ->assertForbidden()
        ->assertJsonPath('gate.state', 'unproven');
});

it('replays the browser transcript as context and keeps none of it', function () {
    roomRpc(ROOM_TEN_PERCENT);
    [$key, $address] = roomWallet();

    roomEnter($key, $address)->assertOk();

    $this->postJson('/api/wallet/lain/chat', [
        'text' => 'and now?',
        'history' => [
            ['role' => 'user', 'text' => 'we spoke yesterday'],
            ['role' => 'lain', 'text' => 'we did'],
        ],
    ])->assertOk();

    Http::assertSent(function (Request $request) use ($address) {
        if ($request->url() !== ROOM_OPENROUTER_URL) {
            return false;
        }

        $messages = $request->data()['messages'];

        return $messages[0]['role'] === 'system'
            && str_contains($messages[0]['content'], substr($address, 0, 6))
            && str_contains($messages[0]['content'], 'non-custodial wallet')
            && $messages[1] === ['role' => 'user', 'content' => 'we spoke yesterday']
            // The holder's own "lain" turns have to reach the model as the
            // assistant, or every past answer reads as something they said.
            && $messages[2] === ['role' => 'assistant', 'content' => 'we did']
            && $messages[3] === ['role' => 'user', 'content' => 'and now?'];
    });

    expect(DB::table('lain_chat_messages')->count())->toBe(0);
});

it('refuses a transcript longer than it will replay', function () {
    roomRpc(ROOM_TEN_PERCENT);
    [$key, $address] = roomWallet();

    roomEnter($key, $address)->assertOk();

    $this->postJson('/api/wallet/lain/chat', [
        'text' => 'hello',
        'history' => array_fill(
            0,
            WalletLainController::CONTEXT_MESSAGES + 1,
            ['role' => 'user', 'text' => 'padding'],
        ),
    ])->assertStatus(422);
});
