<?php

use Elliptic\EC;
use Elliptic\EC\KeyPair;
use Illuminate\Support\Facades\DB;
use kornrunner\Keccak;

/**
 * "Notifications in Telegram" from the wallet: a signed address buys a
 * one-time deep link into the bot, and nothing else does.
 *
 * Keys are generated per test and stay in memory.
 */
function linkWallet(): array
{
    $key = (new EC('secp256k1'))->genKeyPair();
    $public = hex2bin(substr($key->getPublic(false, 'hex'), 2));

    return [$key, '0x'.substr(Keccak::hash($public, 256), 24)];
}

function linkSign(KeyPair $key, string $message): string
{
    $digest = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
    $signature = $key->sign($digest, ['canonical' => true]);

    return '0x'
        .str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT)
        .str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT)
        .str_pad(dechex(27 + $signature->recoveryParam), 2, '0', STR_PAD_LEFT);
}

it('answers a signed address with a one-time link into the bot', function () {
    config(['services.telegram_bot.username' => 'cyberia_network_bot']);
    [$key, $address] = linkWallet();

    $challenge = $this->postJson('/api/wallet/telegram/nonce', ['address' => $address])->json('message');
    $url = $this->postJson('/api/wallet/telegram/link', [
        'address' => $address,
        'signature' => linkSign($key, $challenge),
    ])->assertOk()->json('url');

    expect($url)->toStartWith('https://t.me/cyberia_network_bot?start=link_');

    $token = substr($url, strlen('https://t.me/cyberia_network_bot?start=link_'));
    $row = DB::table('tg_wallet_links')->where('token', $token)->first();

    expect($row->address)->toBe(strtolower($address))
        ->and($row->used_at)->toBeNull()
        ->and(strlen('link_'.$token))->toBeLessThanOrEqual(64);
});

it('refuses a signature made by another address', function () {
    [, $address] = linkWallet();
    [$stranger] = linkWallet();

    $challenge = $this->postJson('/api/wallet/telegram/nonce', ['address' => $address])->json('message');

    $this->postJson('/api/wallet/telegram/link', [
        'address' => $address,
        'signature' => linkSign($stranger, $challenge),
    ])->assertUnauthorized();

    expect(DB::table('tg_wallet_links')->count())->toBe(0);
});

it('answers a challenge once', function () {
    [$key, $address] = linkWallet();

    $challenge = $this->postJson('/api/wallet/telegram/nonce', ['address' => $address])->json('message');
    $signature = linkSign($key, $challenge);

    $this->postJson('/api/wallet/telegram/link', compact('address', 'signature'))->assertOk();
    $this->postJson('/api/wallet/telegram/link', compact('address', 'signature'))->assertStatus(422);
});
