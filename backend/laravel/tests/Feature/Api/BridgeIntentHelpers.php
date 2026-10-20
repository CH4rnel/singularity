<?php

use App\Support\BridgeIntent;
use App\Support\BridgeSubmission;
use Elliptic\EC;
use kornrunner\Keccak;

/*
| Signing helpers for /bridge/submit (B03). A submit is paid only when the
| sending wallet signed the recipient, so tests that are about something else
| sign their payloads the way the wallet does. Each original sender string maps
| to its own deterministic key, so "same sender" and "different sender" stay
| what a test meant by them.
*/

/** EVM private key (hex, no 0x) deterministically derived from a label. */
function bridgeTestEvmKey(string $label): string
{
    return Keccak::hash('cyberia-bridge-test:'.$label, 256);
}

function bridgeTestEvmAddress(string $privateKey): string
{
    $public = (new EC('secp256k1'))->keyFromPrivate($privateKey)->getPublic(false, 'hex');

    return '0x'.substr(Keccak::hash(hex2bin(substr($public, 2)), 256), 24);
}

/** EIP-191 personal_sign, 65-byte hex with v = 27/28. */
function bridgeTestEvmSign(string $message, string $privateKey): string
{
    $hash = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
    $signature = (new EC('secp256k1'))->keyFromPrivate($privateKey)->sign($hash, ['canonical' => true]);

    return '0x'.str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT)
        .str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT)
        .dechex(27 + $signature->recoveryParam);
}

/** @return array{address: string, secret: string} ed25519 keypair from a label */
function bridgeTestSolanaKey(string $label): array
{
    $pair = sodium_crypto_sign_seed_keypair(hash('sha256', 'cyberia-bridge-test:'.$label, true));

    return [
        'address' => bridgeTestBase58(sodium_crypto_sign_publickey($pair)),
        'secret' => sodium_crypto_sign_secretkey($pair),
    ];
}

function bridgeTestBase58(string $bytes): string
{
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $number = gmp_import($bytes);
    $out = '';

    while (gmp_cmp($number, 0) > 0) {
        [$number, $remainder] = gmp_div_qr($number, 58);
        $out = $alphabet[gmp_intval($remainder)].$out;
    }

    return str_repeat('1', strlen($bytes) - strlen(ltrim($bytes, "\x00"))).$out;
}

/** The address signedBridgeSubmit() substitutes for `$label` on this corridor. */
function bridgeTestSender(string $label, string $direction): string
{
    $sourceChain = (string) (config('bridge.routes', [])[$direction]['source_chain'] ?? '');

    return match ((string) config("bridge.chains.{$sourceChain}.type", '')) {
        'solana' => bridgeTestSolanaKey($label)['address'],
        'evm' => bridgeTestEvmAddress(bridgeTestEvmKey($label)),
        default => $label,
    };
}

/**
 * The payload as the wallet would send it: the sender replaced by a key this
 * test holds, and the recipient signed for against the source hash (or the
 * reference given).
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function signedBridgeSubmit(array $payload, ?string $reference = null): array
{
    $direction = (string) $payload['direction'];
    $token = (string) ($payload['token'] ?? 'CYBER.sol');
    $sourceChain = (string) (config('bridge.routes', [])[$direction]['source_chain'] ?? '');
    $type = (string) config("bridge.chains.{$sourceChain}.type", '');
    $label = (string) $payload['sender_address'];

    $reference ??= BridgeSubmission::sourceHash($sourceChain, (string) $payload['source_tx_hash']);

    if ($type === 'solana') {
        $key = bridgeTestSolanaKey($label);
        $payload['sender_address'] = $key['address'];
        $message = BridgeIntent::message($direction, $token, $key['address'], (string) $payload['recipient_address'], $reference);
        $payload['intent_signature'] = base64_encode(sodium_crypto_sign_detached($message, $key['secret']));

        return $payload;
    }

    if ($type === 'evm') {
        $key = bridgeTestEvmKey($label);
        $payload['sender_address'] = bridgeTestEvmAddress($key);
        $message = BridgeIntent::message($direction, $token, $payload['sender_address'], (string) $payload['recipient_address'], $reference);
        $payload['intent_signature'] = bridgeTestEvmSign($message, $key);
    }

    return $payload;
}
